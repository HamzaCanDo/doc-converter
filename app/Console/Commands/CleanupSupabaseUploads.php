<?php

namespace App\Console\Commands;

use App\Services\SupabaseStorageService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

class CleanupSupabaseUploads extends Command
{
    protected $signature = 'uploads:cleanup
        {--older-than=60 : Delete files older than this many minutes}
        {--prefix=uploads/ : Storage prefix to scan}
        {--dry-run : Show candidates without deleting them}';

    protected $description = 'Delete stale uploaded files from Supabase storage.';

    public function __construct(private SupabaseStorageService $storage)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $olderThanMinutes = (int) $this->option('older-than');
        if ($olderThanMinutes < 1) {
            $this->error('--older-than must be greater than 0.');

            return self::INVALID;
        }

        $prefix = trim((string) $this->option('prefix'));
        if ($prefix === '') {
            $prefix = 'uploads/';
        }

        if (!str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        $dryRun = (bool) $this->option('dry-run');
        $threshold = CarbonImmutable::now()->subMinutes($olderThanMinutes);

        try {
            $objects = $this->storage->listAll($prefix);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $candidates = [];

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $name = trim((string) ($object['name'] ?? ''));
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            $path = str_starts_with($name, $prefix) ? $name : $prefix . ltrim($name, '/');

            $timestamp = $object['updated_at'] ?? $object['created_at'] ?? $object['last_accessed_at'] ?? null;
            if (!is_string($timestamp) || $timestamp === '') {
                continue;
            }

            try {
                $updatedAt = CarbonImmutable::parse($timestamp);
            } catch (\Throwable) {
                continue;
            }

            if ($updatedAt->greaterThan($threshold)) {
                continue;
            }

            $candidates[] = [
                'path' => $path,
                'updated_at' => $updatedAt,
            ];
        }

        if (empty($candidates)) {
            $this->info('No stale uploads found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $preview = array_map(function (array $item): array {
                return [
                    'path' => $item['path'],
                    'updated_at' => $item['updated_at']->toDateTimeString(),
                ];
            }, $candidates);

            $this->table(['Path', 'Updated At'], $preview);
            $this->info('Dry run: ' . count($candidates) . ' file(s) would be deleted.');

            return self::SUCCESS;
        }

        $deleted = 0;
        $failed = 0;

        foreach ($candidates as $candidate) {
            try {
                $this->storage->delete($candidate['path']);
                $deleted++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn('Failed to delete ' . $candidate['path'] . ': ' . $e->getMessage());
            }
        }

        $this->info('Cleanup completed. Deleted: ' . $deleted . ', Failed: ' . $failed . '.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
