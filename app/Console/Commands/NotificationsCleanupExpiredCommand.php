<?php

namespace App\Console\Commands;

use App\Models\NotificationRecipient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NotificationsCleanupExpiredCommand extends Command
{
    protected $signature = 'notifications:cleanup-expired {--purge-days=15 : Dias despues del vencimiento para purgar definitivamente}';

    protected $description = 'Marca vencidas y purga notificaciones expiradas';

    public function handle(): int
    {
        $now = now();
        $purgeDays = max(1, (int) $this->option('purge-days'));

        $marked = NotificationRecipient::query()
            ->where('status', 'UNREAD')
            ->whereHas('event', function ($query) use ($now): void {
                $query->whereNotNull('expires_at')->where('expires_at', '<', $now);
            })
            ->update([
                'status' => 'EXPIRED',
                'updated_at' => $now,
            ]);

        $purgeBefore = $now->copy()->subDays($purgeDays);

        $deletedRecipients = NotificationRecipient::query()
            ->whereHas('event', function ($query) use ($purgeBefore): void {
                $query->whereNotNull('expires_at')->where('expires_at', '<', $purgeBefore);
            })
            ->delete();

        $deletedEvents = DB::table('notification_events')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $purgeBefore)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('notification_recipients')
                    ->whereColumn('notification_recipients.notification_event_id', 'notification_events.id');
            })
            ->delete();

        $this->info(sprintf('Notificaciones vencidas marcadas: %d', $marked));
        $this->info(sprintf('Destinatarios purgados: %d', $deletedRecipients));
        $this->info(sprintf('Eventos purgados: %d', $deletedEvents));

        return self::SUCCESS;
    }
}
