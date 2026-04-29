<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationRecipient extends Model
{
    protected $table = 'notification_recipients';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'notification_event_id',
        'usuario_id',
        'status',
        'delivered_at',
        'read_at',
        'archived_at',
        'actioned_at',
    ];

    protected $casts = [
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'archived_at' => 'datetime',
        'actioned_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class, 'notification_event_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
