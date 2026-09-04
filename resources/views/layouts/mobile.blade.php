<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>@yield('title', 'Mi Marca')</title>
    <link rel="icon" href="{{ asset('img/LogoProserge.png') }}?v=2">
    <link rel="apple-touch-icon" href="{{ asset('img/LogoProserge.png') }}?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @include('partials.vite-assets')
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f0f5f9; color: #1e293b; min-height: 100dvh; -webkit-tap-highlight-color: transparent; }
        .mob-page { max-width: 480px; margin: 0 auto; padding: 0 0 24px; min-height: 100dvh; background: #fff; }
        .mob-header { background: linear-gradient(135deg, #0a223d 0%, #134068 100%); color: #fff; padding: 20px 18px 16px; }
        .mob-header-top { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .mob-avatar { width: 42px; height: 42px; border-radius: 50%; background: #19d3c5; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 15px; color: #fff; flex-shrink: 0; }
        .mob-header-info { flex: 1; min-width: 0; }
        .mob-header-info .mob-label { font-size: 10px; opacity: .7; text-transform: uppercase; letter-spacing: .5px; }
        .mob-header-info .mob-name { font-size: 15px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .mob-header-meta { display: flex; gap: 16px; font-size: 11px; opacity: .8; }
        .mob-header-meta span { display: flex; align-items: center; gap: 4px; }
        .mob-body { padding: 16px 18px; }
        .mob-card { background: #fff; border: 1px solid #e8eef5; border-radius: 14px; padding: 16px; margin-bottom: 14px; box-shadow: 0 2px 8px rgba(15,35,66,.04); }
        .mob-card-title { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #7b8da6; letter-spacing: .5px; margin-bottom: 10px; }
        .mob-mark-btn { width: 100%; padding: 16px; border: none; border-radius: 14px; font-size: 16px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all .15s; }
        .mob-mark-btn.primary { background: linear-gradient(135deg, #19d3c5, #14b5a8); color: #fff; box-shadow: 0 6px 20px rgba(25,211,197,.35); }
        .mob-mark-btn.primary:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(25,211,197,.45); }
        .mob-mark-btn.primary:active { transform: scale(.98); }
        .mob-mark-btn.marked { background: #def8e8; color: #087443; border: 2px solid #62d59c; }
        .mob-mark-btn.late { background: #fff4ce; color: #956000; border: 2px solid #f2c35e; }
        .mob-mark-btn.disabled { background: #f1f5f9; color: #94a3b8; cursor: default; border: 2px solid #e2e8f0; }
        .mob-record { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
        .mob-record:last-child { border-bottom: none; }
        .mob-record-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .mob-record-dot.presente { background: #10b981; }
        .mob-record-dot.tardanza { background: #f59e0b; }
        .mob-record-dot.ausente { background: #ef4444; }
        .mob-record-dot.justificado { background: #6366f1; }
        .mob-record-info { flex: 1; min-width: 0; }
        .mob-record-info .mob-rec-label { font-size: 13px; font-weight: 700; }
        .mob-record-info .mob-rec-sub { font-size: 11px; color: #7b8da6; }
        .mob-record-time { font-size: 14px; font-weight: 800; color: #1e293b; white-space: nowrap; }
        .mob-empty { text-align: center; padding: 20px 10px; color: #94a3b8; font-size: 13px; }
        .mob-notice { padding: 12px 14px; border-radius: 10px; font-size: 12px; font-weight: 600; margin-bottom: 14px; }
        .mob-notice.success { background: #def8e8; color: #087443; border: 1px solid #62d59c; }
        .mob-notice.warning { background: #fff4ce; color: #956000; border: 1px solid #f2c35e; }
        .mob-notice.info { background: #e8f1ff; color: #235aa5; border: 1px solid #9bbce9; }
        .mob-footer { text-align: center; padding: 16px; font-size: 11px; color: #94a3b8; }
        @keyframes popIn { 0% { transform: scale(.8); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
        .mob-animate { animation: popIn .25s ease-out; }
    </style>
    @stack('styles')
</head>
<body>
    @yield('content')
    @stack('scripts')
    @yield('scripts')
</body>
</html>
