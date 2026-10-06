<?php

namespace App\Modules\Cms\Domain;

/**
 * Contenido de una página: el documento estructurado que produce el editor
 * (árbol de nodos), no HTML. Antes de guardarlo se reconstruye conservando
 * solo los nodos, marcas y atributos de esta lista, así que lo que llegue de
 * más (scripts, estilos, enlaces `javascript:`) nunca se almacena ni se
 * muestra. Quien lo pinta recorre el árbol; no inserta HTML.
 */
class PageContent
{
    /** Tamaño máximo del documento, en bytes de su JSON */
    public const MAX_BYTES = 200_000;

    private const MAX_DEPTH = 12;

    /** Bloques que contienen otros nodos */
    private const CONTAINERS = ['doc', 'paragraph', 'heading', 'bulletList', 'orderedList', 'listItem', 'blockquote'];

    private const MARKS = ['bold', 'italic', 'underline', 'strike'];

    /** Las imágenes solo pueden ser archivos públicos de la propia organización */
    private const IMAGE_SRC = '#^/api/v1/public-files/[0-9a-f-]{36}$#';

    /**
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return ['type' => 'doc', 'content' => []];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function clean(array $document): array
    {
        return self::node(['type' => 'doc', 'content' => $document['content'] ?? []], 0) ?? self::empty();
    }

    /**
     * Texto plano del documento: sirve para saber si la página tiene contenido.
     *
     * @param  array<string, mixed>  $node
     */
    public static function text(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return (string) ($node['text'] ?? '');
        }

        if (($node['type'] ?? null) === 'image') {
            return '[imagen]';
        }

        return trim(implode(' ', array_map(fn (array $child) => self::text($child), $node['content'] ?? [])));
    }

    /**
     * @param  mixed  $node
     * @return array<string, mixed>|null
     */
    private static function node($node, int $depth): ?array
    {
        if (! is_array($node) || $depth > self::MAX_DEPTH) {
            return null;
        }

        $type = $node['type'] ?? null;

        if (in_array($type, self::CONTAINERS, true)) {
            $children = array_values(array_filter(array_map(
                fn ($child) => self::node($child, $depth + 1),
                is_array($node['content'] ?? null) ? $node['content'] : []
            )));

            $clean = ['type' => $type, 'content' => $children];

            if ($type === 'heading') {
                // Los títulos de la página son de nivel 2 y 3: el 1 es el título de la página
                $clean['attrs'] = ['level' => ((int) ($node['attrs']['level'] ?? 2)) === 3 ? 3 : 2];
            }

            return $clean;
        }

        return match ($type) {
            'text' => self::textNode($node),
            'hardBreak', 'horizontalRule' => ['type' => $type],
            'image' => self::image($node),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private static function textNode(array $node): ?array
    {
        $text = is_string($node['text'] ?? null) ? $node['text'] : '';

        if ($text === '') {
            return null;
        }

        $marks = [];

        foreach (is_array($node['marks'] ?? null) ? $node['marks'] : [] as $mark) {
            $markType = $mark['type'] ?? null;

            if (in_array($markType, self::MARKS, true)) {
                $marks[] = ['type' => $markType];
            } elseif ($markType === 'link' && ($href = self::href($mark['attrs']['href'] ?? null))) {
                $marks[] = ['type' => 'link', 'attrs' => ['href' => $href]];
            }
        }

        return ['type' => 'text', 'text' => $text] + ($marks ? ['marks' => $marks] : []);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private static function image(array $node): ?array
    {
        $src = $node['attrs']['src'] ?? null;

        if (! is_string($src) || ! preg_match(self::IMAGE_SRC, $src)) {
            return null;
        }

        $alt = is_string($node['attrs']['alt'] ?? null) ? mb_substr($node['attrs']['alt'], 0, 200) : null;

        return ['type' => 'image', 'attrs' => ['src' => $src, 'alt' => $alt]];
    }

    /**
     * Solo enlaces web, de correo, de teléfono o internos del sitio.
     *
     * @param  mixed  $href
     */
    private static function href($href): ?string
    {
        if (! is_string($href) || mb_strlen($href) > 500) {
            return null;
        }

        $href = trim($href);

        $allowed = preg_match('#^(https?://|mailto:|tel:)#i', $href)
            || (str_starts_with($href, '/') && ! str_starts_with($href, '//'));

        return $allowed ? $href : null;
    }
}
