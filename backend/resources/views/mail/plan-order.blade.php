<!doctype html>
<html lang="es">
<body style="font-family: Arial, Helvetica, sans-serif; color: #1e293b; font-size: 14px; line-height: 1.5;">
    <p><strong>{{ $title }}</strong></p>
    @foreach ($lines as $line)
        <p>{{ $line }}</p>
    @endforeach
    <p style="color: #64748b; font-size: 12px;">IntelectaLab · Este mensaje se envía solo; no hace falta responderlo.</p>
</body>
</html>
