<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Domain\EmailCatalog;
use App\Modules\Tenancy\Domain\Models\EmailTemplate;
use App\Modules\Tenancy\Domain\Models\PlatformEmailTemplate;
use Illuminate\Validation\ValidationException;

/**
 * Textos de los correos automáticos. Para un correo de academia manda el
 * texto de la academia; si no lo cambió, el texto base de la plataforma; y
 * si tampoco, el original del sistema (EmailCatalog). Cada campo (asunto,
 * mensaje, botón) se resuelve por separado.
 */
class EmailTemplates
{
    public const SOURCE_ACADEMY = 'academy';

    public const SOURCE_PLATFORM = 'platform';

    public const SOURCE_SYSTEM = 'system';

    private const FIELDS = ['subject', 'body', 'button'];

    /**
     * El texto vigente de un correo y su texto base (al que vuelve si se restaura).
     *
     * @param  bool  $forAcademy  true = lo que ve y usa la academia actual; false = el nivel de la plataforma
     * @return array{current: array{subject: string, body: string, button: ?string, enabled: bool}, base: array{subject: string, body: string, button: ?string}, customized: bool, row: EmailTemplate|PlatformEmailTemplate|null}
     */
    public function resolve(string $key, bool $forAcademy): array
    {
        $definition = EmailCatalog::get($key);
        $original = ['subject' => $definition['subject'], 'body' => $definition['body'], 'button' => $definition['button']];
        $platform = PlatformEmailTemplate::query()->where('key', $key)->first();
        $academy = $forAcademy ? EmailTemplate::query()->where('key', $key)->first() : null;

        // Para la academia, la base es el texto de la plataforma; para la plataforma, el original
        $base = $forAcademy ? $this->layer($original, $platform) : $original;
        $row = $forAcademy ? $academy : $platform;
        $current = $this->layer($base, $row);

        return [
            'current' => $current + ['enabled' => $definition['required'] || ($row?->enabled ?? true)],
            'base' => $base,
            'customized' => $row !== null,
            'row' => $row,
        ];
    }

    /**
     * El correo listo para enviar con los datos de este caso, o null si la
     * academia (o la plataforma) lo apagó.
     *
     * @param  array<string, string|int|float|null>  $values
     * @return array{subject: string, lines: list<string>, button: ?string}|null
     */
    public function render(string $key, array $values, bool $forAcademy = true): ?array
    {
        $resolved = $this->resolve($key, $forAcademy);

        if (! $resolved['current']['enabled']) {
            return null;
        }

        return self::fill($resolved['current'], $values);
    }

    /**
     * Reemplaza los datos en un texto. Un párrafo que usa un dato vacío no se envía.
     *
     * @param  array{subject: string, body: string, button: ?string}  $texts
     * @param  array<string, string|int|float|null>  $values
     * @return array{subject: string, lines: list<string>, button: ?string}
     */
    public static function fill(array $texts, array $values): array
    {
        $value = fn (string $name) => isset($values[$name]) && trim((string) $values[$name]) !== '' ? (string) $values[$name] : null;
        $replace = fn (string $text) => preg_replace_callback('/\{([a-z_]+)\}/', fn (array $match) => $value($match[1]) ?? '', $text);

        $lines = [];
        foreach (preg_split('/\R\s*\R/', trim($texts['body'])) as $paragraph) {
            preg_match_all('/\{([a-z_]+)\}/', $paragraph, $used);
            if (collect($used[1])->contains(fn (string $name) => $value($name) === null)) {
                continue;
            }
            $line = trim(preg_replace('/\s*\R\s*/', ' ', $replace($paragraph)));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return [
            'subject' => trim(preg_replace('/\s{2,}/', ' ', $replace($texts['subject']))),
            'lines' => $lines,
            'button' => filled($texts['button']) ? trim($replace($texts['button'])) : null,
        ];
    }

    /**
     * Revisa un texto antes de guardarlo: datos que existen, datos esenciales y largo.
     *
     * @param  array{subject?: ?string, body?: ?string, button?: ?string}  $texts
     *
     * @throws ValidationException
     */
    public static function validate(string $key, array $texts): void
    {
        $definition = EmailCatalog::get($key);
        $known = array_keys($definition['variables']);
        $errors = [];

        foreach (['subject' => 'el asunto', 'body' => 'el mensaje', 'button' => 'el botón'] as $field => $label) {
            $text = (string) ($texts[$field] ?? '');
            preg_match_all('/\{([^{}]*)\}/', $text, $found);
            $unknown = array_values(array_unique(array_diff($found[1], $known)));

            if ($unknown !== []) {
                $errors[$field][] = 'En '.$label.' hay datos que este correo no tiene: '.implode(', ', array_map(fn ($name) => '{'.$name.'}', $unknown)).'. Usa solo los de la lista.';
            }
            if (substr_count($text, '{') !== substr_count($text, '}')) {
                $errors[$field][] = 'En '.$label.' falta abrir o cerrar una llave: cada dato va así, {curso}.';
            }
        }

        $whole = ($texts['subject'] ?? '').' '.($texts['body'] ?? '');
        $missing = array_values(array_filter($definition['essential'], fn (string $name) => ! str_contains($whole, '{'.$name.'}')));
        if ($missing !== []) {
            $errors['body'][] = 'Este correo debe incluir '.implode(' y ', array_map(fn ($name) => '{'.$name.'}', $missing)).': sin ese dato no le sirve a quien lo recibe.';
        }

        if ($definition['button'] !== null && blank($texts['button'] ?? null)) {
            $errors['button'][] = 'Este correo lleva un botón: escribe su texto.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array{subject: string, body: string, button: ?string}  $below
     * @return array{subject: string, body: string, button: ?string}
     */
    private function layer(array $below, EmailTemplate|PlatformEmailTemplate|null $row): array
    {
        $result = $below;
        foreach (self::FIELDS as $field) {
            if ($row !== null && filled($row->{$field})) {
                $result[$field] = $row->{$field};
            }
        }

        return $result;
    }
}
