<?php

namespace App\Modules\Certificates\Domain\Models;

use App\Modules\Files\Domain\Models\File;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateTemplate extends Model
{
    use BelongsToTenant, HasUuids;

    public const POSITIONS = ['LEFT', 'CENTER', 'RIGHT'];

    public const HEIGHTS = ['TOP', 'MIDDLE', 'LOW'];

    public const SIZES = ['S', 'M', 'L'];

    /** El diseño de siempre: es lo que vale mientras la organización no cambie nada */
    public const DEFAULT_DESIGN = [
        // Bloque de texto: alineación y a qué altura empieza
        'text_align' => 'CENTER',
        'text_top' => 'TOP',
        'name_size' => 'M',
        // Títulos y curso, nombre del alumno, y texto secundario
        'primary_color' => '#0a2540',
        'accent_color' => '#0c4a8a',
        'text_color' => '#475569',
        // Con un fondo propio que ya trae el logo o la palabra "Certificado", se ocultan
        'show_logo' => true,
        'show_organization' => true,
        'show_title' => true,
        // Zona inferior: la firma y el código con su QR, cada uno en un tercio
        'signature_position' => 'LEFT',
        'qr_position' => 'RIGHT',
    ];

    public const DEFAULT_NAME = 'Plantilla principal';

    protected $fillable = [
        'tenant_id',
        'name',
        'is_default',
        'signer_name',
        'signer_title',
        'signature_file_id',
        'background_file_id',
        'design',
        'layout',
    ];

    protected function casts(): array
    {
        return [
            'design' => 'array',
            'layout' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(File::class, 'signature_file_id');
    }

    public function background(): BelongsTo
    {
        return $this->belongsTo(File::class, 'background_file_id');
    }

    /**
     * El diseño tal como está ahora, para dejarlo fijo en un certificado al
     * emitirlo: lo que cambie después en la plantilla no lo alcanza.
     *
     * @return array<string, mixed>
     */
    public function frozen(): array
    {
        return [
            'template_id' => $this->id,
            'template_name' => $this->name ?? self::DEFAULT_NAME,
            'layout' => $this->layout,
            'design' => $this->resolvedDesign(),
            'background_file_id' => $this->background_file_id,
            'signature_file_id' => $this->signature_file_id,
        ];
    }

    /**
     * Diseño vigente: lo guardado sobre los valores por defecto.
     *
     * @return array<string, mixed>
     */
    public function resolvedDesign(): array
    {
        return array_intersect_key($this->design ?? [], self::DEFAULT_DESIGN) + self::DEFAULT_DESIGN;
    }
}
