<?php

namespace App\Providers;

use App\Modules\Seguridad\Services\RoleManagementService;
use App\Modules\Notificaciones\Services\NotificationInboxService;
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

            if ($userId === '' || !$schemaReady) {
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
}
