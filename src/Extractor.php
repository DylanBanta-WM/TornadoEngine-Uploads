<?php

namespace TornadoEngine\Uploads;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use ZipArchive;

/**
 * The text in an uploaded file: plain text as it is; PDFs with pdftotext; Word, Excel, PowerPoint
 * and OpenDocument files from their XML; images by OCR (tesseract). Null if it has none we can read.
 */
class Extractor
{
    /** At most this much text from one file. */
    public const MAX_TEXT = 5 * 1024 * 1024;

    private const TEXT = ['txt', 'csv', 'tsv', 'json', 'md', 'xml', 'html', 'htm', 'log', 'yml', 'yaml', 'ini', 'sql', 'php', 'js', 'ts', 'py', 'css', 'ics', 'vcf', 'sh', 'ps1', 'bat', 'conf', 'env', 'blade.php'];

    public function text(string $path, string $name): ?string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $text = match (true) {
            in_array($extension, self::TEXT, true) => (string) file_get_contents($path, length: self::MAX_TEXT),
            $extension === 'pdf' => $this->run(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']),
            in_array($extension, ['docx', 'docm', 'dotx'], true) => $this->word($path),
            in_array($extension, ['xlsx', 'xlsm'], true) => $this->excel($path),
            in_array($extension, ['pptx', 'ppsx'], true) => $this->zipXml($path, '#^ppt/slides/slide\d+\.xml$#'),
            in_array($extension, ['odt', 'ods', 'odp'], true) => $this->zipXml($path, '#^content\.xml$#'),
            in_array($extension, Uploads::IMAGES, true) => $this->run(['tesseract', $path, 'stdout']),
            default => $this->looksLikeText($path) ? (string) file_get_contents($path, length: self::MAX_TEXT) : null,
        };
        if ($text === null) {
            return null;
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        return mb_strcut($text, 0, self::MAX_TEXT);
    }

    private function run(array $command): string
    {
        $result = Process::timeout(180)->run($command);
        if (! $result->successful()) {
            throw new RuntimeException(__('Couldn\'t read the text in this file.'));
        }

        return $result->output();
    }

    private function word(string $path): ?string
    {
        return $this->zipXml($path, '#^word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$#');
    }

    /** The text of the matching XML files in a zip (Office and OpenDocument files), in order. */
    private function zipXml(string $path, string $pattern): ?string
    {
        $zip = $this->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match($pattern, $name = (string) $zip->getNameIndex($i))) {
                $names[] = $name;
            }
        }
        natsort($names);
        $text = '';
        foreach ($names as $name) {
            $text .= $this->xmlText((string) $zip->getFromName($name, self::MAX_TEXT))."\n";
            if (strlen($text) > self::MAX_TEXT) {
                break;
            }
        }
        $zip->close();

        return $text;
    }

    private function xmlText(string $xml): string
    {
        $xml = preg_replace(['#<w:tab/>|<text:tab/>#', '#</(w:p|a:p|text:p|text:h|table:table-row)>#', '#</table:table-cell>#'], ["\t", "\n", "\t"], $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Excel: each sheet's rows, cells separated by tabs. */
    private function excel(string $path): string
    {
        $zip = $this->open($path);
        $strings = [];
        if (($shared = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            preg_match_all('#<si>(.*?)</si>#s', $shared, $items);
            foreach ($items[1] as $item) {
                $strings[] = html_entity_decode(strip_tags($item), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        $sheets = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name = (string) $zip->getNameIndex($i))) {
                $sheets[] = $name;
            }
        }
        natsort($sheets);
        $text = '';
        foreach ($sheets as $sheet) {
            $text .= '# '.basename($sheet, '.xml')."\n";
            preg_match_all('#<row[^>]*>(.*?)</row>#s', (string) $zip->getFromName($sheet), $rows);
            foreach ($rows[1] as $row) {
                preg_match_all('#<c([^>]*?)(?:/>|>(.*?)</c>)#s', $row, $cells, PREG_SET_ORDER);
                $text .= implode("\t", array_map(function ($cell) use ($strings) {
                    $inner = $cell[2] ?? '';
                    if (str_contains($cell[1], 't="s"') && preg_match('#<v>(\d+)</v>#', $inner, $v)) {
                        return $strings[(int) $v[1]] ?? '';
                    }

                    return html_entity_decode(strip_tags(preg_replace('#<f>.*?</f>#s', '', $inner)), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }, $cells))."\n";
                if (strlen($text) > self::MAX_TEXT) {
                    break 2;
                }
            }
        }
        $zip->close();

        return $text;
    }

    private function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException(__('Couldn\'t open this file.'));
        }

        return $zip;
    }

    private function looksLikeText(string $path): bool
    {
        $start = (string) file_get_contents($path, length: 8192);

        return $start !== '' && ! str_contains($start, "\0") && mb_check_encoding($start, 'UTF-8');
    }
}
