<div class="kpi-card">
    <div class="kpi-icon" style="background: {{ $color ?? '#3b82f6' }}20; color: {{ $color ?? '#3b82f6' }};">
        @if(isset($icon))
            {!! $icon !!}
        @else
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
            </svg>
        @endif
    </div>
    <div class="kpi-content">
        <span class="kpi-value">{{ $value }}</span>
        <span class="kpi-label">{{ $label }}</span>
    </div>
    @isset($trend)
        <div class="kpi-trend {{ $trend > 0 ? 'positive' : ($trend < 0 ? 'negative' : '') }}">
            {{ $trend > 0 ? '+' : '' }}{{ $trend }}%
        </div>
    @endif
</div>