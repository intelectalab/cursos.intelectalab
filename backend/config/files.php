<?php

return [

    /*
    | Disco donde se guardan los archivos de las organizaciones. "local" los
    | deja en storage/app/private; para un servicio compatible con S3 basta
    | con cambiar esta variable y configurar el disco "s3" (Maestro: el
    | proveedor de almacenamiento está pendiente de definición).
    */
    'disk' => env('FILES_DISK', 'local'),

    // Tamaño máximo por tipo de archivo, en kilobytes
    'limits' => [
        'image' => (int) env('FILES_IMAGE_MAX_KB', 2048),
        'document' => (int) env('FILES_DOCUMENT_MAX_KB', 51200),
    ],

    /*
    | Usos permitidos (RN-016: todo upload se valida por tipo, tamaño y MIME).
    | Un archivo solo se acepta para un uso declarado aquí. Los públicos se
    | sirven sin sesión; los privados, solo por la ruta del módulo dueño.
    | SVG queda fuera a propósito: puede traer scripts.
    */
    'purposes' => [
        'tenant_logo' => [
            'visibility' => 'public',
            'limit' => 'image',
            'extensions' => ['png', 'jpg', 'jpeg', 'webp'],
        ],
        'course_cover' => [
            'visibility' => 'public',
            'limit' => 'image',
            'extensions' => ['png', 'jpg', 'jpeg', 'webp'],
        ],
        // Entrega de una tarea: documentos, imágenes o un comprimido
        'task_submission' => [
            'visibility' => 'private',
            'limit' => 'document',
            'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg', 'zip'],
        ],
        // Firma que aparece en los certificados: solo la ve el personal y el PDF
        'certificate_signature' => [
            'visibility' => 'private',
            'limit' => 'image',
            'extensions' => ['png', 'jpg', 'jpeg'],
        ],
        // Fondo del certificado: el diseño propio de la organización, a hoja completa
        'certificate_background' => [
            'visibility' => 'private',
            'limit' => 'image',
            'extensions' => ['png', 'jpg', 'jpeg'],
        ],
        // Adjunto de un mensaje de soporte: capturas y documentos
        'ticket_attachment' => [
            'visibility' => 'private',
            'limit' => 'document',
            'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'webp', 'zip'],
        ],
        // Imágenes insertadas en las páginas de la organización
        'page_media' => [
            'visibility' => 'public',
            'limit' => 'image',
            'extensions' => ['png', 'jpg', 'jpeg', 'webp'],
        ],
        'course_material' => [
            'visibility' => 'private',
            'limit' => 'document',
            'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
        ],
    ],

];
