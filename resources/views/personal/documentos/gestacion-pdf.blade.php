<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Constancia de gestacion</title>
    
</head>
<body>
    <div class="header">
        <div class="brand">PROSERGE</div>
        <div>Area de Bienestar</div>
    </div>

    <h1>Constancia provisional de gestacion</h1>
    <div class="subtitle">Formato temporal preparado para reemplazo por plantilla oficial.</div>

    <table>
        <tr>
            <th>Trabajadora</th>
            <td>{{ $trabajador->nombre_completo }}</td>
        </tr>
        <tr>
            <th>Documento</th>
            <td>{{ $trabajador->tipo_documento ?: 'DNI' }} {{ $trabajador->numero_documento ?: $trabajador->dni }}</td>
        </tr>
        <tr>
            <th>Puesto</th>
            <td>{{ $trabajador->puesto ?: '-' }}</td>
        </tr>
        <tr>
            <th>Sexo registrado</th>
            <td>{{ $data['sexo'] ?? 'Femenino' }}</td>
        </tr>
        <tr>
            <th>Periodo registrado</th>
            <td>{{ optional($bloqueo->fecha_inicio)->format('d/m/Y') }} al {{ optional($bloqueo->fecha_fin)->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <th>Motivo</th>
            <td>{{ $bloqueo->motivo ?: 'Periodo de gestacion' }}</td>
        </tr>
        <tr>
            <th>Detalle</th>
            <td>{{ $bloqueo->detalle ?: '-' }}</td>
        </tr>
    </table>

    <div class="note">
        Se deja constancia de que la trabajadora cuenta con un periodo de gestacion registrado en el modulo de Bienestar.
        Este documento se emite como version provisional hasta contar con el formato oficial definitivo.
    </div>

    <div class="signatures">
        <div class="signature left">Bienestar / RRHH</div>
        <div class="signature right">Firma autorizada</div>
    </div>

    <div class="footer">
        Generado el {{ now()->format('d/m/Y H:i') }} desde Proserge.
    </div>
</body>
</html>
