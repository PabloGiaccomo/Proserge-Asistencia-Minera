<?php

namespace App\Providers;

use App\Modules\Seguridad\Services\RoleManagementService;
use App\Modules\Notificaciones\Services\NotificationInboxService;
use App\Models\NotificationUserSetting;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::if('allowed', function (string $module, string $action = 'ver'): bool {
            return PermissionMatrix::allows(session('user.permissions', []), $module, $action);
        });

        Blade::if('allowedDirect', function (string $module, string $action = 'ver'): bool {
            return PermissionMatrix::allowsDirect(session('user.permissions', []), $module, $action);
        });

        if (app()->runningInConsole()) {
            return;
        }

        try {
            app(RoleManagementService::class)->ensureBaseRoles();
        } catch (Throwable) {
            // No bloquear toda la app si la base de datos aun no esta disponible.
        }

        View::composer('partials.header', function ($view): void {
            $userId = (string) session('user.id', '');
            $schemaReady = Schema::hasTable('notification_recipients') && Schema::hasTable('notification_events');
            $notificationsAllowed = $this->notificationsAllowedForUser($userId);

            if ($userId === '' || !$schemaReady || !$notificationsAllowed) {
                $view->with('notificationsEnabled', false);
                $view->with('headerUnreadCount', 0);
                $view->with('headerNotifications', collect());
                return;
            }

            try {
                $inbox = app(NotificationInboxService::class);
                $view->with('notificationsEnabled', true);
                $view->with('headerUnreadCount', $inbox->unreadCount($userId));
                $view->with('headerNotifications', $inbox->latestForHeader($userId, 20));
            } catch (Throwable) {
                $view->with('notificationsEnabled', true);
                $view->with('headerUnreadCount', 0);
                $view->with('headerNotifications', collect());
            }
        });
    }

    private function notificationsAllowedForUser(string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        if (!Schema::hasTable('notification_user_settings')) {
            return PermissionMatrix::allows(session('user.permissions', []), 'notificaciones', 'ver');
        }

        try {
            $setting = NotificationUserSetting::query()
                ->where('usuario_id', $userId)
                ->first();
        } catch (Throwable) {
            return PermissionMatrix::allows(session('user.permissions', []), 'notificaciones', 'ver');
        }

        if (!$setting) {
            return PermissionMatrix::allows(session('user.permissions', []), 'notificaciones', 'ver');
        }

        return (bool) $setting->in_app_enabled
            && (!$setting->muted_until || now()->gte($setting->muted_until));
    }
}
