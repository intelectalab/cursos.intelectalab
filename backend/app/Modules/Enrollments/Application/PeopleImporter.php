<?php

namespace App\Modules\Enrollments\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Identity\Application\RoleService;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Group;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Import\Importer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Importa personas desde una hoja y, en la misma pasada, las matricula en un
 * curso y las suma a un grupo. Quien no tiene cuenta la recibe como alumno, con
 * su número de documento como contraseña temporal que debe cambiar al entrar.
 */
class PeopleImporter extends Importer
{
    /** La contraseña temporal es el documento: debe cumplir el mínimo de una contraseña */
    private const MIN_PASSWORD = 8;

    private User $actor;

    private bool $canEnroll = false;

    public function __construct(
        private readonly TenantNotifier $notifier,
        private readonly EnrollmentService $enrollments,
        private readonly RoleService $roles,
        private readonly AuditLogger $audit,
    ) {}

    public function by(User $actor): static
    {
        $this->actor = $actor;
        $this->canEnroll = $actor->hasPermission('enrollments.create');

        return $this;
    }

    public function name(): string
    {
        return 'Personas y matrículas';
    }

    public function columns(): array
    {
        return [
            'first_name' => ['label' => 'Nombres', 'required' => true, 'hint' => 'Nombres de la persona.', 'example' => 'Ana María'],
            'last_name' => ['label' => 'Apellidos', 'required' => true, 'hint' => 'Apellidos de la persona.', 'example' => 'Torres Quispe'],
            'email' => ['label' => 'Correo', 'required' => true, 'hint' => 'Con este correo iniciará sesión. Si ya tiene cuenta, no se crea otra: solo se matricula y se agrega al grupo.', 'example' => 'ana.torres@correo.com'],
            'doc_type' => ['label' => 'Tipo de documento', 'hint' => 'DNI, CE o PASAPORTE. Vacío se toma como DNI.', 'example' => 'DNI'],
            'doc_number' => ['label' => 'Número de documento', 'required' => true, 'hint' => 'Será su contraseña temporal: al entrar por primera vez deberá cambiarla. Mínimo '.self::MIN_PASSWORD.' caracteres.', 'example' => '45678912'],
            'phone' => ['label' => 'Teléfono', 'hint' => 'Opcional.', 'example' => '999 888 777'],
            'course' => ['label' => 'Curso', 'hint' => 'Título exacto de un curso publicado donde matricularla. Vacío: no se matricula.', 'example' => 'Excel para finanzas'],
            'group' => ['label' => 'Grupo', 'hint' => 'Nombre del grupo al que se suma. Si no existe, se crea. Vacío: sin grupo.', 'example' => 'Promoción 2026-II'],
            'ends_at' => ['label' => 'Fin del acceso', 'hint' => 'Fecha AAAA-MM-DD hasta la que puede entrar al curso. Vacío: lo que defina el curso.', 'example' => '2026-12-31'],
        ];
    }

    protected function process(array $rows): array
    {
        $results = [];
        $seen = [];

        foreach ($rows as ['line' => $line, 'values' => $values]) {
            $values['email'] = Str::lower($values['email']);
            $values['doc_type'] = Str::upper($values['doc_type']) ?: 'DNI';
            $label = trim("{$values['first_name']} {$values['last_name']}") ?: $values['email'];

            $errors = $this->validate($values);
            if ($errors === [] && isset($seen[$values['email']])) {
                $errors[] = "El correo se repite en la fila {$seen[$values['email']]}.";
            }

            if ($errors !== []) {
                $results[] = $this->result($line, self::STATUS_ERROR, $label, $errors);

                continue;
            }

            $seen[$values['email']] = $line;

            try {
                $done = DB::transaction(fn () => $this->apply($values));
                $results[] = $this->result($line, $done['changed'] ? self::STATUS_OK : self::STATUS_SKIP, $label, $done['messages']);
            } catch (ApiException $e) {
                $results[] = $this->result($line, self::STATUS_ERROR, $label, [$e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function validate(array $values): array
    {
        $documentRule = User::DOCUMENT_TYPES[$values['doc_type']]['rule'] ?? null;

        $validator = Validator::make($values, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:180'],
            'doc_type' => [Rule::in(array_keys(User::DOCUMENT_TYPES))],
            'doc_number' => ['required', 'string', 'min:'.self::MIN_PASSWORD, ...($documentRule ? [$documentRule] : [])],
            'phone' => ['nullable', 'string', 'max:30'],
            'course' => ['nullable', 'string', 'max:200'],
            'group' => ['nullable', 'string', 'max:120'],
            'ends_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ], [
            'first_name.required' => 'Faltan los nombres.',
            'last_name.required' => 'Faltan los apellidos.',
            'email.required' => 'Falta el correo.',
            'email.email' => 'El correo no es válido.',
            'doc_type.in' => 'El tipo de documento debe ser DNI, CE o PASAPORTE.',
            'doc_number.required' => 'Falta el número de documento.',
            'doc_number.min' => 'El documento debe tener al menos '.self::MIN_PASSWORD.' caracteres para servir de contraseña temporal.',
            'doc_number.digits' => 'Un DNI tiene 8 dígitos.',
            'doc_number.regex' => 'El número de documento no tiene el formato de su tipo.',
            'ends_at.date_format' => 'El fin del acceso debe tener el formato AAAA-MM-DD.',
            'ends_at.after_or_equal' => 'El fin del acceso ya pasó.',
        ]);

        $errors = $validator->errors()->all();

        if ($values['course'] !== '' && ! $this->canEnroll) {
            $errors[] = 'No tienes permiso para matricular: deja vacía la columna Curso.';
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $values
     * @return array{changed: bool, messages: list<string>}
     */
    private function apply(array $values): array
    {
        $messages = [];
        $changed = false;

        // Primero lo que puede fallar por datos que no existen: nada se crea a medias
        $course = $values['course'] !== '' ? $this->course($values['course']) : null;

        $user = User::query()->where('email', $values['email'])->first();

        if ($user === null) {
            if (User::query()->where('doc_type', $values['doc_type'])->where('doc_number', $values['doc_number'])->exists()) {
                throw new ApiException(ErrorCode::ValidationError, 'Ya hay otra cuenta con ese documento.', 422);
            }

            $user = User::create([
                'email' => $values['email'],
                'password' => $values['doc_number'],
                'first_name' => $values['first_name'],
                'last_name' => $values['last_name'],
                'phone' => $values['phone'] ?: null,
                'doc_type' => $values['doc_type'],
                'doc_number' => $values['doc_number'],
                'status' => User::STATUS_ACTIVE,
                'must_change_password' => true,
            ]);
            $this->audit->record('users.created', $user, null, $user->only(['email', 'first_name', 'last_name']) + ['source' => 'import']);
            $this->notifier->notify($user, 'cuenta_importada', ['correo' => $user->email], '/login');
            $this->roles->grantBaseRole($user, RoleCatalog::STUDENT);

            $messages[] = 'Cuenta nueva: entra con su correo y su documento como contraseña temporal.';
            $changed = true;
        } elseif (! $user->isActive()) {
            throw new ApiException(ErrorCode::ValidationError, 'Ya tiene una cuenta, pero está desactivada. Actívala en Usuarios.', 422);
        } else {
            $messages[] = 'Ya tenía cuenta: no se modificaron sus datos.';
        }

        if ($course !== null) {
            if ($course->teachers()->whereKey($user->id)->exists()) {
                throw new ApiException(ErrorCode::ValidationError, "Ya es docente de «{$course->title}»: no puede ser también alumno.", 422);
            }

            $this->roles->grantBaseRole($user, RoleCatalog::STUDENT);
            $endsAt = $values['ends_at'] !== '' ? Carbon::parse($values['ends_at'])->endOfDay() : null;
            $created = $this->enrollments->enroll($course, [$user->id], $this->actor, null, $endsAt)->count();

            $messages[] = $created > 0 ? "Matriculada en «{$course->title}»." : "Ya estaba matriculada en «{$course->title}».";
            $changed = $changed || $created > 0;
        }

        if ($values['group'] !== '') {
            $group = Group::query()->whereRaw('lower(name) = ?', [Str::lower($values['group'])])->first()
                ?? tap(Group::create(['name' => $values['group'], 'created_by' => $this->actor->id]), fn (Group $new) => $this->audit->record('groups.created', $new, null, ['name' => $new->name, 'source' => 'import']));

            $added = count($group->members()->syncWithoutDetaching([$user->id])['attached']);

            $messages[] = $added > 0 ? "Agregada al grupo «{$group->name}»." : "Ya estaba en el grupo «{$group->name}».";
            $changed = $changed || $added > 0;
        }

        return ['changed' => $changed, 'messages' => $messages];
    }

    private function course(string $title): Course
    {
        $matches = Course::query()->where('status', Course::STATUS_PUBLISHED)->whereRaw('lower(title) = ?', [Str::lower($title)])->get();

        if ($matches->count() !== 1) {
            throw new ApiException(
                ErrorCode::ValidationError,
                $matches->isEmpty() ? "No hay un curso publicado llamado «{$title}»." : "Hay más de un curso publicado llamado «{$title}»: matricula a esta persona desde el curso.",
                422
            );
        }

        return $matches->first();
    }
}
