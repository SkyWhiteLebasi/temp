<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\CentroGestor;
use App\Models\Topico;
use App\Models\User;
use App\Models\Actividade;
use App\Models\Turno;
use App\Models\Consultorio;
use App\Models\MatrizManual;
use App\Models\Programacione;
use App\Models\Asignacione;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

class ImportacionController extends Controller
{
    public function importarProgramacion(Request $request)
    {
        // Aumentar el límite de tiempo y memoria para archivos grandes (ej: 856 filas)
        set_time_limit(0);
        ini_set('memory_limit', '2048M');

        $request->validate([
            'file' => 'required|file|mimes:csv,txt'
        ]);

        $file = $request->file('file');
        $content = file_get_contents($file->getRealPath());
        $lines = explode("\n", $content);

        if (count($lines) < 2) {
            return response()->json(['success' => false, 'message' => 'El archivo está vacío o no tiene el formato correcto.'], 400);
        }

        // Limpiar headers (remover \r)
        $headers = explode('|', trim($lines[0]));

        $imported = 0;

        try {
            DB::beginTransaction();

            $medicoRole = Role::where('name', 'MEDICO')->first();

            // Cache arrays to reduce DB queries for repetitive data
            $centros = [];
            $topicos = [];
            $actividades = [];
            $turnos = [];
            $consultorios = [];
            $usuarios = [];
            $matrices = [];
            $programaciones = [];

            for ($i = 1; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if (empty($line))
                    continue;

                $data = explode('|', $line);
                if (count($data) < 25)
                    continue; // Basic check

                // Create associative array
                $row = [];
                foreach ($headers as $index => $header) {
                    $row[$header] = $data[$index] ?? null;
                }

                // 1. CENTRO GESTOR
                $centroName = $row['CENTRO'] ?? '';
                if (!isset($centros[$centroName])) {
                    $centro = CentroGestor::where('descengest', 'LIKE', '%' . $centroName . '%')
                        ->orWhere('abreviatura', 'LIKE', '%' . $centroName . '%')
                        ->first();

                    if (!$centro) {
                        // Obtener cualquier gerencia para asociar (o crear una por defecto)
                        $gerencia = \App\Models\Gerencia::first();
                        if (!$gerencia) {
                            $gerencia = \App\Models\Gerencia::create([
                                'id' => (string) Str::uuid(),
                                'codgerencia' => 'GER-' . strtoupper(Str::random(4)),
                                'desgerencia' => 'GERENCIA IMPORTADA',
                                'status' => 1
                            ]);
                        }

                        $centro = CentroGestor::create([
                            'id' => (string) Str::uuid(),
                            'codcengest' => 'CG-' . strtoupper(Str::random(4)),
                            'descengest' => $centroName,
                            'abreviatura' => substr($centroName, 0, 10),
                            'gerencia_id' => $gerencia->id,
                            'status' => 1
                        ]);
                    }
                    $centros[$centroName] = $centro;
                }
                $centroGestor = $centros[$centroName];

                // 2. TOPICO (SERVICIO)
                $codSer = $row['CODSER'] ?? '';
                $servicio = $row['SERVICIO'] ?? '';
                $topicoKey = $codSer . '-' . $servicio;
                if (!isset($topicos[$topicoKey])) {
                    $topico = Topico::where('codservintern', $codSer)->first();
                    if (!$topico) {
                        $topico = Topico::create([
                            'id' => (string) Str::uuid(),
                            'codservintern' => $codSer,
                            'servintern' => $servicio,
                            'status' => 1
                        ]);
                    }
                    $topicos[$topicoKey] = $topico;
                }
                $topicoModel = $topicos[$topicoKey];

                // 3. ACTIVIDAD
                $actividadName = $row['SUBACTIVIDAD'] ?? '';
                $actividadDesc = $row['ACTIVIDAD'] ?? '';
                $actKey = $actividadName . '-' . $centroGestor->id;
                if (!isset($actividades[$actKey])) {
                    $actividad = Actividade::where('nombre', $actividadName)->where('centro_id', $centroGestor->id)->first();
                    if (!$actividad) {
                        $actividad = Actividade::create([
                            'id' => (string) Str::uuid(),
                            'nombre' => $actividadName,
                            'descripcion' => $actividadDesc,
                            'centro_id' => $centroGestor->id,
                            'status' => 1
                        ]);
                    }
                    $actividades[$actKey] = $actividad;
                }
                $actividadModel = $actividades[$actKey];

                // 4. TURNO
                $horaIni = $row['HORA_INI'] ?? '';
                $horaFin = $row['HORA_FIN'] ?? '';
                $turnoNombre = $row['TURNO'] ?? ($horaIni . ' - ' . $horaFin);
                $turnoKey = $turnoNombre . '-' . $centroGestor->id;
                if (!isset($turnos[$turnoKey])) {
                    $turno = Turno::where('nombre', $turnoNombre)->where('centro_id', $centroGestor->id)->first();
                    if (!$turno) {
                        $turno = Turno::create([
                            'id' => (string) Str::uuid(),
                            'nombre' => $turnoNombre,
                            'hora_inicio' => $horaIni,
                            'hora_fin' => $horaFin,
                            'centro_id' => $centroGestor->id
                        ]);
                    }
                    $turnos[$turnoKey] = $turno;
                }
                $turnoModel = $turnos[$turnoKey];

                // 5. CONSULTORIO
                $consultorioNombre = $row['CONSULTORIO'] ?? '';
                $codConsult = $row['COD_CONSULT'] ?? '';
                $consultorioKey = $consultorioNombre . '-' . $centroGestor->id;
                if (!isset($consultorios[$consultorioKey])) {
                    $consultorio = Consultorio::where('nombre', $consultorioNombre)->where('centro_id', $centroGestor->id)->first();
                    if (!$consultorio) {
                        $consultorio = Consultorio::create([
                            'id' => (string) Str::uuid(),
                            'nombre' => $consultorioNombre,
                            'descripcion' => $codConsult,
                            'centro_id' => $centroGestor->id
                        ]);
                    }
                    $consultorios[$consultorioKey] = $consultorio;
                }
                $consultorioModel = $consultorios[$consultorioKey];

                // 6. USUARIO (MEDICO)
                $dni = trim($row['DOC_PROFESIONAL'] ?? '');
                if (empty($dni)) {
                    continue; // Evitar errores si viene una fila sin DNI
                }

                $nombreProf = $row['PROFESIONAL'] ?? '';
                if (!isset($usuarios[$dni])) {
                    // Buscar por DNI o por user_name para evitar el error de Duplicate entry
                    $usuario = User::where('dni', $dni)->orWhere('user_name', $dni)->first();

                    // Siempre asignaremos esta ruta, exista o no la imagen físicamente en este momento
                    $imagePath = 'users/' . $dni . '.jpg';

                    if (!$usuario) {
                        // Intentar separar nombre (Paterno Materno Nombres)
                        $parts = explode(' ', $nombreProf);
                        $paterno = $parts[0] ?? '';
                        $materno = isset($parts[1]) ? $parts[1] : '';
                        $nombres = isset($parts[2]) ? implode(' ', array_slice($parts, 2)) : 'Nombres';

                        $usuario = User::create([
                            'id' => (string) Str::uuid(),
                            'user_name' => $dni,
                            'dni' => $dni,
                            'name' => $nombres,
                            'first_lastname' => $paterno,
                            'second_lastname' => $materno,
                            'email' => $dni . '@importado.com',
                            'password' => Hash::make($dni),
                            'centro_id' => $centroGestor->id,
                            'gerencia_id' => $centroGestor->gerencia_id,
                            'abreviatura' => substr($nombres, 0, 1) .substr($paterno, 0, 1) .substr($materno, 0, 1),
                            'imagen' => $imagePath
                        ]);

                        if ($medicoRole) {
                            $usuario->roles()->attach($medicoRole->id);
                        }
                    } else {
                        $needsSave = false;
                        // Si el usuario ya existe pero no tiene la ruta estandarizada, se la actualizamos
                        if ($usuario->imagen !== $imagePath) {
                            $usuario->imagen = $imagePath;
                            $needsSave = true;
                        }
                        // Si el usuario existía por user_name pero no tenía DNI, lo actualizamos
                        if (empty($usuario->dni)) {
                            $usuario->dni = $dni;
                            $needsSave = true;
                        }
                        if ($needsSave) {
                            $usuario->save();
                        }
                    }
                    $usuarios[$dni] = $usuario;
                }
                $usuarioModel = $usuarios[$dni];

                // 7. MATRIZ MANUAL
                $matrizKey = $centroGestor->id . '-' . $actividadModel->id . '-' . $turnoModel->id . '-' . $consultorioModel->id;
                if (!isset($matrices[$matrizKey])) {
                    $matriz = MatrizManual::where([
                        'centro_id' => $centroGestor->id,
                        'actividad_id' => $actividadModel->id,
                        'turno_id' => $turnoModel->id,
                        'consultorio_id' => $consultorioModel->id
                    ])->first();

                    if (!$matriz) {
                        $matriz = MatrizManual::create([
                            'id' => (string) Str::uuid(),
                            'centro_id' => $centroGestor->id,
                            'actividad_id' => $actividadModel->id,
                            'turno_id' => $turnoModel->id,
                            'consultorio_id' => $consultorioModel->id,
                            'prioridad' => 1,
                            'status' => 1
                        ]);
                    }
                    $matrices[$matrizKey] = $matriz;
                }

                // 8. PROGRAMACION
                $fechaStr = $row['PERIODO'] ?? ''; // ej: 02/07/2026
                $fechaCarbon = Carbon::createFromFormat('d/m/Y', $fechaStr);
                $mesesEs = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
                $mes = $mesesEs[$fechaCarbon->month - 1];
                $anio = $fechaCarbon->format('Y');

                $progKey = $mes . '-' . $anio . '-' . $centroGestor->id;
                if (!isset($programaciones[$progKey])) {
                    $programacion = Programacione::where([
                        'mes' => $mes,
                        'centro_gestor_id' => $centroGestor->id
                    ])->whereYear('fecha_programacion', $anio)->first();

                    if (!$programacion) {
                        $programacion = Programacione::create([
                            'id' => (string) Str::uuid(),
                            'codigo_generado' => 'PROG-' . strtoupper(substr($mes, 0, 3)) . '-' . $anio . '-' . rand(1000, 9999),
                            'mes' => $mes,
                            'fecha_programacion' => $fechaCarbon->copy()->startOfMonth()->format('Y-m-d'),
                            'jefe_servicio' => 'Importado',
                            'centro_gestor_id' => $centroGestor->id,
                            'topico_id' => $topicoModel->id,
                            'usuario_id' => auth()->id() ?? User::first()->id,
                            'horas_por_actividad' => $row['HRS_PROG'] ?? null
                        ]);
                    }
                    $programaciones[$progKey] = $programacion;
                }
                $programacionModel = $programaciones[$progKey];

                // 9. ASIGNACION (Si ya existe, la actualizamos para evitar duplicados en el mismo dia/turno/consultorio)
                $asignacione = Asignacione::where([
                    'programacion_id' => $programacionModel->id,
                    'dia_fecha' => $fechaCarbon->format('Y-m-d'),
                    'consultorio_id' => $consultorioModel->id,
                    'turno_id' => $turnoModel->id,
                    'actividad_id' => $actividadModel->id,
                    'usuario_asignado_id' => $usuarioModel->id
                ])->first();

                if (!$asignacione) {
                    Asignacione::create([
                        'id' => (string) Str::uuid(),
                        'programacion_id' => $programacionModel->id,
                        'dia_fecha' => $fechaCarbon->format('Y-m-d'),
                        'consultorio_id' => $consultorioModel->id,
                        'turno_id' => $turnoModel->id,
                        'actividad_id' => $actividadModel->id,
                        'usuario_asignado_id' => $usuarioModel->id,
                        'horas_por_actividad' => $row['HRS_PROG'] ?? null,
                        'status' => 1
                    ]);
                } else {
                    $asignacione->update([
                        'usuario_asignado_id' => $usuarioModel->id,
                        'horas_por_actividad' => $row['HRS_PROG'] ?? null
                    ]);
                }

                $imported++;
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => "Importación completada con éxito. $imported registros procesados."
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error durante la importación: ' . $e->getMessage()
            ], 500);
        }
    }
}
