@extends('layouts.app')

@section('title', 'Notificaciones - Proserge')

@section('content')
<div class="module-page">
    <div class="page-header">
        <div class="page-header-top" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
            <div>
                <h1 class="page-title">Notificaciones</h1>
                <p class="page-subtitle">Alertas segun tus permisos y alcance por mina.</p>
            </div>
            <form method="POST" action="{{ route('notificaciones.mark-all-read') }}">
                @csrf
                <button type="submit" class="btn btn-outline">Marcar todas como leidas</button>
            </form>
        </div>
    </div>

    <div class="card" style="margin-bottom:12px;">
        <div class="card-body">
            <form method="GET" action="{{ route('notificaciones.index') }}" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:end;">
                <div>
                    <label class="filter-compact-label">Estado</label>
                    <select name="status" class="filter-compact-select">
                        <option value="">Todos</option>
                        @foreach(['UNREAD' => 'No leida', 'READ' => 'Leida', 'ARCHIVED' => 'Archivada', 'ACTIONED' => 'Accionada', 'EXPIRED' => 'Vencida'] as $value => $label)
                            <option value="{{ $value }}" {{ ($filters['status'] ?? '') === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="filter-compact-label">Modulo</label>
                    <select name="module" class="filter-compact-select">
                        <option value="">Todos</option>
                        @foreach(['personal', 'rq_mina', 'rq_proserge', 'man_power', 'asistencias', 'faltas', 'usuarios', 'roles', 'bienestar'] as $module)
                            <option value="{{ $module }}" {{ ($filters['module'] ?? '') === $module ? 'selected' : '' }}>{{ strtoupper(str_replace('_', ' ', $module)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="filter-compact-label">Prioridad</label>
                    <select name="priority" class="filter-compact-select">
                        <option value="">Todas</option>
                        @foreach(['low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta', 'critical' => 'Critica'] as $value => $label)
                            <option value="{{ $value }}" {{ ($filters['priority'] ?? '') === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="filter-compact-label">Mina</label>
                    <select name="mine_id" class="filter-compact-select">
                        <option value="">Todas</option>
                        @foreach($mines as $mine)
                            <option value="{{ $mine->id }}" {{ ($filters['mine_id'] ?? '') === $mine->id ? 'selected' : '' }}>{{ $mine->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="filter-compact-label">Buscar</label>
                    <input type="text" name="q" class="filter-compact-select" value="{{ $filters['q'] ?? '' }}" placeholder="Titulo o mensaje">
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                    <a href="{{ route('notificaciones.index') }}" class="btn btn-outline">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
            <span class="card-title">Bandeja</span>
            <span class="card-badge">{{ $unreadCount }} no leidas</span>
        </div>
        <div class="card-body" style="padding-top:0;">
            @if($notifications->count() === 0)
                <div class="empty-state" style="padding:24px;">
                    <h3 class="empty-title">No hay notificaciones</h3>
                    <p class="empty-description">No se encontraron alertas con los filtros aplicados.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Notificacion</th>
                            <th>Modulo</th>
                            <th>Prioridad</th>
                            <th>Mina</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                            <th>Acciones</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($notifications as $recipient)
                            @php $event = $recipient->event; @endphp
                            <tr>
                                <td>
                                    <div style="font-weight:600;">{{ $event->title }}</div>
                                    <div style="font-size:12px;color:#64748b;">{{ $event->message }}</div>
                                </td>
                                <td>{{ strtoupper(str_replace('_', ' ', (string) $event->module)) }}</td>
                                <td>{{ strtoupper((string) $event->priority) }}</td>
                                <td>{{ $event->mina->nombre ?? '-' }}</td>
                                <td>{{ $recipient->status }}</td>
                                <td>{{ optional($recipient->created_at)->format('d/m/Y H:i') }}</td>
                                <td>
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                        @if($recipient->status === 'UNREAD')
                                            <form method="POST" action="{{ route('notificaciones.mark-read', $recipient->id) }}">@csrf<button type="submit" class="btn btn-outline btn-xs">Leida</button></form>
                                        @endif
                                        @if(!empty($event->action_route))
                                            <a href="{{ route('notificaciones.action', $recipient->id) }}" class="btn btn-primary btn-xs">{{ $event->action_label ?: 'Abrir' }}</a>
                                        @endif
                                        @if($recipient->status !== 'ARCHIVED')
                                            <form method="POST" action="{{ route('notificaciones.archive', $recipient->id) }}">@csrf<button type="submit" class="btn btn-outline btn-xs">Archivar</button></form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div style="padding:12px 0 4px;">{{ $notifications->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
