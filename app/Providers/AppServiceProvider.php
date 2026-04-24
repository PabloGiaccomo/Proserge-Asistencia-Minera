<?php

namespace App\Providers;

use App\Modules\Seguridad\Services\RoleManagementService;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Support\Facades\Blade;
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
    }
}
