<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Constancia de gestacion</title>
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            color: #111827;
            font-size: 12px;
            line-height: 1.5;
            margin: 42px;
        }
        .header {
            border-bottom: 2px solid #0f766e;
            padding-bottom: 14px;
            margin-bottom: 26px;
        }
        .brand {
            color: #0f766e;
            font-size: 18px;
            font-weight: bold;
            letter-spacing: .04em;
        }
        h1 {
            font-size: 22px;
            margin: 24px 0 8px;
            text-align: center;
        }
        .subtitle {
            text-align: center;
            color: #4b5563;
            margin-bottom: 28px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 18px 0;
        }
        th, td {
            border: 1px solid #d1d5db;
            padding: 9px 10px;
            text-align: left;
            vertical-align: top;
        }
        th {
            width: 34%;
            background: #f3f4f6;
        }
        .note {
            margin-top: 22px;
            padding: 14px;
            border: 1px solid #d1d5db;
            background: #f9fafb;
        }
        .signatures {
            margin-top: 70px;
            width: 100%;
        }
        .signature {
            width: 42%;
            border-top: 1px solid #111827;
            text-align: center;
            padding-top: 8px;
            color: #374151;
        }
        .signature.right {
            float: right;
        }
        .signature.left {
            float: left;
        }
        .footer {
            position: fixed;
            bottom: 24px;
            left: 42px;
            right: 42px;
            color: #6b7280;
            font-size: 10px;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
        }
    </style>
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
