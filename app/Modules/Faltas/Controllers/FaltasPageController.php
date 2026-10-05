<?php

namespace App\Modules\Faltas\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Faltas\Services\FaltasService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FaltasPageController extends WebPageController
{
    public function __construct(private readonly FaltasService $service)
    {
    }

    public function index(Request $request): View
    {
        $user = $this->getUser();
        $filters = $request->validate([
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date', 'after_or_equal:fecha_desde'],
            'destino_tipo' => ['nullable', 'string', 'in:MINA,TALLER,OFICINA'],
            'destino_id' => ['nullable', 'string', 'size:36'],
            'estado' => ['nullable', 'string', 'in:ACTIVA,CORREGIDA,ANULADA'],
            'buscar' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters['fecha_desde'] ??= Carbon::today()->startOfMonth()->toDateString();
        $filters['fecha_hasta'] ??= Carbon::today()->toDateString();
        if (Carbon::parse($filters['fecha_desde'])->diffInDays(Carbon::parse($filters['fecha_hasta'])) > 366) {
            throw ValidationException::withMessages([
                'fecha_hasta' => 'Selecciona un periodo máximo de 12 meses para mantener el dashboard ágil.',
            ]);
        }

        $items = $this->service->list($user, $filters) ?? collect();
        $dashboard = $this->service->dashboard($items);
        $perPage = (int) ($filters['per_page'] ?? 10);
        $lastPage = max(1, (int) ceil($items->count() / $perPage));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $lastPage);
        $data = $items->slice(($page - 1) * $perPage, $perPage)->values();
        $pagination = [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $items->count(),
            'last_page' => $lastPage,
            'from' => $items->isEmpty() ? 0 : (($page - 1) * $perPage) + 1,
            'to' => min($page * $perPage, $items->count()),
        ];

        return view('faltas.index', compact('data', 'dashboard', 'filters', 'pagination'));
    }

    public function show(string $id): View
    {
        $user = $this->getUser();
        $item = $this->service->findForUser($user, $id);
        
        return view('faltas.show', compact('item'));
    }

    public function corregir(string $id): View
    {
        $user = $this->getUser();
        $item = $this->service->findForUser($user, $id);
        
        return view('faltas.corregir', compact('item'));
    }

    public function corregirPost(Request $request, string $id): RedirectResponse
    {
        $user = $this->getUser();
        $payload = $request->validate([
            'motivo_correccion' => ['required', 'string', 'min:5', 'max:1000'],
            'hora_marcado' => ['nullable', 'date_format:H:i'],
        ]);
        $result = $this->service->corregir($user, $id, $payload);
        
        if ($result['success']) {
            return redirect()->route('faltas.show', $id)->with('success', $result['message']);
        }
        
        return back()->with('error', $result['message']);
    }

    public function anular(Request $request, string $id): RedirectResponse
    {
        $user = $this->getUser();
        $payload = $request->validate([
            'motivo_anulacion' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $result = $this->service->anularPorId($user, $id, $payload['motivo_anulacion']);
        
        if ($result['success']) {
            return redirect()->route('faltas.index')->with('success', $result['message']);
        }
        
        return back()->with('error', $result['message']);
    }
}
