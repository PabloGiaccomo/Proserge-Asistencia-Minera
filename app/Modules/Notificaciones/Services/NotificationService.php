<?php

namespace App\Modules\Notificaciones\Services;

use App\Models\NotificationEvent;
use App\Models\NotificationRecipient;
use App\Models\NotificationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NotificationService
{
    public function __construct(private readonly NotificationRecipientResolverService $recipientResolver)
    {
    }

    public function emit(string $typeCode, array $context = []): ?NotificationEvent
    {
        if (!$this->notificationTablesReady()) {
            Log::warning('notificaciones.emit_tables_not_ready', [
                'type_code' => $typeCode,
            ]);

            return null;
        }

        Log::info('notificaciones.emit_started', [
            'type_code' => $typeCode,
            'actor_usuario_id' => $context['actor_user_id'] ?? null,
            'mine_id' => $context['mine_id'] ?? null,
            'entity_type' => $context['entity_type'] ?? null,
            'entity_id' => $context['entity_id'] ?? null,
            'dedupe_key' => $context['dedupe_key'] ?? null,
        ]);

        $type = NotificationType::query()
            ->where('code', $typeCode)
            ->where('is_active', true)
            ->first();

        if (!$type) {
            Log::warning('notificaciones.type_not_found_or_inactive', [
                'type_code' => $typeCode,
            ]);

            return null;
        }

        $priority = strtolower((string) ($context['priority'] ?? $type->default_priority ?? 'medium'));
        if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            $priority = 'medium';
        }

        $message = trim((string) ($context['message'] ?? ''));

        if ($message === '') {
            Log::warning('notificaciones.message_empty', [
                'type_code' => $typeCode,
                'type_id' => (string) $type->id,
            ]);

            return null;
        }

        $payload = $context['payload'] ?? [];
        $entityId = isset($context['entity_id']) ? (string) $context['entity_id'] : null;

        $title = (string) ($context['title'] ?? $type->default_title);
        $actionLabel = $context['action_label'] ?? $type->default_action_label;
        $actionRouteTemplate = $context['action_route'] ?? $type->default_action_route;
        $actionRoute = $this->compileRouteTemplate($actionRouteTemplate, $entityId, $payload);
        $dedupeKey = isset($context['dedupe_key']) ? (string) $context['dedupe_key'] : null;
        $recipients = $this->recipientResolver->resolve($type, [
            ...$context,
            'priority' => $priority,
            'category' => $context['category'] ?? $type->category,
        ])->map(fn ($id) => (string) $id)->filter()->unique()->values();

        Log::info('notificaciones.recipients_resolved', [
            'type_code' => $typeCode,
            'type_id' => (string) $type->id,
            'recipient_count' => $recipients->count(),
            'recipient_user_ids' => $recipients->values()->all(),
            'actor_usuario_id' => $context['actor_user_id'] ?? null,
            'mine_id' => $context['mine_id'] ?? null,
        ]);

        if ($recipients->isEmpty()) {
            Log::warning('notificaciones.no_recipients', [
                'type_code' => $typeCode,
                'type_id' => (string) $type->id,
                'actor_usuario_id' => $context['actor_user_id'] ?? null,
                'mine_id' => $context['mine_id'] ?? null,
            ]);

            return null;
        }

        $expiresAt = $context['expires_at'] ?? $this->resolveExpiresAt($priority, (string) ($context['category'] ?? $type->category));

        try {
            return DB::transaction(function () use ($type, $context, $priority, $title, $message, $actionLabel, $actionRoute, $entityId, $payload, $dedupeKey, $recipients, $expiresAt): NotificationEvent {
                if ($dedupeKey) {
                    $existing = NotificationEvent::query()->where('dedupe_key', $dedupeKey)->first();
                    if ($existing) {
                        $inserted = $this->insertRecipients((string) $existing->id, $recipients);

                        Log::info('notificaciones.dedupe_hit', [
                            'type_code' => $type->code,
                            'notification_event_id' => (string) $existing->id,
                            'dedupe_key' => $dedupeKey,
                            'recipient_sync_inserted' => $inserted,
                            'recipient_count' => $recipients->count(),
                        ]);

                        return $existing;
                    }
                }

                $event = NotificationEvent::query()->create([
                    'id' => (string) Str::uuid(),
                    'notification_type_id' => $type->id,
                    'actor_usuario_id' => $context['actor_user_id'] ?? null,
                    'mina_id' => $context['mine_id'] ?? null,
                    'module' => $context['module'] ?? $type->module,
                    'priority' => $priority,
                    'title' => $title,
                    'message' => $message,
                    'action_label' => $actionLabel,
                    'action_route' => $actionRoute,
                    'entity_type' => $context['entity_type'] ?? null,
                    'entity_id' => $entityId,
                    'payload' => $payload,
                    'dedupe_key' => $dedupeKey,
                    'occurred_at' => now(),
                    'expires_at' => $expiresAt,
                ]);

                $inserted = $this->insertRecipients((string) $event->id, $recipients);

                Log::info('notificaciones.event_created', [
                    'type_code' => $type->code,
                    'notification_event_id' => (string) $event->id,
                    'recipient_count' => $recipients->count(),
                    'recipient_inserted' => $inserted,
                    'recipient_user_ids' => $recipients->values()->all(),
                ]);

                return $event;
            });
        } catch (\Throwable $exception) {
            Log::error('notificaciones.emit_exception', [
                'type_code' => $typeCode,
                'type_id' => (string) $type->id,
                'actor_usuario_id' => $context['actor_user_id'] ?? null,
                'mine_id' => $context['mine_id'] ?? null,
                'error_message' => $exception->getMessage(),
                'error_trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    private function compileRouteTemplate(?string $template, ?string $entityId, array $payload): ?string
    {
        if (!$template) {
            return null;
        }

        $route = $template;
        if ($entityId) {
            $route = str_replace('{entity_id}', $entityId, $route);
        }

        foreach ($payload as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $route = str_replace('{' . $key . '}', (string) $value, $route);
        }

        return $route;
    }

    private function resolveExpiresAt(string $priority, string $category): \DateTimeInterface
    {
        if ($priority === 'critical' || $category === 'seguridad') {
            return now()->addDays(90);
        }

        if ($priority === 'low') {
            return now()->addDays(30);
        }

        return now()->addDays(60);
    }

    private function insertRecipients(string $eventId, Collection $recipientIds): int
    {
        if ($recipientIds->isEmpty()) {
            return 0;
        }

        $now = now();

        $rows = $recipientIds->map(fn (string $userId): array => [
            'id' => (string) Str::uuid(),
            'notification_event_id' => $eventId,
            'usuario_id' => $userId,
            'status' => 'UNREAD',
            'delivered_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        return NotificationRecipient::query()->insertOrIgnore($rows);
    }

    private function notificationTablesReady(): bool
    {
        return Schema::hasTable('notification_types')
            && Schema::hasTable('notification_events')
            && Schema::hasTable('notification_recipients');
    }
}
