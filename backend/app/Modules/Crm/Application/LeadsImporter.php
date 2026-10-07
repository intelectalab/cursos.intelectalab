<?php

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Models\Lead;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Import\Importer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Importa prospectos desde una hoja (una lista de un evento, un formulario
 * externo). Quedan como nuevos, a cargo de quien los importa.
 */
class LeadsImporter extends Importer
{
    /** Cómo se puede escribir el origen en la hoja */
    private const SOURCES = [
        'paginaweb' => 'WEB', 'web' => 'WEB',
        'recomendacion' => 'REFERRAL', 'referido' => 'REFERRAL',
        'evento' => 'EVENT',
        'llamada' => 'CALL',
        'redessociales' => 'SOCIAL', 'redes' => 'SOCIAL',
        'otro' => 'OTHER', '' => 'OTHER',
    ];

    private User $actor;

    public function by(User $actor): static
    {
        $this->actor = $actor;

        return $this;
    }

    public function name(): string
    {
        return 'Prospectos';
    }

    public function columns(): array
    {
        return [
            'first_name' => ['label' => 'Nombres', 'required' => true, 'hint' => 'Nombres de la persona de contacto.', 'example' => 'Carlos'],
            'last_name' => ['label' => 'Apellidos', 'hint' => 'Opcional.', 'example' => 'Mendoza'],
            'company_name' => ['label' => 'Empresa', 'hint' => 'Empresa donde trabaja, si aplica.', 'example' => 'Minera Andes S.A.'],
            'position' => ['label' => 'Cargo', 'hint' => 'Opcional.', 'example' => 'Jefe de capacitación'],
            'email' => ['label' => 'Correo', 'hint' => 'Hace falta el correo o el teléfono.', 'example' => 'cmendoza@mineraandes.pe'],
            'phone' => ['label' => 'Teléfono', 'hint' => 'Hace falta el correo o el teléfono.', 'example' => '987 654 321'],
            'source' => ['label' => 'Origen', 'hint' => 'Página web, Recomendación, Evento, Llamada, Redes sociales u Otro. Vacío se toma como Otro.', 'example' => 'Evento'],
            'interest' => ['label' => 'Interés', 'hint' => 'Qué le interesa o qué pidió. Opcional.', 'example' => 'Capacitar a 20 personas en Excel'],
        ];
    }

    protected function process(array $rows): array
    {
        $results = [];
        $seen = [];

        foreach ($rows as ['line' => $line, 'values' => $values]) {
            $values['email'] = Str::lower($values['email']);
            $label = trim("{$values['first_name']} {$values['last_name']}") ?: ($values['email'] ?: 'Sin nombre');
            $source = self::SOURCES[preg_replace('/[^a-z]/', '', Str::lower(Str::ascii($values['source'])))] ?? null;

            $errors = Validator::make($values, [
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['nullable', 'string', 'max:100'],
                'company_name' => ['nullable', 'string', 'max:200'],
                'position' => ['nullable', 'string', 'max:120'],
                'email' => ['required_without:phone', 'nullable', 'email', 'max:150'],
                'phone' => ['nullable', 'string', 'max:30'],
                'interest' => ['nullable', 'string', 'max:2000'],
            ], [
                'first_name.required' => 'Faltan los nombres.',
                'email.required_without' => 'Indica un correo o un teléfono para poder contactarlo.',
                'email.email' => 'El correo no es válido.',
            ])->errors()->all();

            if ($source === null) {
                $errors[] = 'El origen no se reconoce. Usa: Página web, Recomendación, Evento, Llamada, Redes sociales u Otro.';
            }

            if ($errors !== []) {
                $results[] = $this->result($line, self::STATUS_ERROR, $label, $errors);

                continue;
            }

            // Mismo correo en el archivo o ya registrado y sin cerrar: no se duplica
            $key = $values['email'] ?: null;
            if ($key !== null && (isset($seen[$key]) || Lead::query()->where('email', $key)->whereIn('status', Lead::OPEN_STATUSES)->exists())) {
                $results[] = $this->result($line, self::STATUS_SKIP, $label, [
                    isset($seen[$key]) ? "El correo se repite en la fila {$seen[$key]}." : 'Ya hay un prospecto por trabajar con ese correo.',
                ]);

                continue;
            }

            if ($key !== null) {
                $seen[$key] = $line;
            }

            Lead::create([
                'first_name' => $values['first_name'],
                'last_name' => $values['last_name'] ?: null,
                'company_name' => $values['company_name'] ?: null,
                'position' => $values['position'] ?: null,
                'email' => $values['email'] ?: null,
                'phone' => $values['phone'] ?: null,
                'source' => $source,
                'interest' => $values['interest'] ?: null,
                'status' => Lead::STATUS_NEW,
                'owner_id' => $this->actor->id,
                'created_by' => $this->actor->id,
            ]);

            $results[] = $this->result($line, self::STATUS_OK, $label, ['Prospecto registrado.']);
        }

        return $results;
    }
}
