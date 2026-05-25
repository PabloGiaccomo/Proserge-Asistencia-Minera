@extends('layouts.app')

@section('title', 'Herramientas por Parada')

@php
    $estadoClass = static function (string $estado): string {
        return match (strtoupper($estado)) {
            'ENVIADO' => 'sent',
            'BORRADOR' => 'draft',
            default => 'pending',
        };
    };
    $roles = array_map('strtoupper', session('user.roles', []));
    $isLogistica = collect($roles)->contains(static fn ($rol) => str_contains($rol, 'LOGIST'));
    $deadlineAlerts = $deadlineAlerts ?? [];
@endphp

@section('content')
<div class="tools-page">
    @if(!empty($deadlineAlerts))
        <div class="tools-deadline-toast" id="toolsDeadlineToast" role="status" aria-live="polite">
            <div class="tools-deadline-toast-head">
                <div>
                    <strong>Listas por vencer</strong>
                    <span>Vencen dentro de los proximos 7 dias</span>
                </div>
                <button type="button" onclick="document.getElementById('toolsDeadlineToast')?.remove()" aria-label="Cerrar aviso">&times;</button>
            </div>
            <div class="tools-deadline-toast-body">
                @foreach($deadlineAlerts as $alert)
                    @php $days = (int) ($alert['dias_para_limite'] ?? 0); @endphp
                    <a href="{{ route('herramientas-parada.show', $alert['rq_mina_id']) }}" class="tools-deadline-toast-item">
                        <span>{{ $alert['lugar'] ?? '-' }}</span>
                        <strong>
                            @if($days === 0)
                                Vence hoy
                            @elseif($days === 1)
                                Vence en 1 dia
                            @else
                                Vence en {{ $days }} dias
                            @endif
                        </strong>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <div class="page-header-custom">
        <div>
            <h1 class="page-title">Herramientas por Parada</h1>
            <p class="page-subtitle">Listas semanales de equipos, herramientas y utillaje por grupo</p>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-error">{{ session('error') }}</div>
    @endif

    <div class="filters-bar">
        <form method="GET" action="{{ route('herramientas-parada.index') }}" class="tools-filters">
            <div class="filter-group">
                <label class="filter-label">Buscar</label>
                <input type="text" name="q" class="filter-input" value="{{ $filters['q'] ?? '' }}" placeholder="Lugar, mina o area">
            </div>
            <div class="filter-group">
                <label class="filter-label">Estado lista</label>
                <select name="estado_lista" class="filter-select">
                    <option value="">Todos</option>
                    @foreach(['PENDIENTE' => 'Pendiente', 'BORRADOR' => 'Borrador', 'ENVIADO' => 'Enviado'] as $value => $label)
                        <option value="{{ $value }}" {{ strtoupper((string) ($filters['estado_lista'] ?? '')) === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="tools-filter-actions">
                <button type="submit" class="btn-filter">Filtrar</button>
                <a href="{{ route('herramientas-parada.index') }}" class="btn-filter-outline">Limpiar</a>
            </div>
        </form>
    </div>

    <div class="tools-card">
        <div class="tools-card-header">
            <div>
                <h2>Paradas</h2>
                <span>{{ count($items) }} registros</span>
            </div>
        </div>

        @if(empty($items))
            <div class="empty-state">
                <h3>Sin paradas para mostrar</h3>
                <p>No hay resultados con los filtros actuales.</p>
            </div>
        @else
            <div class="tools-table-wrap">
                <table class="tools-table">
                    <thead>
                        <tr>
                            <th>Parada</th>
                            <th>Semana</th>
                            <th>Fechas</th>
                            <th>Limite envio</th>
                            <th>Grupos</th>
                            <th>Estado lista</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($items as $item)
                            @php
                                $dias = (int) ($item['dias_para_limite'] ?? 0);
                                $deadlineClass = $dias < 0 ? 'expired' : ($dias <= 2 ? 'urgent' : 'ok');
                            @endphp
                            <tr>
                                <td>
                                    <div class="tools-main-cell">
                                        <strong>{{ $item['lugar'] ?? '-' }}</strong>
                                        <span>{{ $item['area'] ?? '-' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="week-pill">Sem. {{ $item['semana'] ?? '-' }}</span>
                                    <div class="week-year">{{ $item['anio_semana'] ?? '' }}</div>
                                </td>
                                <td>{{ $item['fecha_inicio'] ?? '-' }} al {{ $item['fecha_fin'] ?? '-' }}</td>
                                <td>
                                    <div class="deadline {{ $deadlineClass }}">
                                        <strong>{{ $item['fecha_limite_envio'] ?? '-' }}</strong>
                                        <span>
                                            @if($dias < 0)
                                                Vencido hace {{ abs($dias) }} dia(s)
                                            @elseif($dias === 0)
                                                Vence hoy
                                            @else
                                                Faltan {{ $dias }} dia(s)
                                            @endif
                                        </span>
                                    </div>
                                </td>
                                <td>{{ (int) ($item['grupos_count'] ?? 0) }}</td>
                                <td><span class="tools-status {{ $estadoClass($item['estado_lista'] ?? 'PENDIENTE') }}">{{ ucfirst(strtolower($item['estado_lista'] ?? 'Pendiente')) }}</span></td>
                                <td>
                                    <a href="{{ route('herramientas-parada.show', $item['rq_mina_id']) }}" class="btn-row btn-row-outline">Ver lista</a>
                                    @if($isLogistica)
                                        <a href="{{ route('herramientas-parada.show', $item['rq_mina_id']) }}" class="btn-row">Completar pedido</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
