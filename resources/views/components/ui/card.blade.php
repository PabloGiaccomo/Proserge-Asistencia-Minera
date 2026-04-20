<div class="card {{ $class ?? '' }}">
    @isset($header)
        <div class="card-header">
            <div class="card-title">{{ $header }}</div>
            @isset($actions)
                <div class="card-actions">{{ $actions }}</div>
            @endif
        </div>
    @endif
    
    <div class="card-body {{ $noPadding ?? false ? 'card-body-no-padding' : '' }}">
        {{ $slot }}
    </div>
    
    @isset($footer)
        <div class="card-footer">
            {{ $footer }}
        </div>
    @endif
</div>