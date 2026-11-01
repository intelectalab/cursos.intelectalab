<?php

namespace App\Modules\Platform\Application;

use Carbon\CarbonImmutable;

/**
 * Bitácora de los respaldos: una línea JSON por respaldo en estado.jsonl,
 * junto a los archivos descargables. La escriben el script diario del
 * servidor (deploy/scripts/backup.sh, como root) y el «Respaldo ahora» de
 * la consola (la aplicación); así la consola ve los dos.
 */
class BackupLog
{
    public const KIND_DAILY = 'DAILY';

    public const KIND_WEEKLY = 'WEEKLY';

    public const KIND_MANUAL = 'MANUAL';

    /** Tras este tiempo sin un respaldo bueno, la plataforma avisa */
    public const MAX_HOURS_WITHOUT_BACKUP = 48;

    private const FILE = 'estado.jsonl';

    /**
     * Los últimos respaldos, del más reciente al más antiguo.
     *
     * @return list<array{kind: string, started_at: string, finished_at: ?string, ok: ?bool, size: ?int, file: ?string, error: ?string, by: ?string}>
     */
    public function recent(int $limit = 30): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        $entries = [];
        foreach (array_reverse(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
            $entry = json_decode($line, true);
            if (! is_array($entry) || ! isset($entry['kind'], $entry['started_at'])) {
                continue;
            }
            $entries[] = [
                'kind' => (string) $entry['kind'],
                'started_at' => (string) $entry['started_at'],
                'finished_at' => $entry['finished_at'] ?? null,
                'ok' => $entry['ok'] ?? null,
                'size' => isset($entry['size']) ? (int) $entry['size'] : null,
                'file' => $entry['file'] ?? null,
                'error' => isset($entry['error']) ? mb_substr((string) $entry['error'], 0, 300) : null,
                'by' => $entry['by'] ?? null,
            ];
            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    /**
     * El último respaldo que salió bien (diario, semanal o manual).
     */
    public function lastSuccess(): ?CarbonImmutable
    {
        foreach ($this->recent(200) as $entry) {
            if ($entry['ok'] === true) {
                return CarbonImmutable::parse($entry['finished_at'] ?? $entry['started_at']);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function append(array $entry): void
    {
        $directory = dirname($this->path());

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($this->path(), json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function path(): string
    {
        return config('tenancy.backups_path').'/'.self::FILE;
    }
}
