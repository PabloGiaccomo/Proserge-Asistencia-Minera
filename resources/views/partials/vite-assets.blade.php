@php
    $manifestPath = public_path('build/manifest.json');
    $hotPath = public_path('hot');
    $hotUrl = is_file($hotPath) ? trim((string) file_get_contents($hotPath)) : '';
    $hotAvailable = false;

    if ($hotUrl !== '') {
        $hotParts = parse_url($hotUrl);
        $hotHost = $hotParts['host'] ?? null;
        $hotPort = (int) ($hotParts['port'] ?? (($hotParts['scheme'] ?? 'http') === 'https' ? 443 : 80));

        if ($hotHost) {
            $connection = @fsockopen(trim($hotHost, '[]'), $hotPort, $errorCode, $errorMessage, 0.15);

            if ($connection) {
                $hotAvailable = true;
                fclose($connection);
            }
        }
    }

    $manifest = is_file($manifestPath)
        ? (json_decode((string) file_get_contents($manifestPath), true) ?: [])
        : [];

    $cssFiles = collect();
    $jsFiles = collect();

    if (! $hotAvailable && $manifest) {
        $cssEntry = $manifest['resources/css/app.css'] ?? [];
        $jsEntry = $manifest['resources/js/app.js'] ?? [];

        $cssFiles = $cssFiles
            ->when(isset($cssEntry['file']), fn ($files) => $files->push($cssEntry['file']))
            ->merge($jsEntry['css'] ?? []);

        $jsFiles = $jsFiles
            ->when(isset($jsEntry['file']), fn ($files) => $files->push($jsEntry['file']));
    }

    if (! $hotAvailable && ($cssFiles->isEmpty() || $jsFiles->isEmpty())) {
        $cssFiles = collect(glob(public_path('build/assets/app-*.css')) ?: [])
            ->map(fn ($path) => 'assets/'.basename($path))
            ->sortDesc()
            ->take(1);

        $jsFiles = collect(glob(public_path('build/assets/app-*.js')) ?: [])
            ->map(fn ($path) => 'assets/'.basename($path))
            ->sortDesc()
            ->take(1);
    }

    $cssFiles = $cssFiles->filter()->unique();
    $jsFiles = $jsFiles->filter()->unique();
    $fallbackCss = 'css/proserge-app.css';
    $fallbackCssPath = public_path($fallbackCss);
    $fallbackCssVersion = is_file($fallbackCssPath) ? filemtime($fallbackCssPath) : null;
@endphp

@if($hotAvailable)
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@else
    @if($fallbackCssVersion)
        <link rel="stylesheet" href="{{ asset($fallbackCss) }}?v={{ $fallbackCssVersion }}">
    @endif

    @foreach($cssFiles as $cssFile)
        <link rel="stylesheet" href="{{ asset('build/'.$cssFile) }}">
    @endforeach

    @foreach($jsFiles as $jsFile)
        <script type="module" src="{{ asset('build/'.$jsFile) }}"></script>
    @endforeach
@endif
