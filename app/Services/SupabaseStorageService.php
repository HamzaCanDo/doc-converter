<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SupabaseStorageService
{
    private string $baseUrl;
    private string $serviceKey;
    private string $bucket;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) env('SUPABASE_URL'), '/');
        $this->serviceKey = (string) env('SUPABASE_SERVICE_ROLE_KEY');
        $this->bucket = (string) env('SUPABASE_STORAGE_BUCKET', 'uploads');

    }

    public function upload(string $path, string $contents, string $contentType): void
    {
        $this->ensureConfigured();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->serviceKey,
            'apikey' => $this->serviceKey,
            'x-upsert' => 'true',
            'Content-Type' => $contentType,
        ])->withBody($contents, $contentType)
            ->post($this->objectUrl($path));

        if (!$response->successful()) {
            throw new RuntimeException('Supabase upload failed.');
        }
    }

    public function download(string $path): string
    {
        $this->ensureConfigured();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->serviceKey,
            'apikey' => $this->serviceKey,
        ])->get($this->objectUrl($path));

        if (!$response->successful()) {
            throw new RuntimeException('Supabase download failed.');
        }

        return $response->body();
    }

    public function delete(string $path): void
    {
        $this->ensureConfigured();

        Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->serviceKey,
            'apikey' => $this->serviceKey,
        ])->delete($this->objectUrl($path));
    }

    public function list(string $prefix = 'uploads/', int $limit = 100, int $offset = 0): array
    {
        $this->ensureConfigured();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->serviceKey,
            'apikey' => $this->serviceKey,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/storage/v1/object/list/' . $this->bucket, [
            'prefix' => ltrim($prefix, '/'),
            'limit' => max(1, $limit),
            'offset' => max(0, $offset),
            'sortBy' => [
                'column' => 'name',
                'order' => 'asc',
            ],
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('Supabase list failed.');
        }

        $payload = $response->json();

        if (!is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        if (isset($payload['items']) && is_array($payload['items'])) {
            return $payload['items'];
        }

        return [];
    }

    public function listAll(string $prefix = 'uploads/', int $pageSize = 100): array
    {
        $all = [];
        $offset = 0;
        $pageSize = max(1, $pageSize);

        do {
            $batch = $this->list($prefix, $pageSize, $offset);
            $count = count($batch);

            if ($count === 0) {
                break;
            }

            $all = array_merge($all, $batch);
            $offset += $count;
        } while ($count === $pageSize);

        return $all;
    }

    private function objectUrl(string $path): string
    {
        return $this->baseUrl . '/storage/v1/object/' . $this->bucket . '/' . ltrim($path, '/');
    }

    private function ensureConfigured(): void
    {
        if ($this->baseUrl === '' || $this->serviceKey === '') {
            throw new RuntimeException('Supabase storage is not configured.');
        }
    }
}
