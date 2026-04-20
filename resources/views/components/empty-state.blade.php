<div class="empty-state">
    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
    </svg>
    <h3>{{ $message ?? 'No hay datos' }}</h3>
    <p>{{ $description ?? '' }}</p>
    @if(isset($action))
    <a href="{{ $action }}" class="btn btn-primary btn-sm">{{ $actionText ?? 'Crear' }}</a>
    @endif
</div>

<style>
.empty-state { text-align: center; padding: 40px 20px; color: #64748b; }
.empty-state svg { margin-bottom: 16px; opacity: 0.5; }
.empty-state h3 { font-size: 16px; font-weight: 600; color: #475569; margin: 0 0 8px; }
.empty-state p { font-size: 14px; margin: 0; }
</style>
