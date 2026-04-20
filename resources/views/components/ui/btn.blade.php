@php
    $variant = $variant ?? 'primary';
    $size = $size ?? '';
    $href = $href ?? null;
    $type = $type ?? 'button';
@endphp

@if($href)
    <a href="{{ $href }}" class="btn btn-{{ $variant }} {{ $size }}">
        @isset($icon)
            <span class="btn-icon">{!! $icon !!}</span>
        @endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" class="btn btn-{{ $variant }} {{ $size }}" {{ $attributes }}>
        @isset($icon)
            <span class="btn-icon">{!! $icon !!}</span>
        @endif
        {{ $slot }}
    </button>
@endif