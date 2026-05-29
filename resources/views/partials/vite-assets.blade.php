@php
    $manifestPath = public_path('build/manifest.json');
    $fallbackCss = collect(glob(public_path('build/assets/app-*.css')) ?: [])
        ->map(fn ($path) => basename($path))
        ->sortDesc()
        ->first();
    $fallbackJs = collect(glob(public_path('build/assets/app-*.js')) ?: [])
        ->map(fn ($path) => basename($path))
        ->sortDesc()
        ->first();
@endphp

@if(file_exists($manifestPath))
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@elseif($fallbackCss && $fallbackJs)
    <link rel="stylesheet" href="{{ asset('build/assets/'.$fallbackCss) }}">
    <script type="module" src="{{ asset('build/assets/'.$fallbackJs) }}"></script>
@endif
