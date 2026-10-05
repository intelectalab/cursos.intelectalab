<?php

namespace App\Modules\Courses\Domain;

/**
 * Los videos de los cursos se alojan en YouTube (decisión del 2026-10-05).
 * Del enlace que pega el usuario solo se conserva el identificador del video.
 */
class YouTube
{
    private const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];

    /**
     * Identificador del video, o null si la dirección no es de un video de YouTube.
     * Acepta los formatos watch?v=, youtu.be/, embed/, shorts/ y live/.
     */
    public static function videoId(?string $url): ?string
    {
        $parts = parse_url(trim((string) $url));

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        if ($host === 'youtu.be') {
            $candidate = ltrim($path, '/');
        } elseif (! in_array($host, self::HOSTS, true)) {
            return null;
        } elseif ($path === '/watch') {
            parse_str($parts['query'] ?? '', $query);
            $candidate = is_string($query['v'] ?? null) ? $query['v'] : '';
        } elseif (preg_match('#^/(?:embed|shorts|live)/([^/]+)#', $path, $match)) {
            $candidate = $match[1];
        } else {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) ? $candidate : null;
    }

    public static function watchUrl(string $videoId): string
    {
        return "https://www.youtube.com/watch?v={$videoId}";
    }
}
