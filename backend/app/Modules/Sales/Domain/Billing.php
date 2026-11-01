<?php

namespace App\Modules\Sales\Domain;

use App\Modules\Crm\Domain\Models\Company;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Validation\Rule;

/**
 * El comprobante que pide el comprador: boleta (con su documento, o sin él
 * si la compra es chica, como permite SUNAT) o factura (con RUC, razón
 * social y dirección). Se guarda en la orden y se usa al emitir cada pago.
 *
 * @phpstan-type BillingData array{type: string, doc_type: ?string, doc_number: ?string, name: string, address: ?string}
 */
final class Billing
{
    public const BOLETA = 'BOLETA';

    public const FACTURA = 'FACTURA';

    public const DOC_RUC = 'RUC';

    /** RUC de empresa o persona con negocio: 11 dígitos que empiezan con 10, 15, 17 o 20 */
    private const RUC_PATTERN = '/^(10|15|17|20)\d{9}$/';

    /**
     * Reglas para lo que llega del formulario (todo opcional: sin datos, boleta a nombre del comprador).
     *
     * @return array<string, mixed>
     */
    public static function rules(?string $docType = null, string $prefix = 'billing'): array
    {
        return [
            $prefix => ['nullable', 'array'],
            "{$prefix}.type" => ["required_with:{$prefix}", Rule::in([self::BOLETA, self::FACTURA])],
            "{$prefix}.ruc" => ["required_if:{$prefix}.type,".self::FACTURA, 'nullable', 'string', 'regex:'.self::RUC_PATTERN],
            "{$prefix}.name" => ["required_if:{$prefix}.type,".self::FACTURA, 'nullable', 'string', 'max:200'],
            "{$prefix}.address" => ["required_if:{$prefix}.type,".self::FACTURA, 'nullable', 'string', 'max:250'],
            "{$prefix}.doc_type" => ['nullable', "required_with:{$prefix}.doc_number", Rule::in(array_keys(User::DOCUMENT_TYPES))],
            "{$prefix}.doc_number" => ['nullable', "required_with:{$prefix}.doc_type", 'string', User::DOCUMENT_TYPES[$docType]['rule'] ?? 'max:15'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $prefix = 'billing'): array
    {
        return [
            "{$prefix}.ruc.required_if" => 'Escribe el RUC para la factura.',
            "{$prefix}.ruc.regex" => 'El RUC tiene 11 dígitos y empieza con 10, 15, 17 o 20.',
            "{$prefix}.name.required_if" => 'Escribe la razón social para la factura.',
            "{$prefix}.address.required_if" => 'Escribe la dirección fiscal para la factura.',
        ];
    }

    /**
     * Lo que se guarda: sin datos o con boleta sin documento, los del comprador.
     *
     * @param  array<string, mixed>|null  $input
     * @return BillingData
     */
    public static function from(?array $input, User $customer): array
    {
        if (($input['type'] ?? null) === self::FACTURA) {
            return [
                'type' => self::FACTURA,
                'doc_type' => self::DOC_RUC,
                'doc_number' => $input['ruc'],
                'name' => trim($input['name']),
                'address' => trim($input['address']),
            ];
        }

        $docType = filled($input['doc_number'] ?? null) ? $input['doc_type'] : $customer->doc_type;
        $docNumber = filled($input['doc_number'] ?? null) ? $input['doc_number'] : $customer->doc_number;

        return [
            'type' => self::BOLETA,
            'doc_type' => filled($docNumber) ? $docType : null,
            'doc_number' => filled($docNumber) ? strtoupper(trim($docNumber)) : null,
            'name' => filled($input['name'] ?? null) ? trim($input['name']) : trim("{$customer->first_name} {$customer->last_name}"),
            'address' => null,
        ];
    }

    /**
     * Venta a una empresa: factura con su RUC si lo tiene registrado.
     *
     * @return BillingData|null
     */
    public static function forCompany(Company $company): ?array
    {
        if (! is_string($company->tax_id) || preg_match(self::RUC_PATTERN, $company->tax_id) !== 1) {
            return null;
        }

        return [
            'type' => self::FACTURA,
            'doc_type' => self::DOC_RUC,
            'doc_number' => $company->tax_id,
            'name' => $company->name,
            'address' => $company->address,
        ];
    }
}
