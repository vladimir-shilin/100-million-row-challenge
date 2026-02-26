<?php

namespace App;

final class Parser
{
    private const int BUFFER_SIZE = 16 * 1024;

    private const int OUTPUT_BUFFER = 256 * 1024;

    private const int ADDITIONAL_READ_BYTES = 200;

    private const int URL_FIXED_LENGTH = 25;

    private const int DATE_LENGTH = 10;

    private const int COMMA_TO_NEWLINE_OFFSET = 27;

    private const int WORKERS = 12;

    private function worker(\Socket $socket, string $inputPath, int $start, int $end): void
    {
        $result = $this->process($inputPath, $start, $end);
        $data = igbinary_serialize($result);
        socket_write($socket, $data, strlen($data));
        socket_close($socket);
    }

    private function process(string $inputPath, int $start, int $end): array
    {
        $empty = \SplFixedArray::fromArray(array_fill(0, 6 << 9, 0));
        $result = [];

        $handle = fopen($inputPath, 'r');
        stream_set_read_buffer($handle, 0);

        fseek($handle, $start);
        $cur = $start;
        if ($start != 0) {
            $data = fread($handle, self::ADDITIONAL_READ_BYTES);
            $cur += strpos($data, "\n") + 1;
        }
        fixnewlineatend:
        while ($cur < $end) {
            fseek($handle, $cur);
            $data = fread($handle, min(self::BUFFER_SIZE, $end - $cur + self::ADDITIONAL_READ_BYTES));
            $o = 0;
            $bufferHardEnd = strlen($data);
            $bufferSoftEnd = min($bufferHardEnd, $end - $cur);
            while ($o < $bufferSoftEnd) {
                $nextComma = strpos($data, ',', $o);
                if ($nextComma === false || $nextComma + self::DATE_LENGTH >= $bufferHardEnd) {
                    break;
                }
                $link = substr($data, $o + self::URL_FIXED_LENGTH, $nextComma - $o - self::URL_FIXED_LENGTH);
                if (!isset($result[$link])) {
                    $result[$link] = clone $empty;
                }
                $date = (ord($data[$nextComma + 4]) << 9)
                    + ((10 * ord($data[$nextComma + 6]) + ord($data[$nextComma + 7])) << 5)
                    + 10 * ord($data[$nextComma + 9]) + ord($data[$nextComma + 10]) - 42512;
                $result[$link][$date] += 1;

                $o = $nextComma + self::COMMA_TO_NEWLINE_OFFSET;
            }

            $cur += $o;
        }

        if ($cur == $end + 1) {
            $end += 2;
            goto fixnewlineatend;
        }

        fclose($handle);

        return $result;
    }

    private function merge(array $results): array
    {
        for ($i = 1; $i < count($results); $i++) {
            foreach ($results[$i] as $link => $dates) {
                if (isset($results[0][$link])) {
                    foreach ($dates as $date => $cnt) {
                        $results[0][$link][$date] += $cnt;
                    }
                } else {
                    $results[0][$link] = $dates;
                }
            }
        }

        return $results[0];
    }

    private function writeResult(string $outputPath, array $res): void
    {
        $handle = fopen($outputPath, 'w');
        ob_start();
        $links = array_keys($res);
        $link = $links[0];

        echo "{\n";
        echo '    "\/blog\/' . str_replace('/', '\/', $link) . '": {' . "\n";
        $j = 0;
        $jl = 6 << 9;
        while ($j < $jl) {
            $cnt = $res[$link][$j];
            $date = sprintf('%d-%02d-%02d', 2021 + ($j >> 9), ($j >> 5) % 16, $j % 32);
            if ($cnt == 0) {
                $j++;

                continue;
            }
            echo sprintf('        "%s": %d', $date, $cnt);
            $j++;
            break;
        }
        while ($j < $jl) {
            $cnt = $res[$link][$j];
            $date = sprintf('%d-%02d-%02d', 2021 + ($j >> 9), ($j >> 5) % 16, $j % 32);
            if ($cnt == 0) {
                $j++;

                continue;
            }
            echo sprintf(",\n        \"%s\": %d", $date, $cnt);
            $j++;
        }
        echo "\n    }";

        for ($i = 1, $ll = count($links); $i < $ll; $i++) {
            $link = $links[$i];
            echo ",\n" . '    "\/blog\/' . str_replace('/', '\/', $link) . '": {' . "\n";
            $j = 0;
            while ($j < $jl) {
                $cnt = $res[$link][$j];
                $date = sprintf('%d-%02d-%02d', 2021 + ($j >> 9), ($j >> 5) % 16, $j % 32);
                if ($cnt == 0) {
                    $j++;

                    continue;
                }
                echo sprintf('        "%s": %d', $date, $cnt);
                $j++;
                break;
            }
            while ($j < $jl) {
                $cnt = $res[$link][$j];
                $date = sprintf('%d-%02d-%02d', 2021 + ($j >> 9), ($j >> 5) % 16, $j % 32);
                if ($cnt == 0) {
                    $j++;

                    continue;
                }
                echo sprintf(",\n        \"%s\": %d", $date, $cnt);
                $j++;
            }

            echo "\n    }";
            if (ob_get_length() > self::OUTPUT_BUFFER) {
                fwrite($handle, ob_get_clean());
                ob_start();
            }
        }
        echo "\n}";
        fwrite($handle, ob_get_clean());
        fclose($handle);
    }

    public function parse(string $inputPath, string $outputPath): void
    {
        $size = filesize($inputPath);
        $chunk = intdiv($size, self::WORKERS) + 1;
        $start = -1;

        $sockets = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            $socketPair = [];
            socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $socketPair);

            $pid = pcntl_fork();
            if ($pid == 0) {
                socket_close($socketPair[1]);
                $this->worker($socketPair[0], $inputPath, $start + 1, min($start + $chunk, $size));
                exit(0);
            }
            socket_close($socketPair[0]);
            $sockets[] = $socketPair[1];

            $start += $chunk;
        }

        $results = [];

        foreach ($sockets as $socket) {
            $read = [$socket];
            $write = null;
            $expect = null;
            socket_select($read, $write, $expect, null);
            $data = '';
            while (true) {
                $chunk = socket_read($socket, 10 * 1024 * 1024, PHP_BINARY_READ);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                $data .= $chunk;
            }
            socket_close($socket);
            $results[] = $data ? igbinary_unserialize($data) : [];
        }

        $res = $this->merge($results);
        $this->writeResult($outputPath, $res);
    }
}
