<?php

namespace App\Modules\Tenancy\Console;

use App\Shared\Http\ErrorCode;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionMethod;
use Throwable;

/**
 * Genera la documentación técnica que pide el Maestro (§25) a partir del
 * propio sistema, para que no quede desfasada: contrato de la API (OpenAPI),
 * diccionario de datos, catálogo de estados y catálogo de errores.
 */
class ExportTechnicalDocsCommand extends Command
{
    protected $signature = 'docs:technical {--path= : Carpeta donde dejar los documentos}';

    protected $description = 'Genera docs/tecnica: OpenAPI, diccionario de datos y catálogos de estados y errores';

    /** Nombre con el que se presenta cada módulo */
    private const MODULES = [
        'Tenancy' => 'Organización y configuración',
        'Identity' => 'Identidad y accesos',
        'Audit' => 'Auditoría',
        'Platform' => 'Consola de plataforma',
        'Billing' => 'Planes de la plataforma',
        'Files' => 'Archivos',
        'Courses' => 'Cursos',
        'Academic' => 'Académico',
        'Enrollments' => 'Matrículas y aula',
        'Sales' => 'Ventas y pagos',
        'Promotions' => 'Promociones',
        'Certificates' => 'Certificados',
        'Reports' => 'Reportes',
        'Crm' => 'CRM',
        'B2b' => 'Venta a empresas',
        'Support' => 'Soporte',
        'Claims' => 'Libro de Reclamaciones',
        'Cms' => 'Páginas',
    ];

    /** Errores que arma el manejo general de excepciones: su código HTTP no está junto al mensaje */
    private const GENERAL_ERRORS = [
        'ValidationError' => '422',
        'Unauthenticated' => '401',
        'Forbidden' => '403',
        'NotFound' => '404',
        'MethodNotAllowed' => '405',
        'TooManyRequests' => '429',
        'HttpError' => 'el de la respuesta',
        'InternalError' => '500',
    ];

    /** Columnas que significan lo mismo en todas las tablas y no llevan comentario en las migraciones */
    private const COMMON_COLUMNS = [
        'id' => 'Identificador del registro',
        'tenant_id' => 'Academia a la que pertenece el registro',
        'created_at' => 'Cuándo se creó',
        'updated_at' => 'Última modificación',
        'deleted_at' => 'Cuándo se eliminó (borrado lógico)',
        'status' => 'Estado; ver el catálogo de estados',
        'number' => 'Número correlativo',
        'name' => 'Nombre',
        'title' => 'Título',
        'description' => 'Descripción',
        'notes' => 'Notas',
        'position' => 'Orden dentro de su lista',
        'email' => 'Correo electrónico',
        'phone' => 'Teléfono',
    ];

    private const GENERATED = '> Documento generado con `php artisan docs:technical` a partir del código y de la base de datos. No lo edites a mano: vuelve a generarlo.';

    public function handle(): int
    {
        $directory = rtrim($this->option('path') ?: base_path('../docs/tecnica'), '/\\');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $documents = [
            'openapi.json' => fn () => json_encode($this->openApi(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            'catalogo-de-estados.md' => fn () => $this->stateCatalog(),
            'catalogo-de-errores.md' => fn () => $this->errorCatalog(),
            // El único que necesita la base de datos encendida y migrada
            'diccionario-de-datos.md' => fn () => $this->dataDictionary(),
        ];

        $failed = false;

        foreach ($documents as $name => $render) {
            try {
                file_put_contents("{$directory}/{$name}", $render());
                $this->info("Generado: {$name}");
            } catch (QueryException) {
                $failed = true;
                $this->error("No se generó {$name}: la base de datos no respondió.");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /* ───────────────────────────── OpenAPI ───────────────────────────── */

    /**
     * Contrato de la API deducido de las rutas: dirección, método, sesión,
     * permiso, parámetros y, hasta donde las reglas de validación lo dejan
     * ver, el cuerpo de la petición.
     *
     * @return array<string, mixed>
     */
    private function openApi(): array
    {
        $paths = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }

            $path = '/'.ltrim(substr($route->uri(), strlen('api/v1')), '/');

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths[$path][strtolower($method)] = $this->operation($route, $method);
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'ERP SaaS IntelectaLab — API',
                'version' => 'v1',
                'description' => 'Generado con `php artisan docs:technical` a partir de las rutas del sistema. '
                    .'Cada academia usa su propio subdominio; la consola de plataforma, el dominio central. '
                    .'Los cuerpos de las peticiones se deducen de las reglas de validación y pueden estar incompletos; '
                    .'las respuestas se describen solo por su envoltura común (data + meta, o code + message + details + trace_id).',
            ],
            'servers' => [
                ['url' => 'https://{academia}.{dominio}/api/v1', 'description' => 'Academia', 'variables' => ['academia' => ['default' => 'demo'], 'dominio' => ['default' => 'localhost']]],
                ['url' => 'https://{dominio}/api/v1', 'description' => 'Dominio central (registro de academias y consola de plataforma)', 'variables' => ['dominio' => ['default' => 'localhost']]],
            ],
            'tags' => array_map(fn (string $name) => ['name' => $name], array_values(self::MODULES)),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Token que entrega el inicio de sesión']],
                'schemas' => [
                    'Success' => [
                        'type' => 'object',
                        'properties' => ['data' => ['description' => 'Resultado de la operación'], 'meta' => ['type' => 'object', 'nullable' => true, 'description' => 'Paginación, conteos por estado y otros datos del listado']],
                    ],
                    'Error' => [
                        'type' => 'object',
                        'required' => ['code', 'message'],
                        'properties' => [
                            'code' => ['type' => 'string', 'enum' => array_map(fn (ErrorCode $code) => $code->value, ErrorCode::cases())],
                            'message' => ['type' => 'string'],
                            'details' => ['type' => 'object', 'nullable' => true],
                            'trace_id' => ['type' => 'string', 'nullable' => true],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(RoutingRoute $route, string $method): array
    {
        $middleware = $route->gatherMiddleware();
        $authenticated = in_array('auth:sanctum', $middleware, true);
        $permission = collect($middleware)->first(fn ($item) => is_string($item) && str_starts_with($item, 'permission:'));
        $platform = in_array('platform.admin', $middleware, true);
        [$controller, $action] = array_pad(explode('@', (string) $route->getActionName()), 2, '__invoke');
        $module = preg_match('/^App\\\\Modules\\\\(\w+)\\\\/', $controller, $match) ? $match[1] : null;
        $source = $this->methodSource($controller, $action);
        $body = in_array($method, ['POST', 'PUT', 'PATCH'], true) ? $this->requestBody($source['code']) : null;

        preg_match_all('/\{(\w+)\??\}/', $route->uri(), $parameters);

        $operation = [
            'tags' => [self::MODULES[$module] ?? 'Otros'],
            'summary' => $source['summary'] ?: Str::headline($action),
            'operationId' => ($module ? Str::camel($module) : 'app').'.'.class_basename($controller).'.'.$action.(count(array_diff($route->methods(), ['HEAD'])) > 1 ? '.'.strtolower($method) : ''),
            // Dónde se llama: en el subdominio de una academia o en el dominio central
            'x-scope' => in_array('central', $middleware, true) ? 'central' : (in_array('tenant', $middleware, true) ? 'academia' : 'cualquiera'),
        ];

        if ($permission) {
            // Basta con tener uno de los permisos
            $operation['x-permission'] = explode(',', substr($permission, strlen('permission:')));
        } elseif ($platform) {
            $operation['x-permission'] = ['administrador de plataforma'];
        }

        $operation['security'] = $authenticated ? [['bearerAuth' => []]] : [];

        $operation['parameters'] = array_map(fn (string $name) => [
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string'],
        ], $parameters[1]);

        if (in_array('idempotent', $middleware, true)) {
            $operation['parameters'][] = [
                'name' => 'Idempotency-Key',
                'in' => 'header',
                'required' => false,
                'schema' => ['type' => 'string'],
                'description' => 'Repetir la petición con la misma clave devuelve el resultado ya obtenido, sin repetir el efecto.',
            ];
        }

        if ($operation['parameters'] === []) {
            unset($operation['parameters']);
        }

        if ($body !== null) {
            $operation['requestBody'] = $body;
        }

        $operation['responses'] = array_filter([
            '200' => ['description' => 'Operación correcta', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Success']]]],
            '401' => $authenticated ? $this->errorResponse('Sin sesión o sesión vencida') : null,
            '403' => $permission || $platform ? $this->errorResponse('Sin permiso, o academia en solo lectura') : null,
            '404' => $parameters[1] !== [] ? $this->errorResponse('No existe en esta organización') : null,
            '409' => $method !== 'GET' ? $this->errorResponse('El estado actual no permite la operación') : null,
            '422' => $body !== null ? $this->errorResponse('Datos no válidos: details indica el campo') : null,
        ]);

        return $operation;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
    }

    /**
     * Primer párrafo del comentario del método y su código fuente.
     *
     * @return array{summary: string, code: string}
     */
    private function methodSource(string $controller, string $action): array
    {
        try {
            $method = new ReflectionMethod($controller, $action);
        } catch (Throwable) {
            return ['summary' => '', 'code' => ''];
        }

        $summary = '';

        foreach (preg_split('/\R/', (string) $method->getDocComment()) ?: [] as $line) {
            $line = trim(preg_replace('#^\s*/?\*+/?#', '', $line) ?? '');

            if ($line === '' && $summary !== '') {
                break;
            }

            if ($line !== '' && ! str_starts_with($line, '@')) {
                $summary = trim("{$summary} {$line}");
            }
        }

        $lines = file((string) $method->getFileName()) ?: [];

        return [
            'summary' => $summary,
            'code' => implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1)),
        ];
    }

    /**
     * Campos que valida el método, leídos de sus llamadas a validate([...]).
     * Null si no valida nada; un cuerpo genérico si valida con reglas que
     * vienen de otra parte.
     *
     * @return array<string, mixed>|null
     */
    private function requestBody(string $code): ?array
    {
        if (! str_contains($code, 'validate(')) {
            return null;
        }

        $properties = [];
        $required = [];
        $multipart = false;

        // Entradas de primer nivel de cada arreglo de reglas: 'campo' => reglas
        preg_match_all("/^\s*'([\w]+)'\s*=>\s*(.+)$/m", $code, $entries, PREG_SET_ORDER);

        foreach ($entries as [, $field, $rules]) {
            if (! preg_match('/required|nullable|sometimes|string|integer|numeric|boolean|array|uuid|date|url|Rule::|rules\(|email|max:|min:/', $rules)) {
                continue;
            }

            $schema = match (true) {
                str_contains($rules, 'rules(') || str_contains($rules, "'file'") || str_contains($rules, "'image'") => ['type' => 'string', 'format' => 'binary'],
                str_contains($rules, "'boolean'") => ['type' => 'boolean'],
                str_contains($rules, "'integer'") => ['type' => 'integer'],
                str_contains($rules, "'numeric'") => ['type' => 'number'],
                str_contains($rules, "'array'") => ['type' => 'array', 'items' => []],
                str_contains($rules, "'uuid'") => ['type' => 'string', 'format' => 'uuid'],
                str_contains($rules, "'date'") => ['type' => 'string', 'format' => 'date'],
                str_contains($rules, "'email'") => ['type' => 'string', 'format' => 'email'],
                default => ['type' => 'string'],
            };

            $multipart = $multipart || ($schema['format'] ?? null) === 'binary';

            if (str_contains($rules, "'nullable'")) {
                $schema['nullable'] = true;
            }

            $properties[$field] = $schema;

            if (preg_match("/'required'/", $rules)) {
                $required[] = $field;
            }
        }

        $schema = $properties === []
            ? ['type' => 'object', 'description' => 'Las reglas de este cuerpo no se pudieron deducir del código: ver el controlador.']
            : array_filter(['type' => 'object', 'properties' => $properties, 'required' => array_values(array_unique($required))]);

        return ['content' => [$multipart ? 'multipart/form-data' : 'application/json' => ['schema' => $schema]]];
    }

    /* ───────────────────────────── Diccionario de datos ───────────────────────────── */

    private function dataDictionary(): string
    {
        $columns = collect(DB::select(<<<'SQL'
            SELECT table_name, column_name, udt_name, character_maximum_length, numeric_precision, numeric_scale, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_schema = current_schema()
            ORDER BY table_name, ordinal_position
        SQL))->groupBy('table_name');

        $constraints = collect(DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS table_name, c.contype, pg_get_constraintdef(c.oid) AS definition
            FROM pg_constraint c
            WHERE c.connamespace = current_schema()::regnamespace AND c.contype IN ('p', 'f', 'u')
        SQL))->groupBy(fn ($row) => Str::afterLast($row->table_name, '.'));

        $secured = collect(DB::select(<<<'SQL'
            SELECT c.relname FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = current_schema()
            WHERE c.relkind = 'r' AND c.relrowsecurity
        SQL))->pluck('relname')->all();

        [$owners, $comments] = $this->migrationNotes();

        $lines = [
            '# Diccionario de datos',
            '',
            self::GENERATED,
            '',
            'Base de datos PostgreSQL. Todas las claves primarias `id` son UUID. El dinero se guarda como decimal, nunca como número de coma flotante. Las descripciones salen de los comentarios de las migraciones; las columnas comunes (identificador, academia, fechas) y las referencias a otras tablas llevan una descripción genérica. Una celda vacía significa que la columna no tiene comentario.',
            '',
            '**Aislamiento:** las tablas marcadas con «Aislada por academia» tienen `tenant_id` y seguridad por filas forzada en la base: una academia no puede leer filas de otra aunque la consulta lo intente.',
            '',
            '**Claves:** PK = clave primaria; FK = referencia a otra tabla; U = forma parte de una restricción de unicidad.',
            '',
        ];

        $byModule = $columns->keys()->groupBy(fn (string $table) => $owners[$table] ?? 'Framework')->sortKeys();

        foreach ($byModule as $module => $tables) {
            $lines[] = '## '.(self::MODULES[$module] ?? 'Tablas del framework (colas, caché, sesiones, tokens)');
            $lines[] = '';

            foreach ($tables->sort() as $table) {
                $keys = $this->keysOf($constraints->get($table, collect()));

                $lines[] = "### `{$table}`";
                $lines[] = '';
                $lines[] = in_array($table, $secured, true) ? 'Aislada por academia.' : 'Sin aislamiento por academia (dato de plataforma o del framework).';

                foreach ($keys['unique'] as $unique) {
                    $lines[] = "Único: `{$unique}`.";
                }

                $lines[] = '';
                $lines[] = '| Columna | Tipo | Clave | Oblig. | Descripción |';
                $lines[] = '| --- | --- | --- | :---: | --- |';

                foreach ($columns[$table] as $column) {
                    $key = array_filter([
                        in_array($column->column_name, $keys['primary'], true) ? 'PK' : null,
                        isset($keys['foreign'][$column->column_name]) ? 'FK '.$keys['foreign'][$column->column_name] : null,
                        in_array($column->column_name, $keys['uniqueColumns'], true) ? 'U' : null,
                    ]);

                    $lines[] = sprintf(
                        '| `%s` | %s | %s | %s | %s |',
                        $column->column_name,
                        $this->typeOf($column),
                        implode(', ', $key),
                        $column->is_nullable === 'NO' ? 'Sí' : 'No',
                        str_replace('|', '\\|', $comments[$table][$column->column_name]
                            ?? self::COMMON_COLUMNS[$column->column_name]
                            ?? (isset($keys['foreign'][$column->column_name]) ? 'Referencia a `'.Str::before($keys['foreign'][$column->column_name], '.').'`' : ''))
                    );
                }

                $lines[] = '';
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function typeOf(object $column): string
    {
        return match ($column->udt_name) {
            'varchar' => 'texto'.($column->character_maximum_length ? " ({$column->character_maximum_length})" : ''),
            'bpchar' => "texto fijo ({$column->character_maximum_length})",
            'text' => 'texto largo',
            'uuid' => 'UUID',
            'numeric' => "decimal ({$column->numeric_precision},{$column->numeric_scale})",
            'int2', 'int4', 'int8' => 'entero',
            'bool' => 'sí/no',
            'timestamptz' => 'fecha y hora',
            'timestamp' => 'fecha y hora (sin zona)',
            'date' => 'fecha',
            'jsonb', 'json' => 'JSON',
            default => $column->udt_name,
        };
    }

    /**
     * @param  iterable<object>  $constraints
     * @return array{primary: list<string>, foreign: array<string, string>, unique: list<string>, uniqueColumns: list<string>}
     */
    private function keysOf(iterable $constraints): array
    {
        $keys = ['primary' => [], 'foreign' => [], 'unique' => [], 'uniqueColumns' => []];
        $names = fn (string $list) => array_map(fn (string $name) => trim($name, ' "'), explode(',', $list));

        foreach ($constraints as $constraint) {
            if ($constraint->contype === 'p' && preg_match('/\((.+?)\)/', $constraint->definition, $match)) {
                $keys['primary'] = $names($match[1]);
            }

            if ($constraint->contype === 'f' && preg_match('/FOREIGN KEY \((.+?)\) REFERENCES (?:\w+\.)?"?(\w+)"?\((.+?)\)/', $constraint->definition, $match)) {
                $keys['foreign'][$names($match[1])[0]] = "{$match[2]}.{$names($match[3])[0]}";
            }

            if ($constraint->contype === 'u' && preg_match('/\((.+?)\)/', $constraint->definition, $match)) {
                $keys['unique'][] = implode(', ', $names($match[1]));
                $keys['uniqueColumns'] = [...$keys['uniqueColumns'], ...$names($match[1])];
            }
        }

        sort($keys['unique']);

        return $keys;
    }

    /**
     * De las migraciones: a qué módulo pertenece cada tabla y el comentario
     * escrito encima de cada columna.
     *
     * @return array{0: array<string, string>, 1: array<string, array<string, string>>}
     */
    private function migrationNotes(): array
    {
        $owners = [];
        $comments = [];

        foreach (glob(app_path('Modules/*/Database/Migrations/*.php')) ?: [] as $file) {
            $module = basename(dirname($file, 3));
            $table = null;
            $pending = [];

            foreach (file($file) ?: [] as $line) {
                $text = trim($line);

                if (preg_match("/Schema::(create|table)\('(\w+)'/", $text, $match)) {
                    $table = $match[2];
                    $pending = [];

                    if ($match[1] === 'create') {
                        $owners[$table] = $module;
                    }

                    continue;
                }

                if (str_starts_with($text, '//')) {
                    $pending[] = trim(substr($text, 2));

                    continue;
                }

                if ($table !== null && $pending !== [] && preg_match("/^\\\$table->\w+\('(\w+)'/", $text, $match)) {
                    $comments[$table][$match[1]] = implode(' ', $pending);
                }

                $pending = [];
            }
        }

        return [$owners, $comments];
    }

    /* ───────────────────────────── Catálogo de estados ───────────────────────────── */

    /**
     * Los valores fijos que guardan los modelos (estados, tipos, orígenes),
     * leídos de sus constantes.
     */
    private function stateCatalog(): string
    {
        $lines = [
            '# Catálogo de estados',
            '',
            self::GENERATED,
            '',
            'Valores fijos que el sistema guarda o calcula: estados, tipos, orígenes y medios. La API los devuelve con estos mismos códigos; la pantalla los traduce. El significado sale del comentario de cada constante en el código; vacío = sin comentario.',
            '',
        ];

        $byModule = [];

        foreach (glob(app_path('Modules/*/Domain/Models/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all("#(/\*\*(?:(?!\*/).)*\*/\s*)?public const ((?:STATUS|STATE|ACCESS|SOURCE|TYPE|ROLE|METHOD|STAGE|VISIBILITY|KIND|LEVEL|CATEGORY|PRIORITY|DISCOUNT|PRODUCT|CHANNEL)_\w+) = '([^']+)';#s", $source, $matches, PREG_SET_ORDER);

            if ($matches !== []) {
                $byModule[basename(dirname($file, 3))][basename($file, '.php')] = $matches;
            }
        }

        ksort($byModule);

        foreach ($byModule as $module => $models) {
            $lines[] = '## '.(self::MODULES[$module] ?? $module);
            $lines[] = '';

            foreach ($models as $model => $constants) {
                $lines[] = "### {$model}";
                $lines[] = '';
                $lines[] = '| Constante | Valor | Significado |';
                $lines[] = '| --- | --- | --- |';

                foreach ($constants as [, $comment, $name, $value]) {
                    $meaning = trim(preg_replace('#\s*\*+/?\s*|/\*\*#', ' ', $comment) ?? '');
                    $lines[] = "| `{$name}` | `{$value}` | ".str_replace('|', '\\|', preg_replace('/\s+/', ' ', $meaning) ?? '').' |';
                }

                $lines[] = '';
            }
        }

        return implode("\n", $lines)."\n";
    }

    /* ───────────────────────────── Catálogo de errores ───────────────────────────── */

    /**
     * Cada código de error de la API con la respuesta HTTP y los mensajes
     * con los que el código lo usa.
     */
    private function errorCatalog(): string
    {
        $uses = [];
        $fallback = [];

        foreach ($this->phpFiles(app_path()) as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all("/ErrorCode::(\w+)\s*,\s*(?:\\$\w+\s*\?\s*)?(['\"])((?:(?!\\2).)*)\\2/s", $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $match) {
                // El código HTTP es el argumento que sigue al mensaje (que puede venir concatenado)
                $after = substr($source, $match[0][1] + strlen($match[0][0]), 300);
                $status = preg_match('/^(?:\s*\.[^,;]*)?\s*,\s*([45]\d\d)\b/', $after, $found) ? $found[1] : null;

                $uses[$match[1][0]][] = ['message' => $match[3][0], 'status' => $status];
            }

            // Cuando el mensaje se arma antes (una variable), queda al menos el código HTTP
            preg_match_all('/ErrorCode::(\w+)\s*,\s*\$\w+\s*,\s*([45]\d\d)\b/', $source, $variables, PREG_SET_ORDER);

            foreach ($variables as [, $name, $status]) {
                $fallback[$name][] = $status;
            }
        }

        $lines = [
            '# Catálogo de errores',
            '',
            self::GENERATED,
            '',
            'Toda respuesta de error de la API tiene la misma forma: `code` (estable, de este catálogo), `message` (texto para la persona), `details` (datos del error; en una validación, el mensaje de cada campo) y `trace_id` (para ubicar la petición en los registros).',
            '',
            'El `code` es lo que debe usar un programa; el `message` puede cambiar de redacción. Los mensajes de la tabla son ejemplos tomados del código (hasta tres por código; `{…}` marca un dato que varía).',
            '',
            '| Código | HTTP | Mensajes de ejemplo |',
            '| --- | --- | --- |',
        ];

        foreach (ErrorCode::cases() as $code) {
            $found = collect($uses[$code->name] ?? []);
            $statuses = self::GENERAL_ERRORS[$code->name] ?? $found->pluck('status')->merge($fallback[$code->name] ?? [])->filter()->unique()->sort()->implode(', ');
            $messages = $found->pluck('message')
                ->map(fn (string $message) => preg_replace('/\{?\$[\w>\-\[\]\'()]+\}?/', '{…}', $message))
                ->unique()->take(3)
                ->map(fn (string $message) => '«'.str_replace('|', '\\|', trim($message)).'»')
                ->implode('<br>');

            $lines[] = "| `{$code->value}` | ".($statuses ?: '—').' | '.($messages ?: (isset(self::GENERAL_ERRORS[$code->name]) ? 'Lo produce el manejo general de errores.' : 'El mensaje se arma según el caso: ver dónde se usa el código.')).' |';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
