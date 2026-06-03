<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationUserSetting extends Model
{
    protected $table = 'notification_user_settings';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'usuario_id',
        'in_app_enabled',
        'email_enabled',
        'muted_until',
        'updated_by_usuario_id',
    ];

    protected $casts = [
        'in_app_enabled' => 'boolean',
        'email_enabled' => 'boolean',
        'muted_until' => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'updated_by_usuario_id');
    }
}
