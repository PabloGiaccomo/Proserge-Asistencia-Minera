<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Ficha del colaborador - Proserge')</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=2" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('img/LogoProserge.png') }}?v=2">
    <link rel="apple-touch-icon" href="{{ asset('img/LogoProserge.png') }}?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @include('partials.vite-assets')
</head>
<body>
    <main class="public-ficha-shell">
        @yield('content')
    </main>
    @stack('scripts')
</body>
</html>
