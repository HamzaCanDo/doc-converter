<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Google_Client;
use Google_Service_Drive;
use Illuminate\Support\Facades\Cache;
use League\HTMLToMarkdown\HtmlConverter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GoogleDocConverter
{
    private const HTML_CACHE_TTL_SECONDS = 300;

    private Google_Service_Drive $drive;

    public function __construct()
    {
        $client = new Google_Client();
        $client->setAuthConfig(storage_path('app/google-auth.json'));
        $client->setScopes([Google_Service_Drive::DRIVE_READONLY]);

        $this->drive = new Google_Service_Drive($client);
    }

    public function extractFileIdFromUrl(string $url): string
    {
        $patterns = [
            '~/(?:document|spreadsheets|presentation)/d/([a-zA-Z0-9_-]{10,})~',
            '~[?&]id=([a-zA-Z0-9_-]{10,})~',
            '~/(?:d|file)/([a-zA-Z0-9_-]{10,})~',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        throw new RuntimeException('Unable to extract a valid file ID from the URL.');
    }

    public function assertAccessible(string $fileId): void
    {
        $this->getHtmlExport($fileId);
    }

    public function warmCache(string $fileId): void
    {
        $this->getParsedExport($fileId);
    }

    public function streamFormat(string $fileId, string $format): StreamedResponse
    {
        return match ($format) {
            'pdf' => $this->streamDriveExport($fileId, 'application/pdf', 'document.pdf'),
            'docx' => $this->streamDriveExport(
                $fileId,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'document.docx'
            ),
            'odt' => $this->streamDriveExport(
                $fileId,
                'application/vnd.oasis.opendocument.text',
                'document.odt'
            ),
            'html' => $this->streamHtml($fileId),
            'md' => $this->streamMarkdown($fileId),
            'json' => $this->streamJson($fileId),
            'xlsx' => $this->streamXlsx($fileId),
            default => throw new RuntimeException('Unsupported export format.'),
        };
    }

    private function streamHtml(string $fileId): StreamedResponse
    {
        return response()->streamDownload(function () use ($fileId) {
            echo $this->getHtmlExport($fileId);
        }, 'document.html', [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function streamDriveExport(string $fileId, string $mime, string $filename): StreamedResponse
    {
        $response = $this->drive->files->export($fileId, $mime, ['alt' => 'media']);

        return response()->streamDownload(function () use ($response) {
            if (is_string($response)) {
                echo $response;
                return;
            }

            if (method_exists($response, 'getBody')) {
                $stream = $response->getBody();

                while (!$stream->eof()) {
                    echo $stream->read(1048576);
                }

                return;
            }

            echo (string) $response;
        }, $filename, [
            'Content-Type' => $mime,
        ]);
    }

    private function streamMarkdown(string $fileId): StreamedResponse
    {
        return response()->streamDownload(function () use ($fileId) {
            $parsed = $this->getParsedExport($fileId);

            $converter = new HtmlConverter([
                'strip_tags' => true,
                'header_style' => 'atx',
                'strip_placeholder_links' => true,
            ]);

            $markdown = trim($converter->convert($parsed['htmlWithoutTables']));
            echo $markdown;
        }, 'document.md', [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    private function streamJson(string $fileId): StreamedResponse
    {
        return response()->streamDownload(function () use ($fileId) {
            $parsed = $this->getParsedExport($fileId);

            $json = json_encode([
                'file_id' => $fileId,
                'text' => $parsed['plainText'],
                'lines' => $parsed['lines'],
                'tables' => $parsed['tables'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            echo $json ?: '{}';
        }, 'document.json', [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    private function streamXlsx(string $fileId): StreamedResponse
    {
        return response()->streamDownload(function () use ($fileId) {
            $parsed = $this->getParsedExport($fileId);

            $spreadsheet = new Spreadsheet();

            if (!empty($parsed['tables'])) {
                $sheetIndex = 0;

                foreach ($parsed['tables'] as $table) {
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

                foreach ($parsed['lines'] as $rowIndex => $line) {
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

    private function exportToString(string $fileId, string $mime): string
    {
        $response = $this->drive->files->export($fileId, $mime, ['alt' => 'media']);

        if (is_string($response)) {
            return $response;
        }

        if (method_exists($response, 'getBody')) {
            return (string) $response->getBody();
        }

        return (string) $response;
    }

    private function getHtmlExport(string $fileId): string
    {
        $cacheKey = 'doc-converter:html:' . $fileId;

        return Cache::remember($cacheKey, self::HTML_CACHE_TTL_SECONDS, function () use ($fileId) {
            return $this->exportToString($fileId, 'text/html');
        });
    }

    private function getParsedExport(string $fileId): array
    {
        $cacheKey = 'doc-converter:parsed:' . $fileId;

        return Cache::remember($cacheKey, self::HTML_CACHE_TTL_SECONDS, function () use ($fileId) {
            $html = $this->getHtmlExport($fileId);

            return $this->parseHtml($html);
        });
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
}
