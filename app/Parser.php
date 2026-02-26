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

    private const int WORKERS = 8;

    private function worker(string $filepath, string $inputPath, int $start, int $end): void
    {
        $result = $this->process($inputPath, $start, $end);
        file_put_contents($filepath, igbinary_serialize($result));
    }

    private function process(string $inputPath, int $start, int $end): array
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
                    $result[$link] = $empty;
                }
                $date = substr($data, $nextComma + 1, self::DATE_LENGTH);
                $result[$link][$date] += 1;

                $o = $nextComma + self::COMMA_TO_NEWLINE_OFFSET;
            }

            $cur += $o;
        }

        if ($cur == $end + 1) {
            $end += 2;
            goto fixnewlineatend;
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
                $this->worker($tempFiles[$i], $inputPath, $start + 1, min($start + $chunk, $size));
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
            $results[] = $data ? igbinary_unserialize($data) : [];
            @unlink($f);
        }

        $res = self::merge($results);
        $this->writeResult($outputPath, $res);
    }
}
