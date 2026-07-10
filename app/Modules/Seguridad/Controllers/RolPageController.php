<?php

namespace App\Modules\Seguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Modules\Seguridad\Services\RoleManagementService;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RolPageController extends Controller
{
    public function __construct(
        private readonly RoleManagementService $service,
        private readonly NotificationService $notificationService,
    )
    {
    }

    public function index(): View
    {
        $this->assertDirectPermission('roles', 'ver');

        return view('seguridad.roles.index', [
            'roles' => $this->service->list(),
        ]);
    }

    public function create(): View
    {
        $this->assertDirectPermission('roles', 'crear');

        return view('seguridad.roles.create', $this->formData(new Rol([
            'estado' => 'ACTIVO',
            'permisos' => [],
        ]), 'create'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertDirectPermission('roles', 'crear');

        $validated = $this->validatePayload($request);
        $rol = $this->service->create($validated);

        $this->service->syncNotificationRolePreferences(
            $rol,
            $request->input('notification_type_ids', []),
            $request->input('notificaciones', [])
        );

        return redirect()->route('seguridad.roles.show', $rol->id)->with('success', 'Rol creado correctamente.');
    }

    public function show(string $id): View
    {
        $this->assertDirectPermission('roles', 'ver');

        $rol = $this->service->find($id);
        abort_if(!$rol, 404);

        return view('seguridad.roles.show', [
            'rol' => $rol,
            'modules' => $this->service->modules(),
            'actions' => $this->service->actions(),
            'moduleActions' => $this->service->moduleActions(),
            'notificationModules' => $this->service->notificationModules(),
            'notificationActions' => $this->service->notificationActions(),
            'notificationModuleActions' => $this->service->notificationModuleActions(),
                'notificationTypes' => $this->service->notificationTypes(),
                'notificationRolePreferences' => $this->service->notificationRolePreferences($rol),
            ]);
    }

    public function edit(string $id): View
    {
        $this->assertAnyDirectPermission('roles', ['editar', 'actualizar']);

        $rol = $this->service->find($id);
        abort_if(!$rol, 404);

        return view('seguridad.roles.edit', $this->formData($rol, 'edit'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $this->assertAnyDirectPermission('roles', ['editar', 'actualizar']);

        $rol = Rol::query()->findOrFail($id);
        $beforePermissions = PermissionMatrix::normalize($rol->permisos ?? []);
        $validated = $this->validatePayload($request, $rol);

        $currentEstado = strtoupper((string) $rol->estado);
        $nextEstado = strtoupper((string) ($validated['estado'] ?? $currentEstado));
        if ($currentEstado !== $nextEstado) {
            $this->assertDirectPermission('roles', $nextEstado === 'ACTIVO' ? 'activar' : 'desactivar');
        }

        $rol = $this->service->updateWithNotificationPreferences(
            $rol,
            $validated,
            $request->input('notification_type_ids', []),
            $request->input('notificaciones', [])
        );

        $this->notificationService->emit('rol_modificado', [
            'actor_user_id' => session('user.id'),
            'entity_type' => 'rol',
            'entity_id' => $rol->id,
            'title' => 'Rol actualizado',
            'message' => sprintf('El rol %s fue actualizado.', $rol->nombre),
            'target_user_ids' => $this->securityAudience('roles', ['administrar']),
            'priority' => 'critical',
            'category' => 'seguridad',
            'dedupe_key' => 'rol_modificado:' . $rol->id . ':' . now()->format('YmdHi'),
        ]);

        if (json_encode($beforePermissions) !== json_encode($rol->permisos ?? [])) {
            $this->notificationService->emit('permisos_modificados', [
                'actor_user_id' => session('user.id'),
                'entity_type' => 'rol',
                'entity_id' => $rol->id,
                'title' => 'Permisos de rol modificados',
                'message' => sprintf('Se modificaron permisos del rol %s.', $rol->nombre),
                'target_user_ids' => $this->securityAudience('roles', ['administrar']),
                'priority' => 'critical',
                'category' => 'seguridad',
                'dedupe_key' => 'permisos_modificados:' . $rol->id . ':' . now()->format('YmdHi'),
            ]);
        }

        return redirect()->route('seguridad.roles.edit', $rol->id)->with('success', 'Permisos guardados correctamente.');
    }

    public function duplicate(string $id): RedirectResponse
    {
        $this->assertDirectPermission('roles', 'duplicar');

        $rol = Rol::query()->findOrFail($id);
        $copy = $this->service->duplicate($rol);

        $route = PermissionMatrix::allowsDirectAny(session('user.permissions', []), 'roles', ['editar', 'actualizar'])
            ? 'seguridad.roles.edit'
            : 'seguridad.roles.show';

        return redirect()->route($route, $copy->id)->with('success', 'Rol duplicado correctamente.');
    }

    public function toggleEstado(string $id): RedirectResponse
    {
        $rol = Rol::query()->findOrFail($id);
        $targetAction = strtoupper((string) $rol->estado) === 'ACTIVO' ? 'desactivar' : 'activar';
        $this->assertDirectPermission('roles', $targetAction);

        try {
            $this->service->toggle($rol);
        } catch (ValidationException $exception) {
            return redirect()->route('seguridad.roles.index')->with('error', $this->validationMessage($exception));
        }

        return redirect()->route('seguridad.roles.index')->with('success', 'Estado del rol actualizado correctamente.');
    }

    private function validatePayload(Request $request, ?Rol $rol = null): array
    {
        $rules = [
            'nombre' => ['required', 'string', 'max:100', Rule::unique('roles', 'nombre')->ignore($rol?->id, 'id')],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'estado' => ['required', 'string', Rule::in(['ACTIVO', 'INACTIVO'])],
            'permisos_present' => ['nullable', 'in:1'],
            'permisos' => ['nullable', 'array'],
            'permisos.*' => ['nullable', 'array'],
            'permisos.*.*' => ['nullable'],
        ];

        if (Schema::hasTable('notification_types')) {
            $rules['notification_type_ids'] = ['nullable', 'array'];
            $rules['notification_type_ids.*'] = ['string', 'size:36', 'exists:notification_types,id'];
            $rules['notificaciones'] = ['nullable', 'array'];
        }

        $validated = $request->validate($rules);

        if (!$request->has('permisos')) {
            $validated['permisos'] = $rol && !$request->boolean('permisos_present')
                ? ($rol->permisos ?? [])
                : [];
        }

        return $validated;
    }

    private function formData(Rol $rol, string $mode): array
    {
        return [
            'rol' => $rol,
            'mode' => $mode,
            'modules' => $this->service->modules(),
            'actions' => $this->service->actions(),
            'moduleActions' => $this->service->moduleActions(),
            'notificationModules' => $this->service->notificationModules(),
            'notificationActions' => $this->service->notificationActions(),
            'notificationModuleActions' => $this->service->notificationModuleActions(),
            'notificationTypes' => $this->service->notificationTypes(),
            'notificationRolePreferences' => $this->service->notificationRolePreferences($rol),
        ];
    }

    private function securityAudience(string $module, array $actions): array
    {
        $query = Usuario::query()
            ->with(['rol:id,nombre,permisos', 'rolesAdicionales:id,nombre,permisos'])
            ->when(
                Schema::hasTable('usuarios') && Schema::hasColumn('usuarios', 'estado'),
                fn ($query) => $query->where('estado', 'ACTIVO')
            );

        return $query->get()
            ->filter(fn (Usuario $usuario) => PermissionMatrix::userCanDirectAny($usuario, $module, $actions))
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    private function validationMessage(ValidationException $exception): string
    {
        return (string) (collect($exception->errors())->flatten()->first() ?: 'No se pudo completar la accion.');
    }

    private function assertDirectPermission(string $module, string $action): void
    {
        abort_unless(
            PermissionMatrix::allowsDirect(session('user.permissions', []), $module, $action),
            403,
            'No tienes permiso para realizar esta accion.'
        );
    }

    private function assertAnyDirectPermission(string $module, array $actions): void
    {
        abort_unless(
            PermissionMatrix::allowsDirectAny(session('user.permissions', []), $module, $actions),
            403,
            'No tienes permiso para realizar esta accion.'
        );
    }
}
