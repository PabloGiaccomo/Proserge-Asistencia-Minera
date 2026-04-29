<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationType extends Model
{
    protected $table = 'notification_types';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'code',
        'module',
        'category',
        'default_priority',
        'required_permission_module',
        'required_permission_action',
        'default_title',
        'default_action_label',
        'default_action_route',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(NotificationEvent::class, 'notification_type_id');
    }
}
