<?php

use App\Console\Commands\AsistenciaAusentesPorHorarioCommand;
use App\Console\Commands\HabilitacionMineraSyncRequirementsCommand;
use App\Console\Commands\HabilitacionMineraSyncTemporalStatusesCommand;
use App\Console\Commands\NotificationsCleanupExpiredCommand;
use App\Console\Commands\ParadaHerramientasDeadlineReminderCommand;
use App\Modules\Epps\Services\EppOperationService;
use App\Modules\Personal\Services\PersonalFichaService;
use App\Modules\Personal\Services\PersonalService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(NotificationsCleanupExpiredCommand::class)->dailyAt('02:10');
Schedule::command(ParadaHerramientasDeadlineReminderCommand::class)->dailyAt('07:30');
Schedule::command(AsistenciaAusentesPorHorarioCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(HabilitacionMineraSyncTemporalStatusesCommand::class)->dailyAt('00:10')->withoutOverlapping();
Schedule::command(HabilitacionMineraSyncRequirementsCommand::class)->everyMinute()->withoutOverlapping();
Schedule::call(fn () => app(PersonalFichaService::class)->expireStaleLinks())
    ->name('personal:expire-stale-links')->everyTenMinutes()->withoutOverlapping();
Schedule::call(fn () => app(PersonalService::class)->syncExpiredContractClosures())
    ->name('personal:sync-expired-contracts')->everyThirtyMinutes()->withoutOverlapping();
Schedule::call(fn () => app(EppOperationService::class)->expireReservations(now()))
    ->name('epps:expire-reservations')->everyMinute()->withoutOverlapping();
