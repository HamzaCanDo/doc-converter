<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use League\HTMLToMarkdown\HtmlConverter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\Settings as WordSettings;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LocalFileConverter
{
    private const UPLOAD_TTL_SECONDS = 900;

    public function __construct(private SupabaseStorageService $storage)
    {
    }

    public function prepareUpload(UploadedFile $file): array
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $supported = ['docx', 'pdf', 'xlsx'];

        if (!in_array($ext, $supported, true)) {
            throw new RuntimeException('Unsupported file type. Please upload DOCX, PDF, or XLSX.');
        }

        $token = (string) Str::uuid();
        $path = 'uploads/' . $token . '.' . $ext;
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw new RuntimeException('Unable to read the uploaded file.');
        }

        $contentType = $file->getClientMimeType() ?: 'application/octet-stream';
        $this->storage->upload($path, $contents, $contentType);

        $meta = [
            'token' => $token,
            'path' => $path,
            'type' => $ext,
            'original_name' => $file->getClientOriginalName(),
            'expires_at' => time() + self::UPLOAD_TTL_SECONDS,
        ];

        Cache::put($this->metaKey($token), $meta, self::UPLOAD_TTL_SECONDS);

        if ($ext === 'docx') {
            $parsed = $this->parseDocx($file->getRealPath());
            Cache::put($this->docxKey($token), $parsed, self::UPLOAD_TTL_SECONDS);
        }

        if ($ext === 'pdf') {
            $parsed = $this->parsePdfText($file->getRealPath());
            Cache::put($this->pdfKey($token), $parsed, self::UPLOAD_TTL_SECONDS);
        }

        if ($ext === 'xlsx') {
            $parsed = $this->parseXlsx($file->getRealPath());
            Cache::put($this->xlsxKey($token), $parsed, self::UPLOAD_TTL_SECONDS);
        }

        return [
            'token' => $token,
            'formats' => $this->formatsForExtension($ext),
        ];
    }

    public function streamUploadFormat(string $token, string $format): StreamedResponse
    {
        $meta = $this->getMeta($token);
        $allowed = $this->formatsForExtension($meta['type']);

        if (!in_array($format, $allowed, true)) {
            throw new RuntimeException('Unsupported export format for this file type.');
        }

        return match ($meta['type']) {
            'docx' => $this->streamDocxFormat($meta, $format),
            'pdf' => $this->streamPdfFormat($meta, $format),
            'xlsx' => $this->streamXlsxFormat($meta, $format),
            default => throw new RuntimeException('Unsupported file type.'),
        };
    }

    private function streamDocxFormat(array $meta, string $format): StreamedResponse
    {
        return match ($format) {
            'pdf' => $this->streamDocxPdf($meta),
            'xlsx' => $this->streamDocxXlsx($meta),
            'json' => $this->streamDocxJson($meta),
            'md' => $this->streamDocxMarkdown($meta),
            default => throw new RuntimeException('Unsupported export format.'),
        };
    }

    private function streamPdfFormat(array $meta, string $format): StreamedResponse
    {
        return match ($format) {
            'xlsx' => $this->streamPdfXlsx($meta),
            'json' => $this->streamPdfJson($meta),
            'md' => $this->streamPdfMarkdown($meta),
            default => throw new RuntimeException('Unsupported export format.'),
        };
    }

    private function streamXlsxFormat(array $meta, string $format): StreamedResponse
    {
        return match ($format) {
            'json' => $this->streamXlsxJson($meta),
            'md' => $this->streamXlsxMarkdown($meta),
            default => throw new RuntimeException('Unsupported export format.'),
        };
    }

    private function streamDocxPdf(array $meta): StreamedResponse
    {
        return response()->streamDownload(function () use ($meta) {
            $path = $this->downloadToTemp($meta['path']);

            try {
                WordSettings::setPdfRenderer(
                    WordSettings::PDF_RENDERER_DOMPDF,
                    base_path('vendor/dompdf/dompdf')
                );

                $document = WordIOFactory::load($path);
                $writer = WordIOFactory::createWriter($document, 'PDF');
                $writer->save('php://output');
            } finally {
                @unlink($path);
            }
        }, 'document.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function streamDocxXlsx(array $meta): StreamedResponse
    {
        $parsed = Cache::get($this->docxKey($meta['token']));

        if (!$parsed) {
            $path = $this->downloadToTemp($meta['path']);
            $parsed = $this->parseDocx($path);
            Cache::put($this->docxKey($meta['token']), $parsed, self::UPLOAD_TTL_SECONDS);
            @unlink($path);
        }

        return $this->streamTablesToXlsx($parsed['tables'], $parsed['lines']);
    }

    private function streamDocxJson(array $meta): StreamedResponse
    {
        $parsed = Cache::get($this->docxKey($meta['token']));

        if (!$parsed) {
            $path = $this->downloadToTemp($meta['path']);
            $parsed = $this->parseDocx($path);
            Cache::put($this->docxKey($meta['token']), $parsed, self::UPLOAD_TTL_SECONDS);
            @unlink($path);
        }

        return $this->streamJson($parsed['plainText'], $parsed['lines'], $parsed['tables']);
    }

    private function streamDocxMarkdown(array $meta): StreamedResponse
    {
        $parsed = Cache::get($this->docxKey($meta['token']));

        if (!$parsed) {
            $path = $this->downloadToTemp($meta['path']);
            $parsed = $this->parseDocx($path);
            Cache::put($this->docxKey($meta['token']), $parsed, self::UPLOAD_TTL_SECONDS);
            @unlink($path);
        }

        return $this->streamMarkdown($parsed['htmlWithoutTables']);
    }

    private function streamPdfXlsx(array $meta): StreamedResponse
    {
        $parsed = $this->getPdfParsed($meta);

        return $this->streamLinesToXlsx($parsed['lines']);
    }

    private function streamPdfJson(array $meta): StreamedResponse
    {
        $parsed = $this->getPdfParsed($meta);

        return $this->streamJson($parsed['text'], $parsed['lines'], []);
    }

    private function streamPdfMarkdown(array $meta): StreamedResponse
    {
        $parsed = $this->getPdfParsed($meta);
        $markdown = implode("\n", $parsed['lines']);

        return response()->streamDownload(function () use ($markdown) {
            echo $markdown;
        }, 'document.md', [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    private function streamXlsxJson(array $meta): StreamedResponse
    {
        $parsed = $this->getXlsxParsed($meta);

        return response()->streamDownload(function () use ($parsed) {
            echo json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        }, 'document.json', [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    private function streamXlsxMarkdown(array $meta): StreamedResponse
    {
        $parsed = $this->getXlsxParsed($meta);
        $markdown = $this->xlsxToMarkdown($parsed);

        return response()->streamDownload(function () use ($markdown) {
            echo $markdown;
        }, 'document.md', [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    private function streamTablesToXlsx(array $tables, array $lines): StreamedResponse
    {
        return response()->streamDownload(function () use ($tables, $lines) {
            $spreadsheet = new Spreadsheet();

            if (!empty($tables)) {
                $sheetIndex = 0;

                foreach ($tables as $table) {
                    $sheet = $sheetIndex === 0
                        ? $spreadsheet->getActiveSheet()
                        : $spreadsheet->createSheet();

                    $sheet->setTitle('Table ' . ($sheetIndex + 1));

                    foreach ($table as $rowIndex => $row) {
                        foreach ($row as $colIndex => $value) {
                            $cell = Coordinate::stringFromColumnIndex($colIndex + 1) . ($rowIndex + 1);
                            $sheet->setCellValue($cell, $value);
                        }
                    }

                    $sheetIndex++;
                }
            } else {
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('Content');

                foreach ($lines as $rowIndex => $line) {
                    $cell = Coordinate::stringFromColumnIndex(1) . ($rowIndex + 1);
                    $sheet->setCellValue($cell, $line);
                }
            }

            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }, 'document.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function streamLinesToXlsx(array $lines): StreamedResponse
    {
        return $this->streamTablesToXlsx([], $lines);
    }

    private function streamMarkdown(string $html): StreamedResponse
    {
        $converter = new HtmlConverter([
            'strip_tags' => true,
            'header_style' => 'atx',
            'strip_placeholder_links' => true,
        ]);

        $markdown = trim($converter->convert($html));

        return response()->streamDownload(function () use ($markdown) {
            echo $markdown;
        }, 'document.md', [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    private function streamJson(string $text, array $lines, array $tables): StreamedResponse
    {
        return response()->streamDownload(function () use ($text, $lines, $tables) {
            echo json_encode([
                'text' => $text,
                'lines' => $lines,
                'tables' => $tables,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        }, 'document.json', [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    private function getPdfParsed(array $meta): array
    {
        $cached = Cache::get($this->pdfKey($meta['token']));

        if ($cached) {
            return $cached;
        }

        $path = $this->downloadToTemp($meta['path']);
        $parsed = $this->parsePdfText($path);
        Cache::put($this->pdfKey($meta['token']), $parsed, self::UPLOAD_TTL_SECONDS);
        @unlink($path);

        return $parsed;
    }

    private function getXlsxParsed(array $meta): array
    {
        $cached = Cache::get($this->xlsxKey($meta['token']));

        if ($cached) {
            return $cached;
        }

        $path = $this->downloadToTemp($meta['path']);
        $parsed = $this->parseXlsx($path);
        Cache::put($this->xlsxKey($meta['token']), $parsed, self::UPLOAD_TTL_SECONDS);
        @unlink($path);

        return $parsed;
    }

    private function parseDocx(string $path): array
    {
        $document = WordIOFactory::load($path);
        $writer = WordIOFactory::createWriter($document, 'HTML');
        $tmp = tempnam(sys_get_temp_dir(), 'docx-html-');

        if ($tmp === false) {
            throw new RuntimeException('Unable to create a temp file.');
        }

        $writer->save($tmp);
        $html = file_get_contents($tmp) ?: '';
        @unlink($tmp);

        return $this->parseHtml($html);
    }

    private function parsePdfText(string $path): array
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($path);
        $text = $this->normalizeText($pdf->getText());
        $lines = $this->textToLines($text);

        return [
            'text' => $text,
            'lines' => $lines,
        ];
    }

    private function parseXlsx(string $path): array
    {
        $spreadsheet = SpreadsheetIOFactory::load($path);
        $sheets = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $rows = [];
            $highestRow = $sheet->getHighestRow();
            $highestColumn = $sheet->getHighestColumn();
            $highestColIndex = Coordinate::columnIndexFromString($highestColumn);

            for ($row = 1; $row <= $highestRow; $row++) {
                $rowValues = [];
                $empty = true;

                for ($col = 1; $col <= $highestColIndex; $col++) {
                    $cell = Coordinate::stringFromColumnIndex($col) . $row;
                    $value = $sheet->getCell($cell)->getFormattedValue();
                    $value = is_string($value) ? trim($value) : $value;
                    $rowValues[] = $value;

                    if ($value !== null && $value !== '') {
                        $empty = false;
                    }
                }

                if (!$empty) {
                    $rows[] = $rowValues;
                }
            }

            $sheets[] = [
                'name' => $sheet->getTitle(),
                'rows' => $rows,
            ];
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [
            'sheets' => $sheets,
        ];
    }

    private function parseHtml(string $html): array
    {
        $dom = $this->loadHtml($html);
        $xpath = new DOMXPath($dom);
        $tables = $this->extractTables($xpath);

        $domNoTables = $this->loadHtml($html);
        $this->removeTables($domNoTables);
        $htmlWithoutTables = $domNoTables->saveHTML() ?: $html;

        $plainText = $this->normalizeText(strip_tags($htmlWithoutTables));
        $lines = $this->textToLines($plainText);

        return [
            'tables' => $tables,
            'plainText' => $plainText,
            'lines' => $lines,
            'htmlWithoutTables' => $htmlWithoutTables,
        ];
    }

    private function loadHtml(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return $dom;
    }

    private function extractTables(DOMXPath $xpath): array
    {
        $tables = [];

        foreach ($xpath->query('//table') as $table) {
            $rows = [];

            foreach ($xpath->query('.//tr', $table) as $tr) {
                $cells = [];

                foreach ($xpath->query('./th|./td', $tr) as $cell) {
                    $cells[] = $this->normalizeText($cell->textContent);
                }

                if (!empty($cells)) {
                    $rows[] = $cells;
                }
            }

            if (!empty($rows)) {
                $tables[] = $rows;
            }
        }

        return $tables;
    }

    private function removeTables(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $tables = $xpath->query('//table');

        foreach ($tables as $table) {
            if ($table->parentNode) {
                $table->parentNode->removeChild($table);
            }
        }
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\R+/', "\n", $text) ?? $text;

        return trim($text);
    }

    private function textToLines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\R/', $text) ?: [];
        $lines = array_map('trim', $lines);
        $lines = array_values(array_filter($lines, fn ($line) => $line !== ''));

        return $lines;
    }

    private function downloadToTemp(string $path): string
    {
        $contents = $this->storage->download($path);
        $tmp = tempnam(sys_get_temp_dir(), 'upload-');

        if ($tmp === false) {
            throw new RuntimeException('Unable to create a temp file.');
        }

        file_put_contents($tmp, $contents);

        return $tmp;
    }

    private function getMeta(string $token): array
    {
        $meta = Cache::get($this->metaKey($token));

        if (!$meta || !isset($meta['expires_at']) || $meta['expires_at'] < time()) {
            if ($meta && isset($meta['path'])) {
                $this->storage->delete($meta['path']);
            }

            Cache::forget($this->metaKey($token));
            Cache::forget($this->docxKey($token));
            Cache::forget($this->pdfKey($token));
            Cache::forget($this->xlsxKey($token));

            throw new RuntimeException('Upload expired. Please upload again.');
        }

        return $meta;
    }

    private function formatsForExtension(string $ext): array
    {
        return match ($ext) {
            'docx' => ['pdf', 'xlsx', 'json', 'md'],
            'pdf' => ['xlsx', 'json', 'md'],
            'xlsx' => ['json', 'md'],
            default => [],
        };
    }

    private function xlsxToMarkdown(array $parsed): string
    {
        $blocks = [];

        foreach ($parsed['sheets'] as $sheet) {
            $rows = $sheet['rows'] ?? [];

            if (empty($rows)) {
                continue;
            }

            $blocks[] = '# Sheet: ' . $sheet['name'];

            $header = array_map([$this, 'escapeMarkdownCell'], $rows[0]);
            $blocks[] = '| ' . implode(' | ', $header) . ' |';
            $blocks[] = '| ' . implode(' | ', array_fill(0, count($header), '---')) . ' |';

            foreach (array_slice($rows, 1) as $row) {
                $cells = array_map([$this, 'escapeMarkdownCell'], $row);
                $blocks[] = '| ' . implode(' | ', $cells) . ' |';
            }

            $blocks[] = '';
        }

        return trim(implode("\n", $blocks));
    }

    private function escapeMarkdownCell(mixed $value): string
    {
        $text = (string) $value;
        $text = str_replace('|', '\\|', $text);

        return $text;
    }

    private function metaKey(string $token): string
    {
        return 'upload:meta:' . $token;
    }

    private function docxKey(string $token): string
    {
        return 'upload:docx:' . $token;
    }

    private function pdfKey(string $token): string
    {
        return 'upload:pdf:' . $token;
    }

    private function xlsxKey(string $token): string
    {
        return 'upload:xlsx:' . $token;
    }
}
