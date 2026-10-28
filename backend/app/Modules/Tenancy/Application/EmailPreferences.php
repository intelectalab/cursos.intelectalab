<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\EmailCatalog;
use App\Modules\Tenancy\Domain\Models\EmailOptOut;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qué correos de la academia recibe cada persona. Se da de baja por tipo de
 * correo (EmailCatalog::OPT_OUTS), desde el enlace al pie del correo, con un
 * clic desde su programa de correo o en «Mi cuenta». La baja vale solo para
 * esta academia y se guarda por dirección de correo.
 *
 * El enlace del correo lleva una firma de la dirección: sirve sin iniciar
 * sesión, no vence y no sirve en otra academia.
 */
class EmailPreferences
{
    /** Largo de la firma del enlace, en caracteres hexadecimales */
    private const SIGNATURE_LENGTH = 32;

    /**
     * La persona dejó de recibir los correos de ese tipo.
     */
    public function optedOut(string $email, string $category): bool
    {
        return in_array($category, $this->categoriesOf($email), true);
    }

    /**
     * Los tipos de correo que la persona puede elegir, con si hoy los recibe.
     *
     * @return list<array{key: string, name: string, description: string, subscribed: bool}>
     */
    public function of(string $email): array
    {
        $out = $this->categoriesOf($email);
        $staff = $this->isStaff($email);

        return collect(EmailCatalog::OPT_OUTS)
            ->filter(fn (array $category) => $staff || ! $category['staff'])
            ->map(fn (array $category, string $key) => [
                'key' => $key,
                'name' => $category['name'],
                'description' => $category['description'],
                'subscribed' => ! in_array($key, $out, true),
            ])
            ->values()
            ->all();
    }

    /**
     * Guarda la elección de la persona. Solo cambia los tipos que se envían.
     *
     * @param  array<string, bool>  $choices  tipo de correo => lo recibe
     */
    public function update(string $email, array $choices, string $source): void
    {
        $email = self::normalize($email);
        $staff = null;

        foreach ($choices as $category => $subscribed) {
            if (! array_key_exists($category, EmailCatalog::OPT_OUTS)) {
                continue;
            }
            // Los avisos de trabajo solo los elige quien trabaja en la academia
            if (EmailCatalog::OPT_OUTS[$category]['staff'] && ! ($staff ??= $this->isStaff($email))) {
                continue;
            }

            if ($subscribed) {
                EmailOptOut::query()->where('email', $email)->where('category', $category)->delete();
            } else {
                EmailOptOut::query()->firstOrCreate(['email' => $email, 'category' => $category], ['source' => $source]);
            }
        }
    }

    /**
     * Los tipos de correo que una persona ya no recibe, con sus nombres (para la ficha del alumno).
     *
     * @return list<string>
     */
    public function optedOutNames(string $email): array
    {
        return array_values(array_map(fn (string $key) => EmailCatalog::OPT_OUTS[$key]['name'], $this->categoriesOf($email)));
    }

    /**
     * Firma del enlace para darse de baja: la dirección y su firma, que solo vale en esta academia.
     */
    public static function token(Tenant $tenant, string $email): string
    {
        $email = self::normalize($email);

        return rtrim(strtr(base64_encode($email), '+/', '-_'), '=').'.'.self::signature($tenant, $email);
    }

    /**
     * La dirección de un enlace, o null si la firma no es de esta academia.
     */
    public static function emailFromToken(Tenant $tenant, string $token): ?string
    {
        [$encoded, $signature] = array_pad(explode('.', $token, 2), 2, '');
        $email = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($email === false || $email === '' || ! hash_equals(self::signature($tenant, $email), $signature)) {
            return null;
        }

        return $email;
    }

    /**
     * Página de la academia donde la persona confirma la baja y elige qué recibir.
     */
    public static function pageUrl(Tenant $tenant, string $email, string $category): string
    {
        return TenantUrls::base($tenant).'/correos?'.http_build_query(['t' => self::token($tenant, $email), 'c' => $category]);
    }

    /**
     * Dirección de la baja con un clic (cabecera List-Unsubscribe): la llama
     * el programa de correo, sin abrir la página. Por eso la academia va en la
     * consulta en el modo por ruta, como en los avisos de las pasarelas.
     */
    public static function oneClickUrl(Tenant $tenant, string $email, string $category): string
    {
        $query = ['category' => $category] + (TenantUrls::pathMode() ? ['tenant' => $tenant->slug] : []);

        return TenantUrls::root($tenant).'/api/v1/email-preferences/'.self::token($tenant, $email).'/unsubscribe?'.http_build_query($query);
    }

    /**
     * Dirección «enmascarada» para mostrar en la página pública: ma•••@gmail.com.
     */
    public static function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 2).'•••@'.$domain;
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return list<string>
     */
    private function categoriesOf(string $email): array
    {
        return EmailOptOut::query()->where('email', self::normalize($email))->pluck('category')->all();
    }

    /**
     * Trabaja en la academia: tiene algún rol que no es de alumno ni de contacto de empresa.
     */
    private function isStaff(string $email): bool
    {
        return User::query()
            ->whereRaw('lower(email) = ?', [self::normalize($email)])
            ->whereHas('roles', fn (Builder $roles) => $roles->whereNotIn('code', [RoleCatalog::STUDENT, RoleCatalog::COMPANY]))
            ->exists();
    }

    private static function signature(Tenant $tenant, string $email): string
    {
        return substr(hash_hmac('sha256', "email-preferences|{$tenant->id}|{$email}", (string) config('app.key')), 0, self::SIGNATURE_LENGTH);
    }
}
