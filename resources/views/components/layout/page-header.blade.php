<div class="page-header">
    <div class="page-header-content">
        <div class="page-header-text">
            <h1 class="page-title">{{ $title }}</h1>
            @isset($subtitle)
                <p class="page-subtitle">{{ $subtitle }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="page-header-actions">
                {{ $actions }}
            </div>
        @endif
    </div>
    @isset($tabs)
        <div class="page-tabs">
            {{ $tabs }}
        </div>
    @endif
</div>