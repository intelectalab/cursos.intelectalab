<!doctype html>
<html lang="es">
<body style="font-family: Arial, Helvetica, sans-serif; color: #1e293b; font-size: 14px; line-height: 1.5;">
    <p>Hola {{ $user->first_name }}:</p>
    <p>Recibimos una solicitud para restablecer tu contraseña en <strong>{{ $organization }}</strong>.</p>
    <p>
        <a href="{{ $url }}" style="display: inline-block; padding: 10px 18px; background: #084586; color: #ffffff; text-decoration: none; border-radius: 8px;">
            Crear una contraseña nueva
        </a>
    </p>
    <p>El enlace vence en {{ $minutes }} minutos y solo se puede usar una vez.</p>
    <p>Si no hiciste esta solicitud, ignora este mensaje: tu contraseña no cambiará.</p>
    <p style="color: #64748b; font-size: 12px;">Si el botón no funciona, copia esta dirección en tu navegador:<br>{{ $url }}</p>
</body>
</html>
