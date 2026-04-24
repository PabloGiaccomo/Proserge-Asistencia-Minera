<?php

namespace App\Modules\Seguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioMinaScope;
use App\Modules\Seguridad\Services\RoleManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UsuarioPageController extends Controller
{
    public function __construct(private readonly RoleManagementService $roleService)
    {
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $estado = strtoupper(trim((string) $request->query('estado', '')));

        $query = Usuario::query()
            ->with([
                'personal:id,dni,nombre_completo,puesto',
                'rol:id,nombre',
                'scopesMina:id,usuario_id,mina_id',
            ]);

        if ($search !== '') {
            $needle = '%' . mb_strtolower($search) . '%';

            $query->where(function ($sub) use ($needle): void {
                $sub->whereRaw('LOWER(email) LIKE ?', [$needle])
                    ->orWhereHas('personal', function ($personalQuery) use ($needle): void {
                        $personalQuery->whereRaw('LOWER(nombre_completo) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(dni) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(puesto) LIKE ?', [$needle]);
                    })
                    ->orWhereHas('rol', function ($rolQuery) use ($needle): void {
                        $rolQuery->whereRaw('LOWER(nombre) LIKE ?', [$needle]);
                    });
            });
        }

        if ($this->hasEstadoColumn() && in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
            $query->where('estado', $estado);
        }

        $usuarios = $query->orderBy('created_at', 'desc')->get();

        return view('seguridad.usuarios.index', [
            'usuarios' => $usuarios,
            'filters' => [
                'search' => $search,
                'estado' => $estado,
            ],
            'hasEstadoColumn' => $this->hasEstadoColumn(),
        ]);
    }

    public function create(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $trabajadorId = trim((string) $request->query('trabajador_id', ''));

        $roles = $this->roleService->active();

        $trabajadores = collect();
        if ($search !== '') {
            $needle = '%' . mb_strtolower($search) . '%';

            $trabajadores = Personal::query()
                ->with('usuario:id,personal_id')
                ->where(function ($query) use ($needle): void {
                    $query->whereRaw('LOWER(nombre_completo) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(dni) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(puesto) LIKE ?', [$needle]);
                })
                ->orderBy('nombre_completo')
                ->limit(20)
                ->get(['id', 'dni', 'nombre_completo', 'puesto', 'correo', 'estado']);
        }

        $trabajadorSeleccionado = null;
        if ($trabajadorId !== '') {
            $trabajadorSeleccionado = Personal::query()
                ->with('usuario.rol:id,nombre')
                ->find($trabajadorId, ['id', 'dni', 'nombre_completo', 'puesto', 'correo', 'estado']);
        }

        return view('seguridad.usuarios.create', [
            'roles' => $roles,
            'trabajadores' => $trabajadores,
            'trabajadorSeleccionado' => $trabajadorSeleccionado,
            'search' => $search,
            'hasEstadoColumn' => $this->hasEstadoColumn(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'personal_id' => [
                'required',
                'string',
                'size:36',
                'exists:personal,id',
                Rule::unique('usuarios', 'personal_id'),
            ],
            'email' => ['required', 'email', 'max:191', Rule::unique('usuarios', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'rol_id' => ['required', 'string', 'size:36', 'exists:roles,id'],
            'estado' => ['nullable', 'string', Rule::in(['ACTIVO', 'INACTIVO'])],
        ], [
            'personal_id.unique' => 'Este trabajador ya tiene un usuario registrado.',
        ]);

        $trabajador = Personal::query()->findOrFail($validated['personal_id']);

        if (Usuario::query()->where('personal_id', $trabajador->id)->exists()) {
            return redirect()
                ->route('usuarios.create', ['trabajador_id' => $trabajador->id])
                ->with('error', 'Este trabajador ya tiene un usuario registrado.');
        }

        $payload = [
            'id' => (string) Str::uuid(),
            'personal_id' => $trabajador->id,
            'email' => mb_strtolower($validated['email']),
            'password' => $validated['password'],
            'rol_id' => $validated['rol_id'],
        ];

        if ($this->hasEstadoColumn()) {
            $payload['estado'] = strtoupper((string) ($validated['estado'] ?? 'ACTIVO'));
        }

        $usuario = Usuario::query()->create($payload);

        return redirect()
            ->route('usuarios.show', $usuario->id)
            ->with('success', 'Usuario creado correctamente y vinculado al trabajador seleccionado.');
    }

    public function show(string $id): View
    {
        $usuario = $this->findUsuarioOrFail($id);

        $roles = $this->roleService->active();

        return view('seguridad.usuarios.show', [
            'usuario' => $usuario,
            'roles' => $roles,
            'hasEstadoColumn' => $this->hasEstadoColumn(),
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->findUsuarioOrFail($id);

        $rules = [
            'email' => ['required', 'email', 'max:191', Rule::unique('usuarios', 'email')->ignore($usuario->id, 'id')],
            'rol_id' => ['required', 'string', 'size:36', 'exists:roles,id'],
        ];

        if ($this->hasEstadoColumn()) {
            $rules['estado'] = ['required', 'string', Rule::in(['ACTIVO', 'INACTIVO'])];
        }

        $validated = $request->validate($rules);

        $usuario->email = mb_strtolower($validated['email']);
        $usuario->rol_id = $validated['rol_id'];

        if ($this->hasEstadoColumn()) {
            $usuario->estado = strtoupper((string) $validated['estado']);
        }

        $usuario->save();

        return redirect()->route('usuarios.show', $usuario->id)->with('success', 'Usuario actualizado correctamente.');
    }

    public function updatePassword(Request $request, string $id): RedirectResponse
    {
        $usuario = $this->findUsuarioOrFail($id);

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $usuario->password = $validated['password'];
        $usuario->save();

        return redirect()->route('usuarios.show', $usuario->id)->with('success', 'Contraseña actualizada correctamente.');
    }

    public function toggleEstado(string $id): RedirectResponse
    {
        $usuario = $this->findUsuarioOrFail($id);

        if (!$this->hasEstadoColumn()) {
            return redirect()->route('usuarios.index')->with('error', 'La tabla de usuarios aun no tiene columna de estado. Ejecuta las migraciones pendientes.');
        }

        $usuario->estado = strtoupper((string) $usuario->estado) === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO';
        $usuario->save();

        return redirect()->route('usuarios.index')->with('success', 'Estado del usuario actualizado correctamente.');
    }

    public function editarScope(string $usuarioId): View
    {
        $usuario = $this->findUsuarioOrFail($usuarioId);
        $minas = Mina::query()->orderBy('nombre')->get(['id', 'nombre', 'estado']);
        $scopeIds = $usuario->scopesMina->pluck('mina_id')->all();

        return view('seguridad.usuarios.scope', [
            'usuario' => $usuario,
            'minas' => $minas,
            'scopeIds' => $scopeIds,
        ]);
    }

    public function syncScope(Request $request, string $usuarioId): RedirectResponse
    {
        $usuario = $this->findUsuarioOrFail($usuarioId);

        $validated = $request->validate([
            'mina_ids' => ['nullable', 'array'],
            'mina_ids.*' => ['string', 'size:36', 'exists:minas,id'],
        ]);

        $minaIds = collect($validated['mina_ids'] ?? [])
            ->map(fn (string $id) => trim($id))
            ->filter(fn (string $id) => $id !== '')
            ->unique()
            ->values();

        DB::transaction(function () use ($usuario, $minaIds): void {
            UsuarioMinaScope::query()->where('usuario_id', $usuario->id)->delete();

            if ($minaIds->isEmpty()) {
                return;
            }

            UsuarioMinaScope::query()->insert(
                $minaIds->map(fn (string $minaId) => [
                    'id' => (string) Str::uuid(),
                    'usuario_id' => $usuario->id,
                    'mina_id' => $minaId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all()
            );
        });

        return redirect()->route('usuarios.show', $usuario->id)->with('success', 'Scope Mina actualizado correctamente.');
    }

    private function findUsuarioOrFail(string $id): Usuario
    {
        return Usuario::query()
            ->with([
                'personal:id,dni,nombre_completo,puesto,correo,estado',
                'rol:id,nombre',
                'scopesMina.mina:id,nombre',
            ])
            ->findOrFail($id);
    }

    private function hasEstadoColumn(): bool
    {
        return Schema::hasTable('usuarios') && Schema::hasColumn('usuarios', 'estado');
    }
}
