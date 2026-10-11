{{-- Certificado con diseño libre: cada elemento va donde la organización lo colocó (medidas en mm) --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Certificado {{ $code }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; }
        .sheet { position: relative; width: {{ $width }}mm; height: {{ $height }}mm; overflow: hidden; }
        .background { position: absolute; left: 0; top: 0; width: {{ $width }}mm; height: {{ $height }}mm; }
        .band { position: absolute; left: 0; top: 0; width: {{ $width }}mm; height: 12mm; background: #0a2540; }
        .band-accent { position: absolute; left: 0; top: 12mm; width: {{ $width }}mm; height: 1.6mm; background: #38bdf8; }
        .frame { position: absolute; left: 10mm; top: 20mm; width: {{ $width - 22 }}mm; height: {{ $height - 30 }}mm; border: 0.6mm solid #0a2540; }
        .frame-inner { position: absolute; left: 12.5mm; top: 22.5mm; width: {{ $width - 26.2 }}mm; height: {{ $height - 34.2 }}mm; border: 0.2mm solid #38bdf8; }
        .element { position: absolute; line-height: 1.2; }
    </style>
</head>
<body>
<div class="sheet">
    @if ($background)
        <img class="background" src="{{ $background }}" alt="">
    @else
        <div class="band"></div>
        <div class="band-accent"></div>
        <div class="frame"></div>
        <div class="frame-inner"></div>
    @endif

    @foreach ($elements as $element)
        @if ($element['kind'] === 'image')
            <img class="element" src="{{ $element['src'] }}" alt=""
                 style="left: {{ $element['x'] }}mm; top: {{ $element['y'] }}mm; width: {{ $element['w'] }}mm; height: {{ $element['h'] }}mm;">
        @else
            <div class="element"
                 style="left: {{ $element['x'] }}mm; top: {{ $element['y'] }}mm; width: {{ $element['w'] }}mm; font-family: '{{ $element['font'] }}'; font-size: {{ $element['size'] }}pt; color: {{ $element['color'] }}; font-weight: {{ $element['bold'] ? 'bold' : 'normal' }}; font-style: {{ $element['italic'] ? 'italic' : 'normal' }}; text-align: {{ $element['align'] }}; text-transform: {{ $element['uppercase'] ? 'uppercase' : 'none' }};">{{ $element['value'] }}</div>
        @endif
    @endforeach
</div>
</body>
</html>
