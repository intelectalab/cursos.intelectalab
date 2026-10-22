<?php

namespace App\Modules\Tenancy\Domain;

/**
 * Los correos automáticos del sistema: qué es cada uno, cuándo sale, a
 * quién, qué datos variables admite y su texto original. Las academias y la
 * plataforma pueden cambiar el asunto, el mensaje y el botón; el diseño
 * (logo, colores, saludo y pie) es fijo.
 *
 * Reglas del texto:
 * - Los datos variables van entre llaves: {curso}. Se reemplazan al enviar.
 * - Los párrafos se separan con una línea en blanco.
 * - Si un dato no aplica (vacío), el párrafo que lo usa no se envía.
 * - Los datos «esenciales» no se pueden quitar del mensaje.
 */
final class EmailCatalog
{
    /** Correos que manda cada academia a sus personas */
    public const SCOPE_ACADEMY = 'academy';

    /** Correos propios de la plataforma (pagos de plan) */
    public const SCOPE_PLATFORM = 'platform';

    /** Datos que existen en todos los correos de una academia */
    private const ACADEMY_COMMON = [
        'nombre' => ['Nombre de quien recibe el correo', 'Ana'],
        'academia' => ['Nombre de la academia', 'Academia Andina'],
    ];

    /** Datos que existen en todos los correos de la plataforma */
    private const PLATFORM_COMMON = [
        'academia' => ['Nombre de la academia de la orden', 'Academia Andina'],
        'orden' => ['Código de la orden de plan', 'P-0007'],
        'plan' => ['Nombre del plan', 'Profesional'],
        'importe' => ['Importe de la orden', 'S/ 299.00'],
    ];

    /**
     * @return array<string, array{scope: string, group: string, name: string, when: string, to: string, required: bool, variables: array<string, array{0: string, 1: string}>, essential: list<string>, subject: string, body: string, button: ?string}>
     */
    public static function all(): array
    {
        return array_map(fn (array $email) => $email + [
            'variables' => [],
            'essential' => [],
            'required' => false,
            'button' => null,
        ], self::DEFINITIONS);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        $email = self::all()[$key] ?? null;

        if ($email === null) {
            return null;
        }

        $common = $email['scope'] === self::SCOPE_ACADEMY ? self::ACADEMY_COMMON : self::PLATFORM_COMMON;
        $email['variables'] = $common + $email['variables'];

        return $email;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function ofScope(string $scope): array
    {
        return array_filter(self::all(), fn (array $email) => $email['scope'] === $scope);
    }

    /**
     * Los valores de ejemplo de un correo, para la vista previa y la prueba.
     *
     * @return array<string, string>
     */
    public static function examples(string $key): array
    {
        return array_map(fn (array $variable) => $variable[1], self::get($key)['variables'] ?? []);
    }

    private const DEFINITIONS = [
        // ── Cuentas ──────────────────────────────────────────────
        'cuenta_nueva' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Cuentas',
            'name' => 'Cuenta creada por el personal',
            'when' => 'Cuando alguien del personal crea una cuenta en «Usuarios» o «Alumnos».',
            'to' => 'La persona de la cuenta nueva',
            'required' => true,
            'variables' => ['correo' => ['Correo con el que entra', 'ana.torres@correo.com']],
            'essential' => ['correo'],
            'subject' => 'Tu cuenta está lista',
            'body' => "Te crearon una cuenta en {academia}. Entra con tu correo ({correo}) y la contraseña que te entregaron.\n\nSi no la tienes, usa «¿Olvidaste tu contraseña?» en la pantalla de acceso para crear una.",
            'button' => 'Iniciar sesión',
        ],
        'cuenta_importada' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Cuentas',
            'name' => 'Cuenta creada al importar personas',
            'when' => 'Cuando se importa una persona nueva desde Excel: su contraseña temporal es su número de documento.',
            'to' => 'La persona importada',
            'required' => true,
            'variables' => ['correo' => ['Correo con el que entra', 'ana.torres@correo.com']],
            'essential' => ['correo'],
            'subject' => 'Tu cuenta está lista',
            'body' => "Te crearon una cuenta en {academia}. Entra con tu correo ({correo}); tu contraseña temporal es tu número de documento.\n\nAl entrar por primera vez el sistema te pedirá cambiarla.",
            'button' => 'Iniciar sesión',
        ],
        'cuenta_empresa' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Cuentas',
            'name' => 'Cuenta de un trabajador de empresa',
            'when' => 'Cuando el responsable de una empresa registra a un trabajador para darle cursos.',
            'to' => 'El trabajador',
            'required' => true,
            'variables' => ['correo' => ['Correo con el que entra', 'ana.torres@empresa.com']],
            'essential' => ['correo'],
            'subject' => 'Tu cuenta está lista',
            'body' => "Tu empresa te registró para llevar cursos en {academia}. Entra con tu correo ({correo}) y la contraseña que te entregaron.\n\nSi no la tienes, usa «¿Olvidaste tu contraseña?» en la pantalla de acceso para crear una.",
            'button' => 'Iniciar sesión',
        ],
        'recuperar_contrasena' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Cuentas',
            'name' => 'Recuperar la contraseña',
            'when' => 'Cuando alguien pulsa «¿Olvidaste tu contraseña?». El botón lleva al enlace para crear una nueva.',
            'to' => 'Quien pidió recuperar su contraseña',
            'required' => true,
            'variables' => ['minutos' => ['Minutos que dura el enlace', '60']],
            'essential' => ['minutos'],
            'subject' => 'Restablece tu contraseña',
            'body' => "Recibimos una solicitud para restablecer tu contraseña en {academia}.\n\nEl enlace vence en {minutos} minutos.\n\nSi no hiciste esta solicitud, ignora este mensaje: tu contraseña no cambiará.",
            'button' => 'Crear una contraseña nueva',
        ],

        // ── Cursos ───────────────────────────────────────────────
        'matricula' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Cursos',
            'name' => 'Matrícula hecha por el personal',
            'when' => 'Cuando el personal matricula a alguien en un curso (a mano, por grupo, al importar o por licencia de empresa). Quien compra recibe en cambio el aviso de su pago.',
            'to' => 'El alumno',
            'variables' => [
                'curso' => ['Nombre del curso', 'Excel para finanzas'],
                'vigencia' => ['Fecha hasta la que tiene acceso (vacío si no vence)', '30/04/2027'],
            ],
            'subject' => 'Ya tienes acceso a «{curso}»',
            'body' => "Te matricularon en el curso «{curso}».\n\nTu acceso está disponible hasta el {vigencia}.",
            'button' => 'Entrar al curso',
        ],
        'anuncio_curso' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Cursos',
            'name' => 'Anuncio del curso',
            'when' => 'Cuando el docente o la academia publica un anuncio en un curso y marca «Avisar también por correo».',
            'to' => 'Los alumnos con acceso al curso',
            'variables' => [
                'curso' => ['Nombre del curso', 'Excel para finanzas'],
                'titulo' => ['Título del anuncio', 'Cambio de horario de la clase en vivo'],
                'mensaje' => ['Texto del anuncio', 'Esta semana la clase será el viernes a las 19:00.'],
            ],
            'essential' => ['mensaje'],
            'subject' => '{titulo} · {curso}',
            'body' => "Hay un anuncio nuevo en «{curso}»:\n\n{mensaje}",
            'button' => 'Ir al curso',
        ],
        'acceso_por_vencer' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Cursos',
            'name' => 'Recordatorio: el acceso a un curso vence pronto',
            'when' => 'Cada mañana, a quien le quedan 7 días de acceso a un curso que no terminó.',
            'to' => 'El alumno',
            'variables' => [
                'curso' => ['Nombre del curso', 'Excel para finanzas'],
                'fecha_fin' => ['Último día de acceso', '28/10/2026'],
                'dias' => ['Días que faltan', '7'],
            ],
            'subject' => 'Tu acceso a «{curso}» vence en {dias} días',
            'body' => "Tu acceso al curso «{curso}» termina el {fecha_fin}.\n\nAprovecha estos días para terminarlo. Si necesitas más tiempo, comunícate con la academia.",
            'button' => 'Continuar el curso',
        ],
        'certificado' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Cursos',
            'name' => 'Certificado emitido',
            'when' => 'Cuando se emite un certificado, al completar el curso o a mano.',
            'to' => 'El alumno',
            'variables' => [
                'curso' => ['Nombre del curso', 'Excel para finanzas'],
                'codigo' => ['Código de verificación del certificado', 'CERT-7K2M9Q'],
            ],
            'essential' => ['codigo'],
            'subject' => 'Tu certificado de «{curso}» está listo',
            'body' => "Completaste el curso «{curso}» y ya puedes descargar tu certificado.\n\nCódigo de verificación: {codigo}. Cualquiera puede comprobarlo en la página de verificación.",
            'button' => 'Ver mi certificado',
        ],

        // ── Compras y pagos ──────────────────────────────────────
        'inscripcion_gratuita' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Compras y pagos',
            'name' => 'Inscripción en un curso gratuito',
            'when' => 'Cuando alguien se inscribe en un curso o paquete gratuito desde el catálogo.',
            'to' => 'Quien se inscribió',
            'variables' => ['curso' => ['Curso o paquete', 'Comunicación efectiva']],
            'subject' => 'Ya estás inscrito en «{curso}»',
            'body' => 'Tu inscripción quedó confirmada: ya puedes empezar.',
            'button' => 'Ir a mis cursos',
        ],
        'orden_recibida' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Compras y pagos',
            'name' => 'Orden de compra recibida',
            'when' => 'Cuando alguien compra un curso o paquete y la orden queda pendiente de pago.',
            'to' => 'Quien compró',
            'variables' => [
                'orden' => ['Número de la orden', 'N.º 00042'],
                'curso' => ['Curso o paquete comprado', 'Excel para finanzas'],
                'importe' => ['Total a pagar', 'S/ 120.00'],
                'fecha_limite' => ['Fecha y hora en que vence la orden', '23/10/2026, 18:30'],
            ],
            'essential' => ['orden'],
            'subject' => 'Recibimos tu orden {orden}',
            'body' => "Tu compra de «{curso}» por {importe} está pendiente de pago.\n\nTienes hasta el {fecha_limite} para pagarla; después la orden vence.",
            'button' => 'Ver cómo pagar',
        ],
        'pago_confirmado' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Compras y pagos',
            'name' => 'Pago confirmado',
            'when' => 'Cuando se confirma el pago completo de una orden (a mano o por la pasarela).',
            'to' => 'Quien compró',
            'variables' => [
                'orden' => ['Número de la orden', 'N.º 00042'],
                'importe' => ['Importe pagado', 'S/ 120.00'],
            ],
            'subject' => 'Pago confirmado: ya tienes acceso',
            'body' => "Recibimos tu pago de {importe} por la orden {orden}.\n\nTu matrícula está activa: el curso ya está en «Mis cursos».",
            'button' => 'Ir a mis cursos',
        ],
        'primera_cuota_pagada' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Compras y pagos',
            'name' => 'Primera cuota pagada',
            'when' => 'Cuando se paga la primera cuota de una venta en cuotas: con ella recibe el acceso.',
            'to' => 'Quien compró',
            'variables' => [
                'orden' => ['Número de la orden', 'N.º 00042'],
                'cuota' => ['Número de la cuota pagada', '1'],
                'importe' => ['Importe pagado', 'S/ 60.00'],
                'siguiente_importe' => ['Importe de la siguiente cuota', 'S/ 60.00'],
                'siguiente_vencimiento' => ['Vencimiento de la siguiente cuota', '20/11/2026'],
            ],
            'subject' => 'Recibimos el pago de tu cuota {cuota}',
            'body' => "Registramos tu pago de {importe} por la cuota {cuota} de la orden {orden}.\n\nYa tienes acceso al curso: está en «Mis cursos».\n\nTu siguiente cuota es de {siguiente_importe} y vence el {siguiente_vencimiento}.",
            'button' => 'Ver mi orden',
        ],
        'cuota_pagada' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Compras y pagos',
            'name' => 'Cuota intermedia pagada',
            'when' => 'Cuando se paga una cuota que no es la primera ni la última.',
            'to' => 'Quien compró',
            'variables' => [
                'orden' => ['Número de la orden', 'N.º 00042'],
                'cuota' => ['Número de la cuota pagada', '2'],
                'importe' => ['Importe pagado', 'S/ 60.00'],
                'siguiente_importe' => ['Importe de la siguiente cuota', 'S/ 60.00'],
                'siguiente_vencimiento' => ['Vencimiento de la siguiente cuota', '20/12/2026'],
            ],
            'subject' => 'Recibimos el pago de tu cuota {cuota}',
            'body' => "Registramos tu pago de {importe} por la cuota {cuota} de la orden {orden}.\n\nTu siguiente cuota es de {siguiente_importe} y vence el {siguiente_vencimiento}.",
            'button' => 'Ver mi orden',
        ],
        'ultima_cuota_pagada' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Compras y pagos',
            'name' => 'Última cuota pagada',
            'when' => 'Cuando se paga la última cuota: la compra queda pagada por completo.',
            'to' => 'Quien compró',
            'variables' => [
                'orden' => ['Número de la orden', 'N.º 00042'],
                'cuota' => ['Número de la cuota pagada', '3'],
                'importe' => ['Importe pagado', 'S/ 60.00'],
            ],
            'subject' => 'Recibimos el pago de tu cuota {cuota}',
            'body' => "Registramos tu pago de {importe} por la cuota {cuota} de la orden {orden}.\n\nCon esta cuota terminaste de pagar tu compra. ¡Gracias!",
            'button' => 'Ver mi orden',
        ],
        'recordatorio_cuota' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Compras y pagos',
            'name' => 'Recordatorio: una cuota vence pronto',
            'when' => 'Cada mañana, 3 días antes de que venza una cuota sin pagar.',
            'to' => 'Quien compró',
            'variables' => [
                'cuota' => ['Número de la cuota', '2'],
                'importe' => ['Importe de la cuota', 'S/ 60.00'],
                'vencimiento' => ['Fecha en que vence', '24/10/2026'],
                'dias' => ['Días que faltan', '3'],
            ],
            'subject' => 'Tu cuota {cuota} vence en {dias} días',
            'body' => "La cuota {cuota} de tu compra, por {importe}, vence el {vencimiento}.\n\nPuedes pagarla desde tu orden, en línea o con los datos de pago de la academia.",
            'button' => 'Pagar mi cuota',
        ],
        'cuota_vencida' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Compras y pagos',
            'name' => 'Aviso: una cuota venció',
            'when' => 'El día siguiente al vencimiento de una cuota sin pagar.',
            'to' => 'Quien compró',
            'variables' => [
                'cuota' => ['Número de la cuota', '2'],
                'importe' => ['Importe de la cuota', 'S/ 60.00'],
                'vencimiento' => ['Fecha en que venció', '20/10/2026'],
            ],
            'subject' => 'Tu cuota {cuota} venció ayer',
            'body' => "La cuota {cuota} de tu compra, por {importe}, venció el {vencimiento} y aún no registramos tu pago.\n\nPágala cuanto antes para mantener tu acceso al curso. Si ya pagaste, ignora este mensaje.",
            'button' => 'Pagar mi cuota',
        ],
        'reembolso' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Compras y pagos',
            'name' => 'Compra reembolsada',
            'when' => 'Cuando el Administrador reembolsa una compra.',
            'to' => 'Quien compró',
            'variables' => [
                'orden' => ['Número de la orden', 'N.º 00042'],
                'importe' => ['Importe devuelto', 'S/ 120.00'],
            ],
            'subject' => 'Tu compra fue reembolsada',
            'body' => "Se te devolvió {importe} de la orden {orden}.\n\nSi pagaste en línea, el dinero vuelve al medio con el que pagaste; el plazo depende de tu banco.\n\nYa no tienes acceso al curso de esa compra.",
            'button' => 'Ver mi orden',
        ],

        // ── Atención ─────────────────────────────────────────────
        'horario_por_confirmar' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Docentes',
            'name' => 'Horario por confirmar',
            'when' => 'Cuando el personal arma o cambia el horario de un docente en «Horarios».',
            'to' => 'El docente',
            'variables' => [
                'desde' => ['Fecha desde la que vale el horario', '03/11/2026'],
                'enviado_por' => ['Quién armó el horario', 'Carla Rojas'],
            ],
            'subject' => 'Tienes un horario por confirmar',
            'body' => "{enviado_por} preparó tu horario semanal, que vale desde el {desde}.\n\nRevísalo y confírmalo, o cuéntanos qué hay que cambiar.",
            'button' => 'Ver mi horario',
        ],
        'horario_observado' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Docentes',
            'name' => 'Horario observado por el docente',
            'when' => 'Cuando un docente pide cambios en el horario que le enviaron.',
            'to' => 'Quien armó el horario',
            'variables' => [
                'docente' => ['Nombre del docente', 'Rosa Quispe'],
                'observacion' => ['Lo que pide cambiar el docente', 'Los martes no puedo antes de las 10:00.'],
            ],
            'essential' => ['observacion'],
            'subject' => '{docente} pidió cambios en su horario',
            'body' => "{docente} revisó el horario que le enviaste y pide esto:\n\n«{observacion}»",
            'button' => 'Ver el horario',
        ],
        'respuesta_soporte' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Atención',
            'name' => 'Respuesta a una consulta de soporte',
            'when' => 'Cuando el personal responde una consulta (no se avisa de las notas internas).',
            'to' => 'Quien hizo la consulta',
            'variables' => [
                'numero' => ['Número de la consulta', '#0012'],
                'asunto' => ['Asunto de la consulta', 'No carga el video'],
            ],
            'subject' => 'Respondimos tu consulta {numero}',
            'body' => 'Hay una respuesta nueva en tu consulta «{asunto}».',
            'button' => 'Ver la respuesta',
        ],
        'reclamo_registrado' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Atención',
            'name' => 'Constancia del Libro de Reclamaciones',
            'when' => 'Cuando alguien registra un reclamo o una queja. Es obligatorio por ley.',
            'to' => 'Quien registró la hoja (puede no tener cuenta)',
            'required' => true,
            'variables' => [
                'tipo' => ['«reclamo» o «queja»', 'reclamo'],
                'codigo' => ['Código de la hoja', 'LR-2026-000012'],
                'fecha_limite' => ['Fecha límite para responder', '10/11/2026'],
            ],
            'essential' => ['codigo'],
            'subject' => 'Constancia de tu {tipo} {codigo}',
            'body' => "Registramos tu {tipo} en el Libro de Reclamaciones con el código {codigo}.\n\nTe responderemos a más tardar el {fecha_limite}.\n\nGuarda este código: con él y tu número de documento puedes consultar el estado y la respuesta.",
            'button' => 'Consultar mi hoja',
        ],
        'reclamo_respondido' => [
            'scope' => self::SCOPE_ACADEMY,
            'group' => 'Atención',
            'name' => 'Respuesta del Libro de Reclamaciones',
            'when' => 'Cuando la academia responde un reclamo o una queja.',
            'to' => 'Quien registró la hoja',
            'required' => true,
            'variables' => ['codigo' => ['Código de la hoja', 'LR-2026-000012']],
            'essential' => ['codigo'],
            'subject' => 'Respondimos tu hoja {codigo}',
            'body' => "Ya está la respuesta a tu hoja {codigo} del Libro de Reclamaciones.\n\nPara leerla, consulta tu hoja con el código y tu número de documento.",
            'button' => 'Ver la respuesta',
        ],

        // ── Plataforma: avisos a la plataforma ───────────────────
        'plan_pedido' => [
            'scope' => self::SCOPE_PLATFORM,
            'group' => 'Avisos a la plataforma',
            'name' => 'Una academia pidió un plan',
            'when' => 'Cuando una academia genera una orden de plan en «Mi plan».',
            'to' => 'Los administradores de la plataforma',
            'subject' => 'Nueva orden de plan {orden} · {academia}',
            'body' => "«{academia}» pidió el plan «{plan}» por {importe}.\n\nAún no informó su pago. Cuando lo recibas, confírmalo en la consola, sección Pagos.",
        ],
        'plan_pago_informado' => [
            'scope' => self::SCOPE_PLATFORM,
            'group' => 'Avisos a la plataforma',
            'name' => 'Una academia informó su pago',
            'when' => 'Cuando una academia informa que pagó su orden de plan.',
            'to' => 'Los administradores de la plataforma',
            'variables' => [
                'referencia' => ['Cómo ubicar el pago, según la academia (vacío si no lo indicó)', 'Operación 004512 del BCP'],
                'constancia' => ['«Adjuntó su constancia» si subió una (vacío si no)', 'Adjuntó su constancia'],
            ],
            'subject' => 'Pago informado en la orden {orden} · {academia}',
            'body' => "«{academia}» informó que pagó el plan «{plan}» ({importe}).\n\nCómo ubicar el pago: {referencia}\n\n{constancia}: puedes verla en la consola.\n\nRevísalo y confírmalo en la consola, sección Pagos.",
        ],
        'plan_cobro_revision' => [
            'scope' => self::SCOPE_PLATFORM,
            'group' => 'Avisos a la plataforma',
            'name' => 'Cobro de plan por revisar',
            'when' => 'Cuando la pasarela cobró un plan y el pago no se pudo aplicar a su orden.',
            'to' => 'Los administradores de la plataforma',
            'variables' => ['motivo' => ['Por qué no se aplicó', 'El importe cobrado no coincide con la orden.']],
            'subject' => 'Cobro por revisar en la orden {orden} · {academia}',
            'body' => "La pasarela cobró un pago que no se aplicó a la orden. {motivo}\n\nRevísalo en la cuenta de la pasarela y en la consola, sección Pagos.",
        ],

        // ── Plataforma: avisos a las academias ───────────────────
        'plan_confirmado' => [
            'scope' => self::SCOPE_PLATFORM,
            'group' => 'Avisos a las academias',
            'name' => 'Pago de plan confirmado',
            'when' => 'Cuando la plataforma confirma el pago de una orden de plan.',
            'to' => 'Quien pidió el plan y los administradores de la academia',
            'variables' => ['vigencia' => ['Fecha hasta la que queda vigente el plan', '20/11/2026']],
            'subject' => 'Tu pago fue confirmado: plan «{plan}» vigente',
            'body' => "IntelectaLab confirmó el pago de tu orden {orden}.\n\nTu plan está vigente hasta el {vigencia}.",
        ],
        'plan_cancelado' => [
            'scope' => self::SCOPE_PLATFORM,
            'group' => 'Avisos a las academias',
            'name' => 'Orden de plan cancelada',
            'when' => 'Cuando la plataforma cancela una orden de plan.',
            'to' => 'Quien pidió el plan y los administradores de la academia',
            'variables' => ['motivo' => ['Motivo de la cancelación (vacío si no se indicó)', 'No se recibió el pago.']],
            'subject' => 'Tu orden de plan {orden} fue cancelada',
            'body' => "IntelectaLab canceló tu orden del plan «{plan}».\n\nMotivo: {motivo}\n\nPuedes pedir otro plan en Configuración → Mi plan.",
        ],
        'plan_vencido' => [
            'scope' => self::SCOPE_PLATFORM,
            'group' => 'Avisos a las academias',
            'name' => 'Orden de plan vencida',
            'when' => 'Cuando una orden de plan pasa su plazo sin que se informe un pago.',
            'to' => 'Quien pidió el plan y los administradores de la academia',
            'subject' => 'Tu orden de plan {orden} venció',
            'body' => "La orden del plan «{plan}» pasó su plazo sin que se informara un pago.\n\nSi aún quieres el plan, pídelo de nuevo en Configuración → Mi plan.",
        ],
        'plan_confirmacion_anulada' => [
            'scope' => self::SCOPE_PLATFORM,
            'group' => 'Avisos a las academias',
            'name' => 'Confirmación de pago anulada',
            'when' => 'Cuando la plataforma deshace la confirmación de un pago de plan.',
            'to' => 'Quien pidió el plan y los administradores de la academia',
            'variables' => ['motivo' => ['Motivo', 'Se confirmó por error.']],
            'subject' => 'Se anuló la confirmación de tu orden {orden}',
            'body' => "IntelectaLab anuló la confirmación del pago de tu orden del plan «{plan}»: tu plan volvió a como estaba antes.\n\nMotivo: {motivo}\n\nLa orden quedó otra vez pendiente de pago. Revísala en Configuración → Mi plan.",
        ],
        'plan_reembolsado' => [
            'scope' => self::SCOPE_PLATFORM,
            'group' => 'Avisos a las academias',
            'name' => 'Pago de plan devuelto',
            'when' => 'Cuando la plataforma devuelve el pago en línea de un plan.',
            'to' => 'Quien pidió el plan y los administradores de la academia',
            'variables' => ['motivo' => ['Motivo', 'La academia pidió anular la compra.']],
            'subject' => 'Se devolvió el pago de tu orden {orden}',
            'body' => "IntelectaLab devolvió el pago de tu orden del plan «{plan}» ({importe}): tu plan volvió a como estaba antes.\n\nMotivo: {motivo}\n\nEl dinero vuelve al medio con el que pagaste; el plazo depende de la pasarela y de tu banco.",
        ],
    ];
}
