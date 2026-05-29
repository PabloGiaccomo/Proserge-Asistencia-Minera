@php
    $manifestPath = public_path('build/manifest.json');
@endphp

@if(file_exists($manifestPath))
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@else
    <link rel="stylesheet" href="{{ asset('build/assets/app-BjVZgHOP.css') }}?v=9f148cf">
    <script type="module" src="{{ asset('build/assets/app-DJ1StAe3.js') }}?v=9f148cf"></script>
@endif
