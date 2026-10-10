<?php

namespace App\Modules\Personal\Support;

enum ScheduledAttendanceState: string
{
    case PROGRAMADO = 'PROGRAMADO';
    case ASISTIO = 'ASISTIO';
    case NO_ASISTIO = 'NO_ASISTIO';
    case ANULADO = 'ANULADO';
}
