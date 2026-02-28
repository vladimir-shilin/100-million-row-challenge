<?php

namespace App;

use App\Commands\Visit;

final class Parser
{
    private const int DATA_POINTS = 6 * 12 * 31;

    private const int BUFFER_SIZE = 16 * 1024;

    private const int OUTPUT_BUFFER = 256 * 1024;

    private const int URL_FIXED_LENGTH = 25;

    private const int SLUG_TO_COMMA_SEARCH_OFFSET = 5;

    private const int COMMA_TO_NEWLINE_OFFSET = 27;

    private function process(string $inputPath, array $substringToIndex, int $linksCount): array
    {
        $res = array_fill(0, count($substringToIndex), 0);
        $order = [];

        $handle = fopen($inputPath, 'r');

        $start = 0;
        $end = filesize($inputPath);

        while ($start < $end && count($order) < $linksCount) {
            fseek($handle, $start);
            $data = fread($handle, min(self::BUFFER_SIZE, $end - $start));
            $endData = strrpos($data, "\n");
            if ($endData === false) {
                break;
            }
            $endData--;
            $o = 0;
            while ($o < $endData) {
                $nextComma = strpos($data, ',', $o + self::SLUG_TO_COMMA_SEARCH_OFFSET);

                $link = substr($data, $o + self::URL_FIXED_LENGTH, $nextComma - $o - self::URL_FIXED_LENGTH);
                $order[$link] = 0;

                $substring = substr($data, $o + self::URL_FIXED_LENGTH, $nextComma + 11 - $o - self::URL_FIXED_LENGTH);
                $res[$substringToIndex[$substring]]++;

                $o = $nextComma + self::COMMA_TO_NEWLINE_OFFSET;
            }

            $start += $endData + 2;
        }

        while ($start < $end) {
            fseek($handle, $start);
            $data = fread($handle, min(self::BUFFER_SIZE, $end - $start));
            $endData = strrpos($data, "\n");
            if ($endData === false) {
                break;
            }
            $endData--;
            $o = 0;
            while ($o < $endData) {
                $nextComma = strpos($data, ',', $o + self::SLUG_TO_COMMA_SEARCH_OFFSET);

                $substring = substr($data, $o + self::URL_FIXED_LENGTH, $nextComma + 11 - $o - self::URL_FIXED_LENGTH);
                $res[$substringToIndex[$substring]]++;

                $o = $nextComma + self::COMMA_TO_NEWLINE_OFFSET;
            }

            $start += $endData + 2;
        }

        fclose($handle);

        return [$res, array_keys($order)];
    }

    private function writeResult(string $outputPath, array $res, array $order, array $links, array $dates): void
    {
        $handle = fopen($outputPath, 'w');
        ob_start();
        $link = array_shift($order);
        $linkId = $links[$link];

        echo "{\n";
        echo '    "\/blog\/' . $link . '": {' . "\n";
        $j = $linkId * self::DATA_POINTS;
        $jl = $j + self::DATA_POINTS;
        while ($j < $jl) {
            $cnt = $res[$j];
            if ($cnt == 0) {
                $j++;

                continue;
            }
            echo sprintf('        "%s": %d', $dates[$j % self::DATA_POINTS], $cnt);
            $j++;
            break;
        }
        while ($j < $jl) {
            $cnt = $res[$j];
            if ($cnt == 0) {
                $j++;

                continue;
            }
            echo sprintf(",\n        \"%s\": %d", $dates[$j % self::DATA_POINTS], $cnt);
            $j++;
        }
        echo "\n    }";

        foreach ($order as $link) {
            $linkId = $links[$link];
            echo ",\n" . '    "\/blog\/' . str_replace('/', '\/', $link) . '": {' . "\n";
            $j = $linkId * self::DATA_POINTS;
            $jl = $j + self::DATA_POINTS;
            while ($j < $jl) {
                $cnt = $res[$j];
                if ($cnt == 0) {
                    $j++;

                    continue;
                }
                echo sprintf('        "%s": %d', $dates[$j % self::DATA_POINTS], $cnt);
                $j++;
                break;
            }
            while ($j < $jl) {
                $cnt = $res[$j];
                if ($cnt == 0) {
                    $j++;

                    continue;
                }
                echo sprintf(",\n        \"%s\": %d", $dates[$j % self::DATA_POINTS], $cnt);
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
        $dates = array_fill(0, self::DATA_POINTS, null);
        $i = 0;
        for ($y = 2021; $y <= 2026; $y++) {
            for ($m = 1; $m <= 12; $m++) {
                for ($d = 1; $d <= 31; $d++) {
                    $dates[$i] = sprintf('%d-%02d-%02d', $y, $m, $d);
                    $i++;
                }
            }
        }
        $linkToIndex = [];
        $indexToLink = [];
        foreach (Visit::all() as $i => $v) {
            $link = substr($v->uri, self::URL_FIXED_LENGTH);
            $linkToIndex[$link] = $i;
            $indexToLink[] = $link;
        }

        $substringToIndex = [];
        $i = 0;
        foreach ($indexToLink as $link) {
            foreach ($dates as $date) {
                $substringToIndex[$link . ',' . $date] = $i;
                $i++;
            }
        }

        [$res, $order] = $this->process($inputPath, $substringToIndex, count($linkToIndex));

        $this->writeResult($outputPath, $res, $order, $linkToIndex, $dates);
    }
}
