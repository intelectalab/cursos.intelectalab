<?php

namespace App\Shared\Dashboard;

/**
 * Una sección de un panel tal como la dibuja la aplicación web y la descarga
 * el Excel: tarjetas con cifras, gráficos y una tabla. El mismo formato sirve
 * a la consola de la plataforma y a cada academia.
 */
final class Panel
{
    /** @var list<array<string, mixed>> */
    private array $cards = [];

    /** @var list<array<string, mixed>> */
    private array $charts = [];

    /** @var array<string, mixed>|null */
    private ?array $table = null;

    public function __construct(private readonly string $title) {}

    /**
     * @param  string  $format  number, money o percent
     * @param  string  $tone  brand, success, warning, danger, info o violet: el color de la tarjeta
     * @param  float|int|null  $previous  lo mismo en el periodo anterior, para comparar
     */
    public function card(string $label, float|int $value, string $format = 'number', string $tone = 'brand', ?string $hint = null, float|int|null $previous = null, ?string $currency = null): self
    {
        $this->cards[] = compact('label', 'value', 'format', 'tone', 'hint', 'previous', 'currency');

        return $this;
    }

    /**
     * @param  string  $type  donut (partes de un todo), bar (columnas en el tiempo) o hbar (ranking)
     * @param  array<string, float|int>  $items  etiqueta => valor, en el orden en que se dibujan
     */
    public function chart(string $title, string $type, array $items, string $format = 'number', ?string $currency = null, ?string $note = null): self
    {
        $this->charts[] = [
            'title' => $title,
            'type' => $type,
            'format' => $format,
            'currency' => $currency,
            'note' => $note,
            'items' => array_map(fn (string $label, float|int $value) => ['label' => $label, 'value' => $value], array_keys($items), array_values($items)),
        ];

        return $this;
    }

    /**
     * @param  array<string, string>  $columns  clave => título
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $formats  clave => number, money, date o datetime (lo demás es texto)
     */
    public function table(string $title, array $columns, array $rows, array $formats = [], ?string $empty = null): self
    {
        $this->table = [
            'title' => $title,
            'columns' => array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label, 'format' => $formats[$key] ?? 'text'], array_keys($columns), array_values($columns)),
            'rows' => $rows,
            'empty' => $empty,
        ];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'cards' => $this->cards, 'charts' => $this->charts, 'table' => $this->table];
    }

    /**
     * Cambia las claves por su nombre en pantalla, en el orden de los nombres,
     * y deja fuera lo que está en cero.
     *
     * @param  array<string, float|int>  $values
     * @param  array<string, string>  $labels
     * @return array<string, float|int>
     */
    public static function labelled(array $values, array $labels): array
    {
        $result = [];
        foreach ($labels as $key => $label) {
            if (($values[$key] ?? 0) > 0) {
                $result[$label] = $values[$key];
            }
        }
        foreach ($values as $key => $value) {
            if (! isset($labels[$key]) && $value > 0) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Las hojas de Excel de esta sección: una con las cifras y los gráficos
     * (como filas, para usarlas en una hoja de cálculo o en Power BI) y otra
     * con la tabla.
     *
     * @param  array<string, mixed>  $panel  lo que devuelve toArray()
     * @return array<string, array{headers: list<string>, rows: list<list<string|int|float|null>>}>
     */
    public static function sheets(array $panel): array
    {
        $rows = [];

        foreach ($panel['cards'] as $card) {
            $rows[] = ['Indicador', $card['label'], $card['value'], $card['previous'], $card['currency'] ?? ($card['format'] === 'percent' ? '%' : null)];
        }

        foreach ($panel['charts'] as $chart) {
            foreach ($chart['items'] as $item) {
                $rows[] = [$chart['title'], $item['label'], $item['value'], null, $chart['currency'] ?? ($chart['format'] === 'percent' ? '%' : null)];
            }
        }

        $sheets = [$panel['title'] => ['headers' => ['Bloque', 'Concepto', 'Valor', 'Periodo anterior', 'Unidad'], 'rows' => $rows]];

        if ($panel['table'] !== null) {
            $columns = $panel['table']['columns'];
            $sheets[$panel['title'].' - detalle'] = [
                'headers' => array_column($columns, 'label'),
                'rows' => array_map(fn (array $row) => array_map(fn (array $column) => $row[$column['key']] ?? null, $columns), $panel['table']['rows']),
            ];
        }

        return $sheets;
    }
}
