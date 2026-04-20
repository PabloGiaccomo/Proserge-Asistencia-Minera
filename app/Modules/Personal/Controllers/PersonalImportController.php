<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Personal;
use App\Models\Mina;
use App\Models\PersonalMina;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class PersonalImportController extends Controller
{
    private function normalizarDni($valor)
    {
        if ($valor === null || $valor === '') return '';

        $dni = trim($valor);

        if (preg_match('/^\d+(\.0+)?$/', $dni)) {
            $dni = str_replace('.0', '', $dni);
        }

        $dni = preg_replace('/\s+/', '', $dni);
        $dni = preg_replace('/[^\d]/', '', $dni);

        if (strlen($dni) > 0 && strlen($dni) < 8) {
            $dni = str_pad($dni, 8, '0', STR_PAD_LEFT);
        }

        return $dni;
    }

    private function normalizarTexto($valor)
    {
        if ($valor === null || $valor === undefined) return '';
        return trim(strval($valor));
    }

    private function normalizarFecha($valor)
    {
        if ($valor === null || $valor === '' || $valor === undefined) return null;

        if ($valor instanceof \DateTime) {
            return $valor->format('Y-m-d');
        }

        $texto = trim(strval($valor));
        if (!$texto) return null;

        $fecha = new \DateTime($texto);
        if ($fecha && $fecha->format('Y') !== '0001') {
            return $fecha->format('Y-m-d');
        }

        return null;
    }

    private function esSupervisor($ocupacion)
    {
        if (!$ocupacion) return false;
        $op = strtoupper(trim($ocupacion));
        return $op === 'E' || $op === 'P';
    }

    private function normalizarContrato($valor)
    {
        $texto = strtoupper(trim($this->normalizarTexto($valor)));
        if (!$texto) return 'REG';

        if (in_array($texto, ['SE', 'REG', 'INTER', 'INDET'])) {
            return $texto;
        }

        return 'REG';
    }

    private function slugMina($nombre)
    {
        $slug = preg_replace('/[\x{0300}-\x{036f}]/u', '', $nombre);
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = preg_replace('/^-+|-+$/', '', $slug);
        return substr($slug, 0, 60);
    }

    private function parseEstadoMina($valor)
    {
        $texto = strtoupper($this->normalizarTexto($valor));

        if (!$texto) return null;

        if (strpos($texto, 'NO HABILITADO') !== false) return 'NO_HABILITADO';
        if (strpos($texto, 'EN PROCESO') !== false) return 'EN_PROCESO';
        if (strpos($texto, 'HABILITADO') !== false) return 'HABILITADO';

        return null;
    }

    private function esColumnaUnidadMinera($header, $rows, $columnIndex)
    {
        $nombre = strtoupper($this->normalizarTexto($header));
        if (!$nombre) return false;

        $columnasDescartadas = [
            'N°', 'CONTRATO', 'OCUPACION', 'CC', 'DNI', 'DNI CONTEO',
            'APELLIDOS Y NOMBRES', 'CARGO / PUESTO', 'CARGO GENERAL',
            'FECHA INGRESO', 'FECHA INGRESO INICIAL', 'FIN DE CONTRATO',
            'CELULAR PARTICULAR'
        ];

        if (in_array($nombre, $columnasDescartadas)) return false;

        $muestra = array_slice($rows, 0, 50);
        $coincidenciasEstado = 0;
        $celdasConValor = 0;

        foreach ($muestra as $row) {
            $valor = $row[$columnIndex] ?? null;
            $texto = $this->normalizarTexto($valor);
            if (!$texto) continue;

            $celdasConValor++;
            if ($this->parseEstadoMina($texto)) {
                $coincidenciasEstado++;
            }
        }

        if ($celdasConValor === 0) return false;

        return $coincidenciasEstado > 0;
    }

    public function showImportForm()
    {
        return view('personal.import');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls'
        ]);

        try {
            $file = $request->file('file');
            $spreadsheet = IOFactory::load($file->getRealPath());
            
            $sheetName = null;
            foreach ($spreadsheet->getSheetNames() as $name) {
                if (strtoupper(trim($name)) === 'RESUMEN GRAL') {
                    $sheetName = $name;
                    break;
                }
            }
            
            if (!$sheetName) {
                $sheetName = $spreadsheet->getSheetNames()[0];
            }

            $worksheet = $spreadsheet->getSheetByName($sheetName);
            if (!$worksheet) {
                return back()->with('error', 'No se encontró una hoja válida');
            }

            $dataRaw = $worksheet->toArray(null, true, true, true);

            if (count($dataRaw) < 2) {
                return back()->with('error', 'El archivo está vacío');
            }

            $headers = array_map(fn($h, $idx) => ['index' => $idx, 'value' => trim($h ?? '')], $dataRaw[0], array_keys($dataRaw[0]));
            
            $data = array_slice($dataRaw, 1);
            $data = array_filter($data, fn($row) => $row && is_array($row) && count($row) > 0 && array_filter($row, fn($cell) => trim($cell ?? '') !== ''));

            $colDni = null;
            $colNombre = null;
            $colPuesto = null;
            $colFecha = null;
            $colOcupacion = null;
            $colContrato = null;

            foreach ($headers as $h) {
                $val = strtoupper($h['value']);
                if (strpos($val, 'DNI') !== false && $colDni === null) $colDni = $h['index'];
                if ((strpos($val, 'NOMBRE') !== false || strpos($val, 'APELLIDO') !== false) && $colNombre === null) $colNombre = $h['index'];
                if ((strpos($val, 'PUESTO') !== false || strpos($val, 'CARGO') !== false) && $colPuesto === null) $colPuesto = $h['index'];
                if (strpos($val, 'FECHA') !== false && strpos($val, 'INGRESO') !== false && $colFecha === null) $colFecha = $h['index'];
                if (strpos($val, 'OCUP') !== false && $colOcupacion === null) $colOcupacion = $h['index'];
                if ($val === 'CONTRATO' && $colContrato === null) $colContrato = $h['index'];
            }

            if ($colDni === null) $colDni = 'D';
            if ($colNombre === null) $colNombre = 'G';
            if ($colPuesto === null) $colPuesto = 'H';
            if ($colFecha === null) $colFecha = 'J';
            if ($colOcupacion === null) $colOcupacion = 'C';
            if ($colContrato === null) $colContrato = 'B';

            $cols = array_keys($dataRaw[0]);
            $getCol = function($letter) use ($cols) {
                $idx = array_search($letter, $cols);
                return $idx !== false ? $idx : $letter;
            };

            $colDni = $getCol($colDni);
            $colNombre = $getCol($colNombre);
            $colPuesto = $getCol($colPuesto);
            $colFecha = $getCol($colFecha);
            $colOcupacion = $getCol($colOcupacion);
            $colContrato = $getCol($colContrato);

            $minasHeaders = [];
            foreach ($headers as $h) {
                if ($h['index'] >= 13 && $this->esColumnaUnidadMinera($h['value'], $data, $h['index'])) {
                    $minasHeaders[] = $h;
                }
            }

            $nombresMinasExcel = array_map(fn($h) => strtoupper($this->normalizarTexto($h['value'])), $minasHeaders);
            $nombresMinasExcel = array_filter($nombresMinasExcel);

            $minasBd = Mina::select('id', 'nombre', 'unidad_minera', 'estado')->get();
            foreach ($minasBd as $minaBd) {
                $claveBd = strtoupper($this->normalizarTexto($minaBd->unidad_minera ?: $minaBd->nombre));
                if (!in_array($claveBd, $nombresMinasExcel)) {
                    Mina::where('id', $minaBd->id)->update(['estado' => 'INACTIVO']);
                }
            }

            $minasMap = [];
            foreach ($minasHeaders as $h) {
                $nombre = $this->normalizarTexto($h['value']);
                if (!$nombre) continue;

                $idMina = 'mina-' . $this->slugMina($nombre);

                $mina = Mina::updateOrCreate(
                    ['id' => $idMina],
                    [
                        'nombre' => $nombre,
                        'unidad_minera' => $nombre,
                        'ubicacion' => 'Por definir',
                        'estado' => 'ACTIVO'
                    ]
                );

                $minasMap[strtoupper($nombre)] = [
                    'id' => $mina->id,
                    'nombre' => $mina->nombre,
                    'indice' => $h['index']
                ];
            }

            $resultados = [
                'nuevos' => 0,
                'reactivados' => 0,
                'inactivados' => 0,
                'puestosActualizados' => 0,
                'omitidos' => 0,
                'duplicados' => 0,
                'minasActivasDetectadas' => count($minasMap),
                'relacionesMinaCreadasOActualizadas' => 0,
                'relacionesMinaEliminadas' => 0
            ];

            $personalExistente = Personal::select('id', 'dni', 'nombre_completo', 'puesto', 'ocupacion', 'contrato', 'es_supervisor', 'estado')->get();
            $personalPorDni = [];
            $dnisEnBd = [];
            foreach ($personalExistente as $p) {
                $personalPorDni[$p->dni] = $p;
                if ($p->estado === 'ACTIVO') {
                    $dnisEnBd[] = $p->dni;
                }
            }

            $dnisProcesados = [];

            foreach ($data as $row) {
                $colDniVal = $row[$colDni] ?? '';
                $colNombreVal = $row[$colNombre] ?? '';
                $colPuestoVal = $row[$colPuesto] ?? '';
                $colFechaVal = $row[$colFecha] ?? '';
                $colOcupacionVal = $row[$colOcupacion] ?? '';
                $colContratoVal = $row[$colContrato] ?? '';

                $dni = $this->normalizarDni($colDniVal);
                $nombreCompleto = $this->normalizarTexto($colNombreVal);
                $puesto = $this->normalizarTexto($colPuestoVal);
                $fechaIng = $colFechaVal;
                $ocupacion = $this->normalizarTexto($colOcupacionVal);
                $contrato = $this->normalizarContrato($colContratoVal);

                if (!$dni || strlen($dni) !== 8) {
                    $resultados['omitidos']++;
                    continue;
                }

                if (isset($dnisProcesados[$dni])) {
                    $resultados['duplicados']++;
                    continue;
                }

                $dnisProcesados[$dni] = true;

                $nombre = $nombreCompleto ?: 'Sin nombre';
                $cargo = $puesto ?: 'Sin puesto';
                $fechaNormalizada = $this->normalizarFecha($fechaIng);

                $personalActual = $personalPorDni[$dni] ?? null;

                if ($personalActual) {
                    $dataUpdate = [];
                    $huboCambio = false;

                    if ($personalActual->estado === 'INACTIVO') {
                        $dataUpdate['estado'] = 'ACTIVO';
                        $resultados['reactivados']++;
                        $huboCambio = true;
                    }

                    if ($nombre && $personalActual->nombre_completo !== $nombre) {
                        $dataUpdate['nombre_completo'] = $nombre;
                        $huboCambio = true;
                    }

                    if ($cargo && $personalActual->puesto !== $cargo) {
                        $dataUpdate['puesto'] = $cargo;
                        $resultados['puestosActualizados']++;
                        $huboCambio = true;
                    }

                    if ($ocupacion && $personalActual->ocupacion !== $ocupacion) {
                        $dataUpdate['ocupacion'] = $ocupacion;
                        $huboCambio = true;
                    }

                    if ($contrato && $personalActual->contrato !== $contrato) {
                        $dataUpdate['contrato'] = $contrato;
                        $huboCambio = true;
                    }

                    $nuevoEsSupervisor = $this->esSupervisor($ocupacion);
                    if ($personalActual->es_supervisor != $nuevoEsSupervisor) {
                        $dataUpdate['es_supervisor'] = $nuevoEsSupervisor;
                        $huboCambio = true;
                    }

                    if ($fechaNormalizada) {
                        $dataUpdate['fecha_ingreso'] = $fechaNormalizada;
                        $huboCambio = true;
                    }

                    if ($huboCambio) {
                        $actualizado = Personal::where('dni', $dni)->update($dataUpdate);
                        $personalActual = (object) array_merge((array)$personalActual, $dataUpdate);
                        $personalPorDni[$dni] = $personalActual;
                    }
                } else {
                    $qrCode = 'QR-' . $dni . '-' . time();

                    Personal::create([
                        'id' => 'personal-' . $dni,
                        'dni' => $dni,
                        'nombre_completo' => $nombre,
                        'puesto' => $cargo,
                        'ocupacion' => $ocupacion,
                        'contrato' => $contrato,
                        'es_supervisor' => $this->esSupervisor($ocupacion),
                        'qr_code' => $qrCode,
                        'fecha_ingreso' => $fechaNormalizada,
                        'estado' => 'ACTIVO'
                    ]);

                    $personalActual = (object)[
                        'id' => 'personal-' . $dni,
                        'dni' => $dni
                    ];
                    $personalPorDni[$dni] = $personalActual;
                    $resultados['nuevos']++;
                }

                if (!$personalActual) continue;

                foreach ($minasMap as $minaData) {
                    $estadoCelda = isset($row[$minaData['indice']]) ? $this->parseEstadoMina($row[$minaData['indice']]) : null;

                    if (!$estadoCelda || $estadoCelda === 'NO_HABILITADO') {
                        $eliminado = PersonalMina::where('personal_id', $personalActual->id)
                            ->where('mina_id', $minaData['id'])
                            ->delete();
                        if ($eliminado > 0) {
                            $resultados['relacionesMinaEliminadas'] += $eliminado;
                        }
                        continue;
                    }

                    PersonalMina::updateOrCreate(
                        [
                            'personal_id' => $personalActual->id,
                            'mina_id' => $minaData['id']
                        ],
                        [
                            'estado' => $estadoCelda
                        ]
                    );

                    $resultados['relacionesMinaCreadasOActualizadas']++;
                }
            }

            foreach ($dnisEnBd as $dniBd) {
                if (!isset($dnisProcesados[$dniBd])) {
                    Personal::where('dni', $dniBd)->update(['estado' => 'INACTIVO']);
                    $resultados['inactivados']++;
                }
            }

            return back()->with('success', 'Importación completada: ' . $resultados['nuevos'] . ' nuevos, ' . $resultados['reactivados'] . ' reactivados, ' . $resultados['inactivados'] . ' inactivados');

        } catch (\Exception $e) {
            return back()->with('error', 'Error al procesar el archivo: ' . $e->getMessage());
        }
    }
}
