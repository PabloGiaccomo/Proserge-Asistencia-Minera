@extends('layouts.app')

@section('title', 'Asistencia - Por Supervisor')

@php
                <div class="asistencia-table-header">
                    <span>Supervisor</span>
                    <span>Mina</span>
                    <span>Grupos</span>
                    <span>Personal</span>
                    <span>Asistencia</span>
                    <span>Faltas</span>
                    <span>Evaluación</span>
                </div>
                @foreach($supervisores as $sup)
                <div class="asistencia-table-row">
                    <span class="destino-nombre">{{ $sup['nombre'] }}</span>
                    <span>{{ $sup['mina'] }}</span>
                    <span>{{ $sup['grupos'] }}</span>
                    <span>{{ $sup['personal'] }}</span>
                    <span class="porcentaje {{ $sup['porcentaje'] >= 90 ? 'porcentaje-alto' : ($sup['porcentaje'] >= 80 ? 'porcentaje-medio' : 'porcentaje-bajo') }}">{{ $sup['porcentaje'] }}%</span>
                    <span class="texto-ausente">{{ $sup['faltas_acumuladas'] }}</span>
                    <span class="texto-success">{{ number_format($sup['promedio_eval'], 1) }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection

