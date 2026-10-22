<?php

namespace App\Modules\Identity\Domain;

/**
 * Códigos de un solo uso por tiempo (RFC 6238), los que muestran Google
 * Authenticator, Microsoft Authenticator y similares: 6 dígitos que cambian
 * cada 30 segundos, calculados con una clave compartida.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    private const DIGITS = 6;

    /**
     * Clave nueva de 160 bits en base32, como la piden las aplicaciones.
     */
    public static function secret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn (string $chunk) => self::ALPHABET[bindec($chunk)], str_split($bits, 5)));
    }

    /**
     * Paso de tiempo actual (cambia cada 30 segundos).
     */
    public static function step(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? now()->getTimestamp(), self::PERIOD);
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * El paso en el que vale el código, admitiendo un paso antes y uno
     * después por la diferencia de hora del teléfono; null si no vale.
     */
    public static function match(string $secret, string $code, ?int $timestamp = null): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $current = self::step($timestamp);

        foreach ([$current, $current - 1, $current + 1] as $step) {
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Enlace que las aplicaciones leen desde el QR.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return "otpauth://totp/{$label}?".http_build_query(['secret' => $secret, 'issuer' => $issuer, 'digits' => self::DIGITS, 'period' => self::PERIOD]);
    }

    private static function decode(string $secret): string
    {
        $bits = '';

        foreach (str_split(strtoupper($secret)) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn (string $byte) => chr(bindec($byte)), array_filter(str_split($bits, 8), fn (string $byte) => strlen($byte) === 8)));
    }
}
