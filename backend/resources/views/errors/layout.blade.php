<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: linear-gradient(135deg, #0b1f3a, #12355f); color: #fff; }
        main { max-width: 28rem; padding: 2rem; text-align: center; }
        p.code { font-size: .8rem; letter-spacing: .15em; color: #7dd3fc; margin: 0 0 .5rem; }
        h1 { font-size: 1.6rem; margin: 0 0 .75rem; }
        p { color: #cbd5e1; line-height: 1.5; }
        a { display: inline-block; margin-top: 1rem; padding: .6rem 1.2rem; border-radius: .6rem; background: #fff; color: #0b1f3a; font-weight: 600; text-decoration: none; }
    </style>
</head>
<body>
<main>
    <p class="code">ERROR @yield('code')</p>
    <h1>@yield('title')</h1>
    <p>@yield('message')</p>
    <a href="/">Ir al inicio</a>
</main>
</body>
</html>
