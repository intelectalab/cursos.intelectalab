@php
    // Geometría en milímetros sobre una hoja A4 horizontal (297 × 210)
    $block = ['CENTER' => ['left' => 30, 'width' => 237, 'align' => 'center'], 'LEFT' => ['left' => 25, 'width' => 175, 'align' => 'left'], 'RIGHT' => ['left' => 97, 'width' => 175, 'align' => 'right']][$design['text_align']];
    $top = ['TOP' => 30, 'MIDDLE' => 50, 'LOW' => 70][$design['text_top']];
    $nameSize = ['S' => 26, 'M' => 32, 'L' => 40][$design['name_size']];
    $signatureLeft = ['LEFT' => 30, 'CENTER' => 103.5, 'RIGHT' => 177][$design['signature_position']];
    $verifyLeft = ['LEFT' => 25, 'CENTER' => 98, 'RIGHT' => 160][$design['qr_position']];
    $ruleMargin = ['center' => '4mm auto 0 auto', 'left' => '4mm 0 0 0', 'right' => '4mm 0 0 auto'][$block['align']];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Certificado {{ $certificate->verification_code }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; color: {{ $design['primary_color'] }}; }
        .sheet { position: relative; width: 297mm; height: 210mm; overflow: hidden; }
        .background { position: absolute; left: 0; top: 0; width: 297mm; height: 210mm; }
        .band { position: absolute; left: 0; top: 0; width: 297mm; height: 12mm; background: #0a2540; }
        .band-accent { position: absolute; left: 0; top: 12mm; width: 297mm; height: 1.6mm; background: #38bdf8; }
        .frame { position: absolute; left: 10mm; top: 20mm; width: 275mm; height: 180mm; border: 0.6mm solid #0a2540; }
        .frame-inner { position: absolute; left: 12.5mm; top: 22.5mm; width: 270.8mm; height: 175.8mm; border: 0.2mm solid #38bdf8; }
        .content { position: absolute; left: {{ $block['left'] }}mm; top: {{ $top }}mm; width: {{ $block['width'] }}mm; text-align: {{ $block['align'] }}; }
        .logo { height: 18mm; }
        .organization { margin-top: 2mm; font-size: 12pt; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase; color: {{ $design['primary_color'] }}; }
        .title { margin-top: 7mm; font-family: Times, serif; font-size: 40pt; letter-spacing: 6pt; color: {{ $design['primary_color'] }}; }
        .lead { margin-top: 5mm; font-size: 12pt; color: {{ $design['text_color'] }}; }
        .student { margin-top: 4mm; font-family: Times, serif; font-size: {{ $nameSize }}pt; font-weight: bold; color: {{ $design['accent_color'] }}; }
        .document { margin-top: 1mm; font-size: 10pt; color: {{ $design['text_color'] }}; }
        .rule { width: 60mm; height: 0.5mm; margin: {{ $ruleMargin }}; background: {{ $background ? $design['accent_color'] : '#38bdf8' }}; }
        .course { margin-top: 3mm; font-size: 20pt; font-weight: bold; color: {{ $design['primary_color'] }}; }
        .details { margin-top: 3mm; font-size: 11pt; color: {{ $design['text_color'] }}; }
        .signature { position: absolute; left: {{ $signatureLeft }}mm; top: 153mm; width: 90mm; text-align: center; }
        .signature img { height: 17mm; }
        .signature .blank { height: 17mm; }
        .signature .line { width: 70mm; height: 0.3mm; margin: 1mm auto 1.5mm auto; background: {{ $design['primary_color'] }}; }
        .signature .name { font-size: 11pt; font-weight: bold; color: {{ $design['primary_color'] }}; }
        .signature .role { font-size: 9.5pt; color: {{ $design['text_color'] }}; }
        .verify { position: absolute; left: {{ $verifyLeft }}mm; top: 153mm; width: 110mm; }
        .verify td { vertical-align: middle; }
        .verify img { width: 30mm; height: 30mm; }
        .verify .label { font-size: 8.5pt; color: {{ $design['text_color'] }}; }
        .verify .code { margin: 1mm 0; font-family: Courier, monospace; font-size: 13pt; font-weight: bold; color: {{ $design['primary_color'] }}; }
        .verify .url { font-size: 7.5pt; color: {{ $design['accent_color'] }}; }
    </style>
</head>
<body>
<div class="sheet">
    @if ($background)
        {{-- Diseño propio de la organización: ocupa toda la hoja y reemplaza los marcos --}}
        <img class="background" src="{{ $background }}" alt="">
    @else
        <div class="band"></div>
        <div class="band-accent"></div>
        <div class="frame"></div>
        <div class="frame-inner"></div>
    @endif

    <div class="content">
        @if ($logo && $design['show_logo'])
            <img class="logo" src="{{ $logo }}" alt="">
        @endif
        @if ($design['show_organization'])
            <div class="organization">{{ $data['organization'] }}</div>
        @endif

        @if ($design['show_title'])
            <div class="title">CERTIFICADO</div>
        @endif
        <div class="lead">Se otorga a</div>
        <div class="student">{{ $data['student_name'] }}</div>
        @if (! empty($data['student_document']))
            <div class="document">{{ $data['student_document'] }}</div>
        @endif
        <div class="rule"></div>

        <div class="lead">por haber completado satisfactoriamente el curso</div>
        <div class="course">{{ $data['course_title'] }}</div>
        <div class="details">
            @if (! empty($data['duration_hours']))
                con una duración de {{ $data['duration_hours'] }} {{ (int) $data['duration_hours'] === 1 ? 'hora' : 'horas' }} ·
            @endif
            emitido el {{ $issuedOn }}
        </div>
    </div>

    @if (! empty($data['signer_name']))
        <div class="signature">
            @if ($signature)
                <img src="{{ $signature }}" alt="">
            @else
                <div class="blank"></div>
            @endif
            <div class="line"></div>
            <div class="name">{{ $data['signer_name'] }}</div>
            @if (! empty($data['signer_title']))
                <div class="role">{{ $data['signer_title'] }}</div>
            @endif
        </div>
    @endif

    <table class="verify" cellspacing="0" cellpadding="0">
        <tr>
            <td style="width: 33mm;"><img src="{{ $qr }}" alt=""></td>
            <td>
                <div class="label">Código de verificación</div>
                <div class="code">{{ $certificate->verification_code }}</div>
                <div class="label">Comprueba su validez en</div>
                <div class="url">{{ $verifyHost }}</div>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
