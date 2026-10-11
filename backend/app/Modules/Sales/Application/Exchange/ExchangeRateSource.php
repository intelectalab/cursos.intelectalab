<?php

namespace App\Modules\Sales\Application\Exchange;

/**
 * De dónde sale un tipo de cambio automático. Otra fuente (un banco, otro
 * servicio) entra implementando esta interfaz y etiquetándola.
 */
interface ExchangeRateSource
{
    /** Etiqueta con la que el contenedor reúne las fuentes disponibles */
    public const TAG = 'payments.exchange_rate_sources';

    /** Código que queda en exchange_rates.source */
    public function code(): string;

    /** Nombre que ve quien configura la organización */
    public function label(): string;

    /**
     * Soles por dólar de hoy según la fuente, o null si no respondió.
     *
     * @return array{rate: float, date: string}|null
     */
    public function today(): ?array;
}
