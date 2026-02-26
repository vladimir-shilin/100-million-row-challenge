<?php

namespace App;

final class Parser
{
    private const int BUFFER_SIZE = 16 * 1024;

    private const int OUTPUT_BUFFER = 256 * 1024;

    private const int WORKERS = 4;

    private function packLinks(array $result): string
    {
        $binary = pack('N', count($result));
        foreach ($result as $link => $dates) {
            $linkLen = strlen($link);
            $binary .= pack('n', $linkLen) . $link;
            $binary .= pack('N', count($dates));
            foreach ($dates as $date => $cnt) {
                $dateLen = strlen($date);
                $binary .= pack('n', $dateLen) . $date . pack('N', $cnt);
            }
        }

        return $binary;
    }

    private function unpackLinks(string $binary): array
    {
        if ($binary === '') {
            return [];
        }
        $result = [];
        $offset = 0;
        $len = strlen($binary);
        $numLinks = unpack('N', substr($binary, $offset, 4))[1] ?? 0;
        $offset += 4;
        for ($i = 0; $i < $numLinks && $offset < $len; $i++) {
            $linkLen = unpack('n', substr($binary, $offset, 2))[1] ?? 0;
            $offset += 2;
            $link = substr($binary, $offset, $linkLen);
            $offset += $linkLen;
            $numDates = unpack('N', substr($binary, $offset, 4))[1] ?? 0;
            $offset += 4;
            $result[$link] = [];
            for ($j = 0; $j < $numDates && $offset < $len; $j++) {
                $dateLen = unpack('n', substr($binary, $offset, 2))[1] ?? 0;
                $offset += 2;
                $date = substr($binary, $offset, $dateLen);
                $offset += $dateLen;
                $cnt = unpack('N', substr($binary, $offset, 4))[1] ?? 0;
                $offset += 4;
                $result[$link][$date] = $cnt;
            }
        }

        return $result;
    }

    private function worker(string $filepath, string $inputPath, int $pre, int $start, int $end): void
    {
        $result = $this->process($inputPath, $pre, $start, $end);
        $data = $this->packLinks($result);
        file_put_contents($filepath, $data);
    }

    private function process(string $inputPath, int $pre, int $start, int $end): array
    {
        $empty = [];
        for ($y = 2021; $y <= 2026; $y++) {
            for ($m = 1; $m <= 12; $m++) {
                for ($d = 1; $d <= 31; $d++) {
                    $empty[sprintf('%04d-%02d-%02d', $y, $m, $d)] = 0;
                }
            }
        }
        $result = [];

        $handle = fopen($inputPath, 'r');
        fseek($handle, $pre);
        $cur = $pre;
        if ($pre != $start) {
            $data = fread($handle, $start - $pre);
            $cur = $start - ($start - $pre - strrpos($data, "\n")) + 1;
        }
        while ($cur < $end) {
            fseek($handle, $cur);
            $data = fread($handle, min(self::BUFFER_SIZE, $end - $cur));
            foreach (explode("\n", $data) as $line) {
                [$link, $date] = [...explode(',', $line), ''];
                if (!$date || strlen($date) < 25) {
                    $cur -= strlen($line);
                    break;
                }
                $link = substr($link, 25);
                if (!isset($result[$link])) {
                    $result[$link] = $empty;
                }
                $date = substr($date, 0, 10);
                $result[$link][$date] += 1;
            }
            if (strlen($data) < self::BUFFER_SIZE) {
                break;
            }
            $cur += strlen($data);
        }

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
        $dates = array_keys($res[$link]);

        echo "{\n";
        echo '    "\/blog\/' . str_replace('/', '\/', $link) . '": {' . "\n";
        $j = 0;
        $jl = count($dates);
        while ($j < $jl) {
            $date = $dates[$j];
            $cnt = $res[$link][$date];
            if ($cnt == 0) {
                $j++;

                continue;
            }
            echo sprintf('        "%s": %d', $date, $cnt);
            $j++;
            break;
        }
        while ($j < $jl) {
            $date = $dates[$j];
            $cnt = $res[$link][$date];
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
                $date = $dates[$j];
                $cnt = $res[$link][$date];
                if ($cnt == 0) {
                    $j++;

                    continue;
                }
                echo sprintf('        "%s": %d', $date, $cnt);
                $j++;
                break;
            }
            while ($j < $jl) {
                $date = $dates[$j];
                $cnt = $res[$link][$date];
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

        $tempFiles = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $tempFiles[] = sys_get_temp_dir() . '/parser_worker_' . $i . '.dat';
            if (file_exists($tempFiles[$i])) {
                @unlink($tempFiles[$i]);
            }
        }

        $pids = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            $pid = pcntl_fork();
            if ($pid == 0) {
                $this->worker($tempFiles[$i], $inputPath, max(0, $start - 100), $start + 1, min($start + $chunk, $size));
                exit(0);
            }

            $pids[] = $pid;
            $start += $chunk;
        }

        foreach ($pids as $pid) {
            pcntl_wait($pid);
        }

        $results = [];

        foreach ($tempFiles as $i => $f) {
            $data = @file_get_contents($f);
            $results[] = $data ? $this->unpackLinks($data) : [];
            @unlink($f);
        }

        $res = self::merge($results);
        $this->writeResult($outputPath, $res);
    }
}
