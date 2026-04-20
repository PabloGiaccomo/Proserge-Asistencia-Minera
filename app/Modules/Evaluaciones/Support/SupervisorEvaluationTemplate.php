<?php

namespace App\Modules\Evaluaciones\Support;

class SupervisorEvaluationTemplate
{
    public const ITEMS = [
        'A' => [
            'A1' => 'Realiza las coordinaciones correspondientes con el supervisor operativo de la minera, sobre el trabajo a realizar en las disciplinas de mecanica.',
            'A2' => 'Identifica la tarea a realizar en campo.',
            'A3' => 'Realiza la buena practica en optimizar las herramientas para realizar los trabajos en campo.',
            'A4' => 'Utiliza su conocimiento tecnico de area humeda y seca para resolver problemas.',
            'A5' => 'Constata el correcto funcionamiento de equipos y herramientas antes de iniciar sus actividades.',
            'A6' => 'Constata el correcto funcionamiento de equipos y herramientas una vez hechos los trabajos de montaje y coberturado.',
            'A7' => 'Realiza oportunamente la entrega de informes y protocolos de servicios ejecutados.',
            'A8' => 'Utiliza su capacidad de resolucion de problemas ante situaciones no previstas.',
            'A9' => 'Item tecnico configurable.',
        ],
        'B' => [
            'B1' => 'Con que frecuencia revisa y actualiza los PETS de su trabajo a realizar?',
            'B2' => 'Cumple las normas, reglamentos, instrucciones, procedimientos, formatos y demas documentos del Sistema Integrado de Gestion de la Empresa.',
            'B3' => 'Demuestra una actitud segura frente al desarrollo de sus labores, realiza observaciones de comportamientos inseguros e identifica peligros y riesgos asociados a la actividad informando de manera oportuna a su supervisor operativo con el fin de prevenir la ocurrencia de incidentes.',
            'B4' => 'Propone oportunidades de mejora con respecto a seguridad para la ejecucion de actividades de mantenimiento.',
            'B5' => 'Corrige de inmediato las observaciones encontradas en campo en cuanto a temas de seguridad y medio ambiente.',
            'B6' => 'Mantiene su area limpia y ordenada.',
            'B7' => 'Conoce las politicas y estandares de la empresa y de la minera en que realiza sus actividades.',
        ],
        'C' => [
            'C1' => 'Sabe integrar al equipo, motivando y participando positivamente para alcanzar los objetivos y metas comunes.',
            'C2' => 'Comparte su conocimiento, habilidades y experiencia.',
            'C3' => 'Se desempeña como un miembro activo del equipo.',
            'C4' => 'Fomenta el dialogo de manera abierta y directa.',
            'C5' => 'Expresa sus ideas con claridad y respeto a la otra persona.',
            'C6' => 'Comparte informacion de manera efectiva y asertiva.',
            'C7' => 'Se esfuerza por mantener buenas relaciones con personas de distinto nivel y ambito de actividad.',
            'C8' => 'Acepta y vive los cambios y novedades como una oportunidad de mejora.',
            'C9' => 'Busca nuevas maneras de brindar valor agregado a los clientes.',
            'C10' => 'Toma en cuenta la satisfaccion del cliente externo para la ejecucion de sus actividades.',
        ],
    ];

    public const WEIGHTS = [
        'A1' => 0.04,
        'A2' => 0.04,
        'A3' => 0.04,
        'A4' => 0.08,
        'A5' => 0.04,
        'A6' => 0.04,
        'A7' => 0.04,
        'A8' => 0.04,
        'A9' => 0.04,
        'B1' => 0.05,
        'B2' => 0.05,
        'B3' => 0.05,
        'B4' => 0.04,
        'B5' => 0.04,
        'B6' => 0.04,
        'B7' => 0.03,
        'C1' => 0.03,
        'C2' => 0.03,
        'C3' => 0.03,
        'C4' => 0.03,
        'C5' => 0.03,
        'C6' => 0.03,
        'C7' => 0.03,
        'C8' => 0.03,
        'C9' => 0.03,
        'C10' => 0.03,
    ];

    public static function normalizeResponses(array $input): array
    {
        $normalized = [];

        foreach (array_keys(self::WEIGHTS) as $key) {
            $value = $input[$key] ?? null;
            $normalized[$key] = (is_numeric($value) && (int) $value >= 1 && (int) $value <= 5)
                ? (int) $value
                : 0;
        }

        return $normalized;
    }

    public static function calculateFinalScore(array $responses): float
    {
        $weighted = 0.0;

        foreach (self::WEIGHTS as $key => $weight) {
            $weighted += $weight * (float) ($responses[$key] ?? 0);
        }

        return round(($weighted / 5) * 100, 2);
    }
}
