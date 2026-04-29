<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationEvent extends Model
{
    protected $table = 'notification_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'notification_type_id',
        'actor_usuario_id',
        'mina_id',
        'module',
        'priority',
        'title',
        'message',
        'action_label',
        'action_route',
        'entity_type',
        'entity_id',
        'payload',
        'dedupe_key',
        'occurred_at',
        'expires_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(NotificationType::class, 'notification_type_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'actor_usuario_id');
    }

    public function mina(): BelongsTo
    {
        return $this->belongsTo(Mina::class, 'mina_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class, 'notification_event_id');
    }
}
