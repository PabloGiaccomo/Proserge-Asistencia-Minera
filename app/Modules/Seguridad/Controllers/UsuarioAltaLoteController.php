<?php

namespace App\Modules\Seguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Modules\Seguridad\Requests\StoreUsuarioAltaLoteRequest;
use App\Modules\Seguridad\Services\RoleManagementService;
use App\Modules\Seguridad\Services\UsuarioAltaLoteService;
use App\Modules\Seguridad\Services\UsuarioTrabajadorSearchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UsuarioAltaLoteController extends Controller
{
    private const PRIVATE_HEADERS = [
        'Cache-Control' => 'private, no-store, max-age=0',
        'Pragma' => 'no-cache',
        'Referrer-Policy' => 'no-referrer',
    ];

    public function __construct(
        private readonly UsuarioAltaLoteService $lotes,
        private readonly UsuarioTrabajadorSearchService $trabajadores,
        private readonly RoleManagementService $roles,
    ) {}

    public function create(Request $request): View
    {
        $actor = $this->actor($request);
        $oldRows = $request->old('filas', []);
        $rows = is_array($oldRows) ? array_slice($oldRows, 0, UsuarioAltaLoteService::MAX_ROWS, true) : [];
        $ids = collect($rows)->filter(fn ($row): bool => is_array($row))
            ->pluck('personal_id')->filter(fn ($id): bool => is_string($id) && Str::isUuid($id))->all();
        $workers = collect($this->trabajadores->rowsForIds($ids))->keyBy('id');
        $selection = [];
        foreach ($rows as $index => $row) {
            $id = is_array($row) ? ($row['personal_id'] ?? null) : null;
            if (is_int($index) && is_string($id) && $worker = $workers->get($id)) {
                $selection[$index] = [
                    ...$worker,
                    'email' => is_string($row['email'] ?? null) ? $row['email'] : ($worker['correo_sugerido'] ?? ''),
                ];
            }
        }
        $oldLot = $request->old('lote_id');

        return view('seguridad.usuarios.lotes.create', [
            'roles' => $this->roles->active(),
            'loteId' => is_string($oldLot) && Str::isUuid($oldLot) ? $oldLot : (string) Str::uuid(),
            'seleccion' => $selection,
            'lotesRecientes' => $this->lotes->recent($actor),
            'maxFilas' => UsuarioAltaLoteService::MAX_ROWS,
            'hasEstadoColumn' => Schema::hasColumn('usuarios', 'estado'),
        ]);
    }

    public function store(StoreUsuarioAltaLoteRequest $request): RedirectResponse
    {
        $lot = $this->lotes->create($this->actor($request), $request->validated());

        return redirect()->route('usuarios.lotes.show', ['lote' => $lot]);
    }

    public function show(Request $request, string $lote): Response
    {
        $resultado = $this->lotes->result($this->actor($request), $lote);

        return response()->view('seguridad.usuarios.lotes.show', compact('resultado'), 200, self::PRIVATE_HEADERS);
    }

    public function export(Request $request, string $lote): StreamedResponse
    {
        $actor = $this->actor($request);
        $result = $this->lotes->result($actor, $lote);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Credenciales');
        $sheet->fromArray(['Trabajador', 'Usuario', 'Contraseña inicial'], null, 'A1');
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(38);
        $sheet->getColumnDimension('C')->setWidth(30);
        foreach ($result['filas'] as $index => $row) {
            $line = $index + 2;
            $sheet->setCellValueExplicit('A'.$line, $row['trabajador'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B'.$line, $row['usuario'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C'.$line, $row['contrasena'], DataType::TYPE_STRING);
        }
        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(false);
        Log::info('usuarios.alta_lote.exportacion', ['lote_id' => $lote, 'actor_id' => $actor->id]);

        return response()->streamDownload(function () use ($writer, $book): void {
            try {
                $writer->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, 'credenciales-usuarios-'.now()->format('Ymd-His').'.xlsx', [
            ...self::PRIVATE_HEADERS,
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function actor(Request $request): Usuario
    {
        $actor = $request->user();
        abort_unless(UsuarioAltaLoteService::canCreate($actor), 403);

        return $actor;
    }
}
