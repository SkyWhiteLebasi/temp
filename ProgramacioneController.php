<?php

namespace App\Http\Controllers;

use App\Models\Asignacione;
use App\Models\Programacione;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Illuminate\Support\Facades\View;
class ProgramacioneController extends Controller
{

    public function medicosFiltrados(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'valid' => false,
                    'error' => 'Usuario no autenticado'
                ], 401);
            }

            $filtros = $request->validate([
                'tipo_servicio' => 'nullable|string',
                'consultorio_id' => 'nullable|string',
                'turno_id' => 'nullable|string',
                'anio' => 'nullable|integer',
                'mes' => 'nullable|integer',
                'numero_semana' => 'nullable|integer',
                'vista' => 'nullable|in:mes,semana',
                'centro_id' => 'nullable|string', // Nuevo filtro
                'gerencia_id' => 'nullable|string' // Nuevo filtro
            ]);

            $query = User::with([
                'asignaciones.consultorio',
                'asignaciones.turno',
                'asignaciones.actividad',
                'asignaciones.programacion.topico',
                'especialidad',
                'roles'
            ])->whereHas('asignaciones', function ($query) use ($filtros, $user) {
                // Aplicar filtros a las asignaciones
                if (!empty($filtros['anio'])) {
                    $query->whereYear('dia_fecha', $filtros['anio']);
                }

                if (!empty($filtros['mes']) && empty($filtros['numero_semana'])) {
                    $query->whereMonth('dia_fecha', $filtros['mes']);
                }

                if (!empty($filtros['numero_semana']) && !empty($filtros['anio'])) {
                    $query->whereRaw('WEEK(dia_fecha, 1) = ?', [$filtros['numero_semana']])
                        ->whereYear('dia_fecha', $filtros['anio']);
                }

                if (!empty($filtros['consultorio_id'])) {
                    $query->where('consultorio_id', $filtros['consultorio_id']);
                }

                if (!empty($filtros['turno_id'])) {
                    $query->where('turno_id', $filtros['turno_id']);
                }

                if (!empty($filtros['tipo_servicio'])) {
                    $query->whereHas('programacion.topico', function ($q) use ($filtros) {
                        $q->where('servintern', 'like', '%' . $filtros['tipo_servicio'] . '%');
                    });
                }

                // 🔥 APLICAR FILTRADO JERÁRQUICO POR ROLES
                if ($user->hasRole('SUPERADMIN')) {
                    // SUPERADMIN: puede filtrar por cualquier gerencia/centro
                    if (!empty($filtros['centro_id'])) {
                        $query->whereHas('consultorio', function ($q) use ($filtros) {
                            $q->where('centro_id', $filtros['centro_id']);
                        });
                    } elseif (!empty($filtros['gerencia_id'])) {
                        $query->whereHas('consultorio.centro', function ($q) use ($filtros) {
                            $q->where('gerencia_id', $filtros['gerencia_id']);
                        });
                    }

                } elseif ($user->hasRole('ADMIN')) {
                    // ADMIN: solo ve su gerencia
                    if ($user->gerencia_id) {
                        $query->whereHas('consultorio.centro', function ($q) use ($user) {
                            $q->where('gerencia_id', $user->gerencia_id);
                        });

                        // Si envía un centro_id, verificar que pertenezca a su gerencia
                        if (!empty($filtros['centro_id'])) {
                            $query->whereHas('consultorio', function ($q) use ($filtros) {
                                $q->where('centro_id', $filtros['centro_id']);
                            });
                        }
                    }

                } elseif ($user->hasRole('USER') || $user->hasRole('MEDICO')) {
                    // USER/MEDICO: solo ve su centro específico
                    if ($user->centro_id) {
                        $query->whereHas('consultorio', function ($q) use ($user) {
                            $q->where('centro_id', $user->centro_id);
                        });
                    }
                }
            });

            $medicos = $query->get()->map(function ($medico) use ($filtros) {
                // Filtrar asignaciones según los criterios
                $medico->asignaciones = $medico->asignaciones->filter(function ($asignacion) use ($filtros) {
                    $cumpleFiltros = true;

                    if (!empty($filtros['anio'])) {
                        $cumpleFiltros = $cumpleFiltros && (date('Y', strtotime($asignacion->dia_fecha)) == $filtros['anio']);
                    }

                    if (!empty($filtros['mes']) && empty($filtros['numero_semana'])) {
                        $cumpleFiltros = $cumpleFiltros && (date('n', strtotime($asignacion->dia_fecha)) == $filtros['mes']);
                    }

                    if (!empty($filtros['numero_semana']) && !empty($filtros['anio'])) {
                        $semanaAsignacion = date('W', strtotime($asignacion->dia_fecha));
                        $cumpleFiltros = $cumpleFiltros && ($semanaAsignacion == $filtros['numero_semana']);
                    }

                    if (!empty($filtros['consultorio_id'])) {
                        $cumpleFiltros = $cumpleFiltros && ($asignacion->consultorio_id == $filtros['consultorio_id']);
                    }

                    if (!empty($filtros['turno_id'])) {
                        $cumpleFiltros = $cumpleFiltros && ($asignacion->turno_id == $filtros['turno_id']);
                    }

                    return $cumpleFiltros;
                })->values();

                // Agregar URL de la imagen
                if ($medico->imagen) {
                    if (Storage::disk('public')->exists($medico->imagen)) {
                        $medico->imagen_url = Storage::disk('public')->url($medico->imagen);
                    } elseif (file_exists(public_path('storage/' . $medico->imagen))) {
                        $medico->imagen_url = asset('storage/' . $medico->imagen);
                    } else {
                        $medico->imagen_url = null;
                    }
                } else {
                    $medico->imagen_url = null;
                }

                // Agregar información del rol
                $medico->role = $medico->roles->first()->name ?? 'USER';

                return $medico;
            })->filter(function ($medico) {
                // Solo médicos que tengan asignaciones después del filtrado
                return $medico->asignaciones->count() > 0;
            })->values();

            return response()->json([
                'valid' => true,
                'medicos' => $medicos,
                'filtros_aplicados' => $filtros,
                'total_medicos' => $medicos->count(),
                'user_context' => [
                    'role' => $user->roles->first()->name ?? 'No role',
                    'gerencia_id' => $user->gerencia_id,
                    'centro_id' => $user->centro_id
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'valid' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function medico(Request $request)
    {
        try {
            $user = Auth::user();

            // Obtener parámetros de filtro
            $centroId = $request->get('centro_id');
            $gerenciaId = $request->get('gerencia_id');

            // 🔥 CONSULTA PRINCIPAL DE MÉDICOS Y PROGRAMACIONES
            $query = DB::table('users as u')
                ->join('asignaciones as a', 'u.id', '=', 'a.usuario_asignado_id')
                ->join('consultorios as c', 'a.consultorio_id', '=', 'c.id')
                ->join('turnos as t', 'a.turno_id', '=', 't.id')
                ->join('programaciones as p', 'a.programacion_id', '=', 'p.id')
                ->join('topicos as tp', 'p.topico_id', '=', 'tp.id')
                ->leftJoin('especialidades as e', 'u.especialidad_id', '=', 'e.id')
                ->leftJoin('centro_gestors as cg', 'c.centro_id', '=', 'cg.id')
                ->leftJoin('gerencias as g', 'cg.gerencia_id', '=', 'g.id')
                ->whereMonth('a.dia_fecha', now()->month)
                ->whereYear('a.dia_fecha', now()->year)
                ->whereDate('a.dia_fecha', '>=', now()->toDateString());

            // 🔥 MEJORA: Filtrar solo programaciones futuras o del día actual
            $query->where(function ($q) {
                $q->whereDate('a.dia_fecha', '>', now()->toDateString())
                    ->orWhere(function ($q2) {
                        $q2->whereDate('a.dia_fecha', '=', now()->toDateString())
                            ->whereTime('t.hora_fin', '>=', now()->format('H:i:s'));
                    });
            });

            // 🔥 FILTRADO JERÁRQUICO MEJORADO
            if ($user->hasRole('SUPERADMIN')) {
                if (!empty($centroId)) {
                    $query->where('c.centro_id', $centroId);
                } elseif (!empty($gerenciaId)) {
                    $query->where('g.id', $gerenciaId);
                }
            } elseif ($user->hasRole('ADMIN')) {
                if ($user->gerencia_id) {
                    $query->where('g.id', $user->gerencia_id);

                    if (!empty($centroId)) {
                        $query->where('c.centro_id', $centroId)
                            ->where('g.id', $user->gerencia_id);
                    }
                } else {
                    return response()->json([
                        'valid' => true,
                        'medicos' => [],
                        'total_medicos' => 0,
                        'message' => 'Usuario ADMIN no tiene gerencia asignada'
                    ]);
                }
            } elseif ($user->hasRole('USER') || $user->hasRole('MEDICO') || $user->hasRole('OPERADOR')) {
                if ($user->centro_id) {
                    $query->where('c.centro_id', $user->centro_id);
                } elseif ($user->gerencia_id) {
                    $query->where('g.id', $user->gerencia_id);
                } else {
                    return response()->json([
                        'valid' => true,
                        'medicos' => [],
                        'total_medicos' => 0,
                        'message' => 'Usuario no tiene centro o gerencia asignado'
                    ]);
                }
            }

            $medicos = $query->select(
                'u.id as medico_id',
                'u.name as medico_nombre',
                'u.first_lastname',
                'u.second_lastname',
                'u.abreviatura as abreviatura',
                'u.imagen',
                'e.nombre as especialidad',
                't.nombre as turno',
                't.hora_inicio',
                't.hora_fin',
                'a.dia_fecha',
                'c.nombre as consultorio',
                'c.centro_id',
                'cg.descengest as centro_nombre',
                'g.id as gerencia_id',
                'g.desgerencia as gerencia_nombre',
                'tp.servintern as tipo_servicio'
            )
                ->orderBy('u.id')
                ->orderBy('a.dia_fecha')
                ->orderBy('t.hora_inicio')
                ->get();

            // 🔥 OBTENER IDS DE MÉDICOS ÚNICOS
            $medicoIds = $medicos->pluck('medico_id')->unique()->values();

            // 🔥 CONSULTA DE EVALUACIONES DE MÉDICOS
            $evaluaciones = [];
            if ($medicoIds->count() > 0) {
                // Obtener el período activo más reciente
                $periodoActivo = DB::table('evaluacion_periodos')
                    ->where('estado', 'activo')
                    ->when(!empty($centroId), function ($q) use ($centroId) {
                        $q->where('centro_id', $centroId);
                    })
                    ->when($user->hasRole('ADMIN') && $user->gerencia_id, function ($q) use ($user) {
                        $q->whereIn('centro_id', function ($subq) use ($user) {
                            $subq->select('id')
                                ->from('centro_gestors')
                                ->where('gerencia_id', $user->gerencia_id);
                        });
                    })
                    ->when($user->hasRole('USER') || $user->hasRole('MEDICO'), function ($q) use ($user) {
                        $q->where('centro_id', $user->centro_id);
                    })
                    ->orderBy('fecha_fin', 'desc')
                    ->first();

                if ($periodoActivo) {
                    $evaluaciones = DB::table('evaluacion_medicos as em')
                        ->select(
                            'em.medico_id',
                            'em.calificacion_final',
                            'em.estrellas_finales',
                            'em.calificaciones',
                            'ep.nombre as periodo_nombre'
                        )
                        ->join('evaluacion_periodos as ep', 'em.periodo_id', '=', 'ep.id')
                        ->where('em.periodo_id', $periodoActivo->id)
                        ->whereIn('em.medico_id', $medicoIds)
                        ->where('em.status', true)
                        ->get()
                        ->keyBy('medico_id');
                }
            }

            // 🔥 MEJORA: Procesar resultados con agrupación mejorada
            $medicosAgrupados = $medicos->groupBy('medico_id')->map(function ($programaciones, $medicoId) use ($evaluaciones) {
                $primerMedico = $programaciones->first();

                // 🔥 MEJORA: Manejo robusto de imágenes

                $imagenUrl = null;
                if ($primerMedico->imagen) {
                    try {
                        if (Storage::disk('public')->exists($primerMedico->imagen)) {
                            //    $imagenUrl = Storage::disk('public')->url($primerMedico->imagen);
                            $imagenUrl = asset('storage/' . $primerMedico->imagen);
                        }
                    } catch (\Exception $e) {
                        \Log::warning("Error cargando imagen para médico {$medicoId}: " . $e->getMessage());
                    }
                }

                // 🔥 MEJORA: URL de imagen por defecto
                if (!$imagenUrl) {
                    $imagenUrl = asset('images/default-user.png');
                }



                // 🔥 INFORMACIÓN DE EVALUACIÓN
                $evaluacionData = null;
                if (isset($evaluaciones[$medicoId])) {
                    $eval = $evaluaciones[$medicoId];

                    // Calcular mejor categoría
                    $mejorCategoria = null;
                    $calificacionesArray = json_decode($eval->calificaciones, true);

                    if ($calificacionesArray && is_array($calificacionesArray)) {
                        $maxCalificacion = max($calificacionesArray);
                        $mejorCategoriaSlug = array_search($maxCalificacion, $calificacionesArray);

                        if ($mejorCategoriaSlug) {
                            $categoria = DB::table('evaluacion_categorias')
                                ->where('slug', $mejorCategoriaSlug)
                                ->first();

                            if ($categoria) {
                                $mejorCategoria = [
                                    'nombre' => $categoria->nombre,
                                    'calificacion' => $maxCalificacion,
                                    'slug' => $categoria->slug
                                ];
                            }
                        }
                    }

                    $evaluacionData = [
                        'calificacion_final' => $eval->calificacion_final,
                        'estrellas_finales' => $eval->estrellas_finales,
                        'periodo_nombre' => $eval->periodo_nombre,
                        'mejor_categoria' => $mejorCategoria
                    ];
                }

                // 🔥 MEJORA: Agrupar programaciones por fecha con información mejorada
                $programacionesPorFecha = $programaciones->groupBy('dia_fecha')->map(function ($programacionesDelDia, $fecha) {
                    $primerProgramacion = $programacionesDelDia->first();

                    return [
                        'fecha' => $fecha,
                        'dia_semana' => $this->obtenerDiaSemana($fecha),
                        'dia_semana_corto' => $this->obtenerDiaSemanaCorto($fecha),
                        'fecha_formateada' => $this->formatearFecha($fecha),
                        'programaciones' => $programacionesDelDia->map(function ($programacion) {
                            return [
                                'turno' => $programacion->turno,
                                'hora_inicio' => $programacion->hora_inicio,
                                'hora_fin' => $programacion->hora_fin,
                                'hora_inicio_formateada' => $this->formatearHora($programacion->hora_inicio),
                                'hora_fin_formateada' => $this->formatearHora($programacion->hora_fin),
                                'consultorio' => $programacion->consultorio,
                                'tipo_servicio' => $programacion->tipo_servicio,
                                'centro_nombre' => $programacion->centro_nombre
                            ];
                        })->sortBy('hora_inicio')->values()
                    ];
                })->sortBy('fecha')->values();

                $nombreCompleto = trim($primerMedico->medico_nombre . ' ' . ($primerMedico->first_lastname ?? '') . ' ' . ($primerMedico->second_lastname ?? ''));
                $nombreCorto = trim($primerMedico->medico_nombre . ' ' . ($primerMedico->first_lastname ?? ''));

                return [
                    'medico_id' => $medicoId,
                    'medico_nombre' => $primerMedico->medico_nombre,
                    'first_lastname' => $primerMedico->first_lastname,
                    'second_lastname' => $primerMedico->second_lastname,
                    'nombre_completo' => $nombreCompleto ?: $primerMedico->medico_nombre,
                    'nombre_corto' => $nombreCorto ?: $primerMedico->medico_nombre,
                    'abreviatura' => $primerMedico->abreviatura,
                    'imagen_url' => $imagenUrl,
                    'especialidad' => $primerMedico->especialidad,
                    'centro_nombre' => $primerMedico->centro_nombre,
                    'gerencia_nombre' => $primerMedico->gerencia_nombre,
                    'total_programaciones' => $programaciones->count(),
                    'proxima_fecha' => $programaciones->min('dia_fecha'),
                    'programaciones_por_fecha' => $programacionesPorFecha,
                    'evaluacion' => $evaluacionData // 🔥 NUEVO: Datos de evaluación
                ];
            })->values();

            return response()->json([
                'valid' => true,
                'medicos' => $medicosAgrupados,
                'total_medicos' => $medicosAgrupados->count(),
                'fecha_consulta' => now()->toDateTimeString(),
                'filtros_aplicados' => [
                    'centro_id' => $centroId,
                    'gerencia_id' => $gerenciaId,
                    'user_role' => $user->roles->first()->name ?? 'No role'
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error("Error en médico endpoint: " . $e->getMessage());
            return response()->json([
                'valid' => false,
                'error' => $e->getMessage(),
                'message' => 'Error al cargar la programación médica'
            ], 500);
        }
    }
    // 🔥 MEJORA: Funciones auxiliares para formateo
// private function obtenerDiaSemana($fecha)
// {
//     $dias = [
//         'Sunday' => 'Domingo',
//         'Monday' => 'Lunes', 
//         'Tuesday' => 'Martes',
//         'Wednesday' => 'Miércoles',
//         'Thursday' => 'Jueves',
//         'Friday' => 'Viernes',
//         'Saturday' => 'Sábado'
//     ];

    //     $englishDay = date('l', strtotime($fecha));
//     return $dias[$englishDay] ?? $englishDay;
// }

    private function obtenerDiaSemanaCorto($fecha)
    {
        $dias = [
            'Sunday' => 'Dom',
            'Monday' => 'Lun',
            'Tuesday' => 'Mar',
            'Wednesday' => 'Mié',
            'Thursday' => 'Jue',
            'Friday' => 'Vie',
            'Saturday' => 'Sáb'
        ];

        $englishDay = date('l', strtotime($fecha));
        return $dias[$englishDay] ?? substr($englishDay, 0, 3);
    }

    private function formatearFecha($fecha)
    {
        return date('d/m/Y', strtotime($fecha));
    }

    private function formatearHora($hora)
    {
        if (!$hora)
            return '--:--';
        return date('H:i', strtotime($hora));
    }

    // 🔥 NUEVA FUNCIÓN: Obtener día de la semana en español
    private function obtenerDiaSemana($fecha)
    {
        $dias = [
            'Sunday' => 'Domingo',
            'Monday' => 'Lunes',
            'Tuesday' => 'Martes',
            'Wednesday' => 'Miércoles',
            'Thursday' => 'Jueves',
            'Friday' => 'Viernes',
            'Saturday' => 'Sábado'
        ];

        $nombreIngles = date('l', strtotime($fecha));
        return $dias[$nombreIngles] ?? $nombreIngles;
    }
    // 🔹 Método auxiliar para obtener URL de imagen
    private function getImagenUrl($imagenPath)
    {
        if (!$imagenPath) {
            return null;
        }

        // Verificar si la imagen existe en storage
        if (Storage::disk('public')->exists($imagenPath)) {
            return Storage::disk('public')->url($imagenPath);
        }

        // Verificar si existe en public/storage
        if (file_exists(public_path('storage/' . $imagenPath))) {
            return asset('storage/' . $imagenPath);
        }

        // Si no se encuentra, retornar null
        return null;
    }

    public function listEnableData(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 10);
            $search = $request->get('search');
            $centro_id = $request->get('centro_id');
            $gerencia_id = $request->get('gerencia_id');

            // Obtener el usuario autenticado y su rol
            $user = Auth::user();
            $userRole = $user->roles->first()->name ?? null;

            // Base query con relaciones
            $query = Programacione::with([
                'asignaciones.consultorio',
                'asignaciones.turno',
                'asignaciones.actividad',
                'asignaciones.medico',
                'centroGestor',
                'centroGestor.gerencia',
                'topico',
                'usuario'
            ])->where('status', 1);

            // 🔥 FILTRADO POR ROL Y PERMISOS
            if ($userRole === 'USER') {
                // USER solo ve programaciones de su centro gestor
                if ($user->centro_id) {
                    $query->where('centro_gestor_id', $user->centro_id); // ✅ Cambiado a centro_gestor_id
                }
            } elseif ($userRole === 'ADMIN') {
                // ADMIN ve programaciones de su gerencia
                if ($user->gerencia_id) {
                    $query->whereHas('centroGestor.gerencia', function ($q) use ($user) {
                        $q->where('id', $user->gerencia_id);
                    });
                }
            }
            // SUPERADMIN ve todo (no se aplica filtro)

            // 🔥 FILTRADO MANUAL POR PARÁMETROS (para superadmin y admin que quieran filtrar)
            if (!empty($centro_id)) {
                $query->where('centro_gestor_id', $centro_id); // ✅ Cambiado a centro_gestor_id
            }

            if (!empty($gerencia_id)) {
                $query->whereHas('centroGestor.gerencia', function ($q) use ($gerencia_id) {
                    $q->where('id', $gerencia_id);
                });
            }

            // Filtro de búsqueda
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('codigo_generado', 'like', "%$search%")
                        ->orWhere('mes', 'like', "%$search%")
                        ->orWhere('jefe_servicio', 'like', "%$search%")
                        ->orWhere('id', 'like', "%$search%")
                        ->orWhereHas('centroGestor', function ($subQuery) use ($search) {
                            $subQuery->where('descengest', 'like', "%$search%");
                        })
                        ->orWhereHas('centroGestor.gerencia', function ($subQuery) use ($search) {
                            $subQuery->where('desgerencia', 'like', "%$search%");
                        })
                        ->orWhereHas('asignaciones.consultorio', function ($subQuery) use ($search) {
                            $subQuery->where('nombre', 'like', "%$search%");
                        })
                        ->orWhereHas('asignaciones.medico', function ($subQuery) use ($search) {
                            $subQuery->where('name', 'like', "%$search%");
                        });
                });
            }

            // Paginación
            $programaciones = $query->orderBy('created_at', 'desc')->paginate($perPage);

            // Transformar la respuesta para incluir datos de relaciones
            $formattedData = $programaciones->getCollection()->map(function ($programacion) {
                return [
                    'id' => $programacion->id,
                    'codigo_generado' => $programacion->codigo_generado,
                    'fecha_programacion' => $programacion->fecha_programacion,
                    'mes' => $programacion->mes,
                    'anio' => $programacion->fecha_programacion ? date('Y', strtotime($programacion->fecha_programacion)) : null,
                    'año' => $programacion->fecha_programacion ? date('Y', strtotime($programacion->fecha_programacion)) : null,
                    'horas_por_actividad' => $programacion->horas_por_actividad,
                    'jefe_servicio' => $programacion->jefe_servicio,
                    'servicio' => $programacion->servicio ?? 'MEDICINA PREVENCION Y PROMOCION',
                    'centro_id' => $programacion->centro_gestor_id, // ✅ Cambiado para mantener consistencia
                    'centro' => $programacion->centroGestor ? [
                        'id' => $programacion->centroGestor->id,
                        'descengest' => $programacion->centroGestor->descengest,
                        'codcengest' => $programacion->centroGestor->codcengest
                    ] : null,
                    'centro_gestor' => $programacion->centroGestor ? [
                        'id' => $programacion->centroGestor->id,
                        'descengest' => $programacion->centroGestor->descengest,
                        'codcengest' => $programacion->centroGestor->codcengest
                    ] : null,
                    'centroGestor' => $programacion->centroGestor ? [
                        'id' => $programacion->centroGestor->id,
                        'descengest' => $programacion->centroGestor->descengest,
                        'codcengest' => $programacion->centroGestor->codcengest
                    ] : null,
                    'gerencia' => $programacion->centroGestor && $programacion->centroGestor->gerencia ? [
                        'id' => $programacion->centroGestor->gerencia->id,
                        'desgerencia' => $programacion->centroGestor->gerencia->desgerencia,
                        'codgerencia' => $programacion->centroGestor->gerencia->codgerencia
                    ] : null,
                    'topico' => $programacion->topico ? [
                        'id' => $programacion->topico->id,
                        'servintern' => $programacion->topico->servintern
                    ] : null,
                    'usuario' => $programacion->usuario ? [
                        'id' => $programacion->usuario->id,
                        'name' => $programacion->usuario->name
                    ] : null,
                    'asignaciones' => $programacion->asignaciones->map(function ($asignacion) {
                        return [
                            'id' => $asignacion->id,
                            'consultorio' => $asignacion->consultorio ? [
                                'id' => $asignacion->consultorio->id,
                                'nombre' => $asignacion->consultorio->nombre
                            ] : null,
                            'turno' => $asignacion->turno ? [
                                'id' => $asignacion->turno->id,
                                'nombre' => $asignacion->turno->nombre,
                                'hora_inicio' => $asignacion->turno->hora_inicio,
                                'hora_fin' => $asignacion->turno->hora_fin
                            ] : null,
                            'actividad' => $asignacion->actividad ? [
                                'id' => $asignacion->actividad->id,
                                'nombre' => $asignacion->actividad->nombre
                            ] : null,
                            'medico' => $asignacion->medico ? [
                                'id' => $asignacion->medico->id,
                                'name' => $asignacion->medico->name,
                                'first_lastname' => $asignacion->medico->first_lastname,
                                'second_lastname' => $asignacion->medico->second_lastname
                            ] : null,
                            'dia_fecha' => $asignacion->dia_fecha,
                            'horas_por_actividad' => $asignacion->horas_por_actividad
                        ];
                    }),
                    'status' => $programacion->status,
                    'created_at' => $programacion->created_at,
                    'total_asignaciones' => $programacion->asignaciones->count()
                ];
            });

            // 🔥 INFORMACIÓN ADICIONAL PARA EL FRONTEND
            $metadata = [
                'user_role' => $userRole,
                'filters_applied' => [
                    'centro_id' => $centro_id,
                    'gerencia_id' => $gerencia_id,
                    'automatic_by_role' => $userRole !== 'SUPERADMIN'
                ],
                'total_records' => $programaciones->total()
            ];

            return response()->json([
                'data' => $formattedData,
                'metadata' => $metadata,
                'current_page' => $programaciones->currentPage(),
                'per_page' => $programaciones->perPage(),
                'total' => $programaciones->total(),
                'last_page' => $programaciones->lastPage(),
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Error en listEnableData Programaciones: ' . $e->getMessage());
            return response()->json([
                'valid' => false,
                'error' => 'Error al cargar las programaciones: ' . $e->getMessage()
            ], 500);
        }
    }
    public function storeProgramacion(Request $request)
    {
        // ✅ 1. Validación de datos recibidos - CONSULTORIO OPCIONAL para vacaciones
        $request->validate([
            'fecha_programacion' => 'required|date',
            'mes' => 'required|string',
            'jefe_servicio' => 'required|string',
            'servicio' => 'nullable|string',
            'topico_id' => 'required|uuid|exists:topicos,id',
            'horas_por_actividad' => 'required|numeric',
            'centro_id' => 'required|uuid|exists:centro_gestors,id',
            'asignaciones' => 'required|array|min:1',
            'asignaciones.*.consultorio_id' => 'nullable|uuid|exists:consultorios,id', // ✅ CAMBIO: nullable
            'asignaciones.*.turno_id' => 'required|uuid|exists:turnos,id',
            'asignaciones.*.actividad_id' => 'required|uuid|exists:actividades,id',
            'asignaciones.*.usuario_asignado_id' => 'required|exists:users,id',
            'asignaciones.*.dia_fecha' => 'required|date',
            'asignaciones.*.horas_por_actividad' => 'required|numeric'
        ]);

        $userId = auth()->id() ?? null;
        $user = Auth::user();

        DB::beginTransaction();

        try {
            $fechaProgramacion = Carbon::parse($request->fecha_programacion)->format('Y-m-d');
            $centroId = $request->centro_id ?? $user->centro_id ?? null;

            // ✅ 2. Validar que NO exista otra programación con el mismo mes, año y centro
            $mes = $request->mes;
            $anioProgramacion = Carbon::parse($fechaProgramacion)->year;

            // Extraer el mes y año del string del mes
            $mesAnio = $this->extraerMesYAnio($mes, $anioProgramacion);
            $mesFormateado = $mesAnio['mes'];
            $anio = $mesAnio['anio'];

            // ✅ CORRECCIÓN: Validar duplicados por mes, año y centro
            $existePrograma = Programacione::where('centro_gestor_id', $centroId)
                ->where('mes', 'like', "%{$mesFormateado}%")
                ->where(function ($query) use ($anio) {
                    $query->where('mes', 'like', "%{$anio}%")
                        ->orWhereYear('fecha_programacion', $anio);
                })
                ->where('status', 1)
                ->exists();

            if ($existePrograma) {
                return response()->json([
                    'success' => false,
                    'message' => "Ya existe una programación registrada para el mes {$mesFormateado} {$anio} en este centro gestor ❌"
                ], 422);
            }

            // ✅ 3. Generar código único
            $codigoGenerado = 'PROG-' . strtoupper(Str::random(8));

            // ✅ 4. Crear la programación
            $programa = Programacione::create([
                'id' => Str::uuid(),
                'codigo_generado' => $codigoGenerado,
                'fecha_programacion' => $fechaProgramacion,
                'mes' => $request->mes,
                'horas_por_actividad' => $request->horas_por_actividad,
                'jefe_servicio' => $request->jefe_servicio,
                'servicio' => $request->servicio ?? 'MEDICINA PREVENCION Y PROMOCION',
                'centro_gestor_id' => $centroId,
                'topico_id' => $request->topico_id,
                'usuario_id' => $userId,
                'status' => 1
            ]);

            // ✅ 5. Registrar las asignaciones
            foreach ($request->asignaciones as $index => $asignacion) {

                // ✅ CORRECCIÓN: Validar que al menos tenga consultorio_id o actividad que permita null
                if (empty($asignacion['consultorio_id']) && empty($asignacion['usuario_asignado_id'])) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "La asignación #" . ($index + 1) . " no tiene consultorio ni usuario asignado ❌"
                    ], 422);
                }

                // ✅ CORRECCIÓN: Validar duplicados de asignaciones - CONSULTORIO NULLABLE
                $existeAsignacion = Asignacione::where(function ($query) use ($asignacion) {
                    // Si tiene consultorio, validar por consultorio
                    if (!empty($asignacion['consultorio_id'])) {
                        $query->where('consultorio_id', $asignacion['consultorio_id']);
                    } else {
                        // Si no tiene consultorio, validar por actividad de vacaciones
                        $query->whereNull('consultorio_id');
                    }
                })
                    ->where('dia_fecha', $asignacion['dia_fecha'])
                    ->where('usuario_asignado_id', $asignacion['usuario_asignado_id'])
                    ->where('status', 1)
                    ->exists();

                if ($existeAsignacion) {
                    DB::rollBack();
                    $tipo = !empty($asignacion['consultorio_id']) ? 'consultorio' : 'vacaciones';
                    return response()->json([
                        'success' => false,
                        'message' => "El usuario ya está asignado en este {$tipo} para la fecha {$asignacion['dia_fecha']} ❌"
                    ], 422);
                }

                // ✅ CORRECCIÓN: Crear asignación con consultorio_id nullable
                Asignacione::create([
                    'id' => Str::uuid(),
                    'programacion_id' => $programa->id,
                    'consultorio_id' => $asignacion['consultorio_id'] ?? null, // ✅ Puede ser null
                    'turno_id' => $asignacion['turno_id'],
                    'actividad_id' => $asignacion['actividad_id'],
                    'usuario_asignado_id' => $asignacion['usuario_asignado_id'],
                    'horas_por_actividad' => $asignacion['horas_por_actividad'],
                    'dia_fecha' => $asignacion['dia_fecha'],
                    'status' => 1
                ]);
            }

            DB::commit();

            // ✅ 6. Cargar relaciones para la respuesta
            $programaCargado = Programacione::with([
                'asignaciones.consultorio',
                'asignaciones.turno',
                'asignaciones.actividad',
                'asignaciones.medico'
            ])->find($programa->id);

            // ✅ 7. Respuesta de éxito
            return response()->json([
                'success' => true,
                'message' => 'Programación y asignaciones registradas correctamente ✅',
                'programa' => [
                    'id' => $programaCargado->id,
                    'codigo_generado' => $programaCargado->codigo_generado,
                    'fecha_programacion' => $programaCargado->fecha_programacion,
                    'mes' => $programaCargado->mes,
                    'jefe_servicio' => $programaCargado->jefe_servicio,
                    'servicio' => $programaCargado->servicio,
                    'topico_id' => $programaCargado->topico_id,
                    'horas_por_actividad' => $programaCargado->horas_por_actividad,
                    'asignaciones' => $programaCargado->asignaciones->map(function ($asignacion) {
                        return [
                            'consultorio' => $asignacion->consultorio ? $asignacion->consultorio->nombre : 'VACACIONES',
                            'turno' => $asignacion->turno ? $asignacion->turno->nombre : null,
                            'actividad' => $asignacion->actividad ? $asignacion->actividad->nombre : null,
                            'medico' => $asignacion->medico ? $asignacion->medico->name : null,
                            'dia_fecha' => $asignacion->dia_fecha,
                            'horas_por_actividad' => $asignacion->horas_por_actividad
                        ];
                    })
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Error al registrar programación mensual de médicos', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar programación mensual de médicos ❌',
                'error' => $e->getMessage(),
                'trace' => env('APP_DEBUG') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    // 🔥 AGREGAR ESTE MÉTODO AL CONTROLADOR si no existe
    private function extraerMesYAnio($mes, $anioProgramacion)
    {
        // Si el mes ya incluye el año, extraerlo
        if (strpos($mes, ' ') !== false) {
            $partes = explode(' ', $mes);
            $mesFormateado = $partes[0];
            $anio = isset($partes[1]) ? $partes[1] : $anioProgramacion;
        } else {
            $mesFormateado = $mes;
            $anio = $anioProgramacion;
        }

        return [
            'mes' => $mesFormateado,
            'anio' => $anio
        ];
    }

    /**
     * Convierte nombre del mes a número
     */
    private function convertirMesANumero($mes)
    {
        $meses = [
            'enero' => 1,
            'febrero' => 2,
            'marzo' => 3,
            'abril' => 4,
            'mayo' => 5,
            'junio' => 6,
            'julio' => 7,
            'agosto' => 8,
            'septiembre' => 9,
            'octubre' => 10,
            'noviembre' => 11,
            'diciembre' => 12
        ];

        $mesLower = strtolower(trim($mes));
        return $meses[$mesLower] ?? null;
    }

    // En ProgramacioneController.php

    public function show($id)
    {
        try {
            // Obtener el usuario autenticado y su rol
            $user = Auth::user();
            $userRole = $user->roles->first()->name ?? null;

            // Base query con relaciones
            $query = Programacione::with([
                'asignaciones.consultorio',
                'asignaciones.turno',
                'asignaciones.actividad',
                'asignaciones.medico', // ✅ Esta relación debe cargarse
                'centroGestor',
                'topico',
                'usuario'
            ]);

            // 🔥 APLICAR LOS MISMOS FILTROS POR ROL QUE EN listEnableData()
            if ($userRole === 'USER' || $userRole === 'MEDICO') {
                // USER/MEDICO solo puede ver programaciones de su centro gestor
                if ($user->centro_id) {
                    $query->where('centro_gestor_id', $user->centro_id);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tiene permisos para ver esta programación'
                    ], 403);
                }
            } elseif ($userRole === 'ADMIN') {
                // ADMIN ve programaciones de su gerencia
                if ($user->gerencia_id) {
                    $query->whereHas('centroGestor', function ($q) use ($user) {
                        $q->where('gerencia_id', $user->gerencia_id);
                    });
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tiene permisos para ver esta programación'
                    ], 403);
                }
            }
            // SUPERADMIN ve todo (no se aplica filtro)

            // Buscar la programación específica con los filtros aplicados
            $programacion = $query->find($id);

            if (!$programacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Programación no encontrada o no tiene permisos para verla'
                ], 404);
            }

            // ✅ CORREGIR: Asegurar que las relaciones se carguen correctamente
            if ($programacion->asignaciones) {
                foreach ($programacion->asignaciones as $asignacion) {
                    // Verificar y corregir la relación médico si es necesario
                    if ($asignacion->usuario_asignado_id && !$asignacion->medico) {
                        // Cargar manualmente el médico si no se cargó en la relación
                        $asignacion->load('medico');
                    }
                }
            }

            // ✅ MEJORAR: Agregar imagen_url para el usuario de la programación
            if ($programacion->usuario && $programacion->usuario->imagen) {
                $programacion->usuario->imagen_url = $programacion->usuario->getImagenUrlAttribute();
            }

            // ✅ MEJORAR: Agregar imagen_url para cada médico en las asignaciones
            if ($programacion->asignaciones) {
                foreach ($programacion->asignaciones as $asignacion) {
                    if ($asignacion->medico && $asignacion->medico->imagen) {
                        $asignacion->medico->imagen_url = $asignacion->medico->getImagenUrlAttribute();
                    }
                }
            }

            // ✅ AGREGAR: Información de filtros aplicados (para debug)
            $filtrosAplicados = [
                'user_role' => $userRole,
                'user_gerencia_id' => $user->gerencia_id,
                'user_centro_id' => $user->centro_id,
                'programacion_centro_id' => $programacion->centro_gestor_id
            ];

            return response()->json([
                'success' => true,
                'data' => $programacion,
                'filtros_aplicados' => $filtrosAplicados // ✅ Para debug en frontend
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error en show Programación: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar la programación'
            ], 500);
        }
    }

    public function updateProgramacion(Request $request, $id)
    {
        // ✅ LOG TEMPORAL PARA DEBUG
        \Log::info('Payload recibido en updateProgramacion:', [
            'programacion_id' => $id,
            'asignaciones_count' => count($request->asignaciones ?? []),
            'asignaciones_sample' => $request->asignaciones ? array_slice($request->asignaciones, 0, 2) : [],
        ]);

        // ✅ Validación de datos - CONSULTORIO NULLABLE para vacaciones
        $request->validate([
            'fecha_programacion' => 'required|date',
            'mes' => 'required|string',
            'jefe_servicio' => 'required|string',
            'servicio' => 'nullable|string',
            'topico_id' => 'required|uuid|exists:topicos,id',
            'horas_por_actividad' => 'required|numeric',
            'asignaciones' => 'required|array|min:1',
            'asignaciones.*.id' => 'sometimes|uuid|exists:asignaciones,id', // Cambiado a 'sometimes'
            'asignaciones.*.consultorio_id' => 'nullable|uuid|exists:consultorios,id', // 🔥 CAMBIO: nullable
            'asignaciones.*.turno_id' => 'required|uuid|exists:turnos,id',
            'asignaciones.*.actividad_id' => 'required|uuid|exists:actividades,id',
            'asignaciones.*.usuario_asignado_id' => 'required|exists:users,id',
            'asignaciones.*.dia_fecha' => 'required|date',
            'asignaciones.*.horas_por_actividad' => 'required|numeric'
        ]);

        DB::beginTransaction();

        try {
            $programacion = Programacione::findOrFail($id);
            $fechaProgramacion = Carbon::parse($request->fecha_programacion)->format('Y-m-d');

            // ✅ Actualizar la programación
            $programacion->update([
                'fecha_programacion' => $fechaProgramacion,
                'mes' => $request->mes,
                'horas_por_actividad' => $request->horas_por_actividad,
                'jefe_servicio' => $request->jefe_servicio,
                'servicio' => $request->servicio ?? $programacion->servicio ?? 'MEDICINA PREVENCION Y PROMOCION',
                'topico_id' => $request->topico_id,
            ]);

            // ✅ Procesar asignaciones
            $asignacionesExistentesIds = [];

            foreach ($request->asignaciones as $index => $asignacionData) {

                // 🔥 NUEVA VALIDACIÓN: Verificar que tenga al menos consultorio o usuario
                if (empty($asignacionData['consultorio_id']) && empty($asignacionData['usuario_asignado_id'])) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "La asignación #" . ($index + 1) . " no tiene consultorio ni usuario asignado ❌"
                    ], 422);
                }

                // ✅ CORRECCIÓN: Solo validar duplicados para NUEVAS asignaciones
                // Para asignaciones existentes, permitimos la actualización sin validar duplicados
                if (!isset($asignacionData['id'])) {
                    // 🔥 NUEVA LÓGICA: Validar duplicados considerando consultorios null
                    $existeAsignacion = Asignacione::where(function ($query) use ($asignacionData) {
                        // Si tiene consultorio, validar por consultorio
                        if (!empty($asignacionData['consultorio_id'])) {
                            $query->where('consultorio_id', $asignacionData['consultorio_id']);
                        } else {
                            // Si no tiene consultorio, validar por actividad de vacaciones
                            $query->whereNull('consultorio_id');
                        }
                    })
                        ->where('dia_fecha', $asignacionData['dia_fecha'])
                        ->where('usuario_asignado_id', $asignacionData['usuario_asignado_id'])
                        ->where('status', 1)
                        ->exists();

                    if ($existeAsignacion) {
                        DB::rollBack();
                        $tipo = !empty($asignacionData['consultorio_id']) ? 'consultorio' : 'vacaciones';
                        return response()->json([
                            'success' => false,
                            'message' => "El usuario ya está asignado en este {$tipo} para la fecha {$asignacionData['dia_fecha']} ❌"
                        ], 422);
                    }
                }

                if (isset($asignacionData['id'])) {
                    // Actualizar asignación existente
                    $asignacion = Asignacione::find($asignacionData['id']);
                    if ($asignacion) {
                        $asignacion->update([
                            'consultorio_id' => $asignacionData['consultorio_id'] ?? null, // 🔥 ACEPTA NULL
                            'turno_id' => $asignacionData['turno_id'],
                            'actividad_id' => $asignacionData['actividad_id'],
                            'usuario_asignado_id' => $asignacionData['usuario_asignado_id'],
                            'horas_por_actividad' => $asignacionData['horas_por_actividad'],
                            'dia_fecha' => $asignacionData['dia_fecha'],
                        ]);
                        $asignacionesExistentesIds[] = $asignacionData['id'];
                    }
                } else {
                    // ✅ CORRECCIÓN: Crear nueva asignación sin ID
                    $nuevaAsignacion = Asignacione::create([
                        'id' => Str::uuid(),
                        'programacion_id' => $programacion->id,
                        'consultorio_id' => $asignacionData['consultorio_id'] ?? null, // 🔥 ACEPTA NULL
                        'turno_id' => $asignacionData['turno_id'],
                        'actividad_id' => $asignacionData['actividad_id'],
                        'usuario_asignado_id' => $asignacionData['usuario_asignado_id'],
                        'horas_por_actividad' => $asignacionData['horas_por_actividad'],
                        'dia_fecha' => $asignacionData['dia_fecha'],
                        'status' => 1
                    ]);
                    $asignacionesExistentesIds[] = $nuevaAsignacion->id;
                }
            }

            // ✅ Eliminar asignaciones que no están en la lista actual
            Asignacione::where('programacion_id', $programacion->id)
                ->whereNotIn('id', $asignacionesExistentesIds)
                ->delete();

            DB::commit();

            // ✅ Cargar relaciones actualizadas
            $programacionCargada = Programacione::with([
                'asignaciones.consultorio',
                'asignaciones.turno',
                'asignaciones.actividad',
                'asignaciones.medico'
            ])->find($programacion->id);

            return response()->json([
                'success' => true,
                'message' => 'Programación actualizada correctamente ✅',
                'programa' => $programacionCargada
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Error al actualizar programación', [
                'error' => $e->getMessage(),
                'programacion_id' => $id,
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar programación ❌',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function generarPDF(\Illuminate\Http\Request $request, $id)
    {
        try {
            // Obtener el usuario autenticado y su rol
            $user = Auth::user();
            $userRole = $user->roles->first()->name ?? null;

            // Filtros de actividades y consultorios enviados desde frontend
            $filtroActividades = $request->query('actividades');
            $filtroConsultorios = $request->query('consultorios');

            // Base query con relaciones
            $query = Programacione::with([
                'asignaciones' => function($q) use ($filtroActividades, $filtroConsultorios) {
                    if ($filtroActividades) {
                        $q->whereIn('actividad_id', explode(',', $filtroActividades));
                    }
                    if ($filtroConsultorios) {
                        $q->whereIn('consultorio_id', explode(',', $filtroConsultorios));
                    }
                },
                'asignaciones.consultorio',
                'asignaciones.turno',
                'asignaciones.actividad',
                'asignaciones.medico',
                'centroGestor',
                'centroGestor.gerencia',
                'topico',
                'usuario'
            ]);

            // Aplicar filtros por rol
            if ($userRole === 'USER') {
                if ($user->centro_id) {
                    $query->where('centro_gestor_id', $user->centro_id);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tiene permisos para ver esta programación'
                    ], 403);
                }
            } elseif ($userRole === 'ADMIN') {
                if ($user->gerencia_id) {
                    $query->whereHas('centroGestor.gerencia', function ($q) use ($user) {
                        $q->where('id', $user->gerencia_id);
                    });
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tiene permisos para ver esta programación'
                    ], 403);
                }
            }

            // Buscar la programación específica
            $programacion = $query->find($id);

            if (!$programacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Programación no encontrada o no tiene permisos para verla'
                ], 404);
            }

            // Preparar datos para el PDF consolidado
            $datosPDF = $this->prepararDatosConsolidados($programacion);

            // Renderizar la vista
            $html = View::make('reporteProgramaMedico.ProgramaGeneralPdf', $datosPDF)->render();

            // Aumentar el límite de PCRE para tablas grandes (como la de 800+ filas)
            ini_set("pcre.backtrack_limit", "5000000");

            // Configurar mPDF
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4-L', // Horizontal para tabla ancha
                'margin_left' => 10,
                'margin_right' => 10,
                'margin_top' => 15,
                'margin_bottom' => 15,
                'default_font' => 'arial'
            ]);

            $mpdf->WriteHTML($html);

            // Generar el PDF
            $pdfContent = $mpdf->Output('', 'S');

            // Devolver respuesta con el PDF
            return response($pdfContent)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="programacion_consolidada_' . $programacion->codigo_generado . '.pdf"');

        } catch (\Exception $e) {
            \Log::error('Error al generar PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    // Método para preparar datos consolidados
    private function prepararDatosConsolidados($programacion)
    {
        // Obtener todos los médicos únicos con sus asignaciones
        $medicosConAsignaciones = $this->obtenerMedicosConAsignaciones($programacion);

        // Generar días del mes
        $diasDelMes = $this->generarDiasDelMesPDF($programacion->mes);

        // Preparar datos de la programación con asignaciones
        $programacionData = [
            'codigo_generado' => $programacion->codigo_generado,
            'mes' => $programacion->mes,
            'anio' => date('Y', strtotime($programacion->fecha_programacion)),
            'horas_por_actividad' => $programacion->horas_por_actividad,
            'jefe_servicio' => $programacion->jefe_servicio,
            'servicio' => $programacion->servicio ?? 'MEDICINA PREVENCION Y PROMOCION',
            'asignaciones' => $this->prepararAsignacionesParaVista($programacion)
        ];

        return [
            'programacion' => $programacionData,
            'gerencia' => $programacion->centroGestor && $programacion->centroGestor->gerencia
                ? $programacion->centroGestor->gerencia->desgerencia
                : 'Sin gerencia',
            'centro' => $programacion->centroGestor
                ? $programacion->centroGestor->descengest
                : 'Sin centro',
            'medicos' => $medicosConAsignaciones,
            'diasDelMes' => $diasDelMes,
            'total_horas_general' => $programacion->asignaciones->count() * (float)($programacion->horas_por_actividad ?? 6),
            'total_asignaciones_general' => $programacion->asignaciones->count(),
            'fecha_generacion' => now()->format('d/m/Y H:i:s')
        ];
    }

    private function prepararAsignacionesParaVista($programacion)
    {
        $asignacionesFormateadas = [];

        foreach ($programacion->asignaciones as $asignacion) {
            $asignacionesFormateadas[] = [
                'actividad_nombre' => $asignacion->actividad->nombre ?? 'Sin actividad',
                'turno_nombre' => $asignacion->turno->nombre ?? 'Sin turno',
                'consultorio_nombre' => $asignacion->consultorio->nombre ?? 'Sin consultorio',
                'dia_fecha' => $asignacion->dia_fecha,
                'medico_nombre' => $asignacion->medico ?
                    $asignacion->medico->name . ' ' . $asignacion->medico->first_lastname :
                    'Sin médico',
                'iniciales' => $asignacion->medico ?
                    strtoupper(
                        mb_substr($asignacion->medico->name ?? '', 0, 1) .
                        mb_substr($asignacion->medico->first_lastname ?? '', 0, 1) .
                        mb_substr($asignacion->medico->second_lastname ?? '', 0, 1)
                    ) : '',
                'abreviatura' => $asignacion->medico ?
                    $asignacion->medico->abreviatura :
                    'Sin médico'
            ];
        }

        return $asignacionesFormateadas;
    }
    // Método para obtener médicos con sus asignaciones y totales
    private function obtenerMedicosConAsignaciones($programacion)
    {
        $medicosMap = [];

        foreach ($programacion->asignaciones as $asignacion) {
            if ($asignacion->medico) {
                $medicoId = $asignacion->medico->id;

                if (!isset($medicosMap[$medicoId])) {
                    $medicosMap[$medicoId] = [
                        'id' => $asignacion->medico->id,
                        'abreviatura' => $asignacion->medico->abreviatura,
                        'iniciales' => strtoupper(
                            mb_substr($asignacion->medico->name ?? '', 0, 1) .
                            mb_substr($asignacion->medico->first_lastname ?? '', 0, 1) .
                            mb_substr($asignacion->medico->second_lastname ?? '', 0, 1)
                        ),
                        'nombre_completo' => $asignacion->medico->name . ' ' .
                            $asignacion->medico->first_lastname . ' ' .
                            $asignacion->medico->second_lastname,
                        'especialidad' => $asignacion->medico->especialidad ?? 'Médico General',
                        'total_asignaciones' => 0,
                        'total_horas' => 0,
                        'asignaciones' => []
                    ];
                }

                // Agregar asignación
                $medicosMap[$medicoId]['asignaciones'][] = [
                    'actividad_nombre' => $asignacion->actividad->nombre ?? 'Sin actividad',
                    'turno_nombre' => $asignacion->turno->nombre ?? 'Sin turno',
                    'consultorio_nombre' => $asignacion->consultorio->nombre ?? 'Sin consultorio',
                    'dia_fecha' => $asignacion->dia_fecha,
                    // 'medico_nombre' => $asignacion->medico->name . ' ' . $asignacion->medico->first_lastname,
                    'abreviatura' => $asignacion->medico->abreviatura
                ];

                // Incrementar contadores
                $medicosMap[$medicoId]['total_asignaciones']++;
                $medicosMap[$medicoId]['total_horas'] += (float)($programacion->horas_por_actividad ?? 6);
            }
        }

        // Ordenar médicos por nombre
        usort($medicosMap, function ($a, $b) {
            return strcmp($a['nombre_completo'], $b['nombre_completo']);
        });

        return array_values($medicosMap);
    }
    // Métodos auxiliares actualizados
    private function obtenerActividadesUnicasPDF($programacion)
    {
        $actividadesSet = [];
        foreach ($programacion->asignaciones as $asignacion) {
            if ($asignacion->actividad) {
                $key = $asignacion->actividad->id;
                if (!isset($actividadesSet[$key])) {
                    $actividadesSet[$key] = [
                        'id' => $asignacion->actividad->id,
                        'nombre' => $asignacion->actividad->nombre
                    ];
                }
            }
        }
        return array_values($actividadesSet);
    }

    private function obtenerTurnosUnicosPDF($programacion)
    {
        $turnosSet = [];
        foreach ($programacion->asignaciones as $asignacion) {
            if ($asignacion->turno) {
                $key = $asignacion->turno->id;
                if (!isset($turnosSet[$key])) {
                    $turnosSet[$key] = [
                        'id' => $asignacion->turno->id,
                        'nombre' => $asignacion->turno->nombre,
                        'hora_inicio' => $asignacion->turno->hora_inicio,
                        'hora_fin' => $asignacion->turno->hora_fin
                    ];
                }
            }
        }
        return array_values($turnosSet);
    }

    private function obtenerConsultoriosUnicosPDF($programacion)
    {
        $consultoriosSet = [];
        foreach ($programacion->asignaciones as $asignacion) {
            if ($asignacion->consultorio) {
                $key = $asignacion->consultorio->id;
                if (!isset($consultoriosSet[$key])) {
                    $consultoriosSet[$key] = [
                        'id' => $asignacion->consultorio->id,
                        'nombre' => $asignacion->consultorio->nombre
                    ];
                }
            }
        }
        return array_values($consultoriosSet);
    }

    private function generarDiasDelMesPDF($mesNombre)
    {
        $meses = [
            'enero' => 1,
            'febrero' => 2,
            'marzo' => 3,
            'abril' => 4,
            'mayo' => 5,
            'junio' => 6,
            'julio' => 7,
            'agosto' => 8,
            'septiembre' => 9,
            'octubre' => 10,
            'noviembre' => 11,
            'diciembre' => 12
        ];

        $mesLimpio = strtolower(explode(' ', $mesNombre)[0]);
        $mesNum = $meses[$mesLimpio] ?? null;
        $añoNum = date('Y');

        if ($mesNum === null) {
            return [];
        }

        $diasEnMes = cal_days_in_month(CAL_GREGORIAN, $mesNum, $añoNum);
        $dias = [];

        for ($i = 1; $i <= $diasEnMes; $i++) {
            $fecha = new \DateTime("$añoNum-$mesNum-$i");
            $dias[] = [
                'numero' => $i,
                'diaSemana' => $this->obtenerDiaSemanaEspanol($fecha->format('w')),
                'fechaCompleta' => $fecha->format('Y-m-d'),
            ];
        }

        return $dias;
    }

    private function obtenerDiaSemanaEspanol($diaNumero)
    {
        $dias = ['D', 'L', 'M', 'M', 'J', 'V', 'S'];
        return $dias[$diaNumero] ?? 'D';
    }

    public function debugPDFData($id)
    {
        try {
            // Obtener el usuario autenticado y su rol
            $user = Auth::user();
            $userRole = $user->roles->first()->name ?? null;

            // Base query con relaciones
            $query = Programacione::with([
                'asignaciones.consultorio',
                'asignaciones.turno',
                'asignaciones.actividad',
                'asignaciones.medico',
                'centroGestor',
                'centroGestor.gerencia',
                'topico',
                'usuario'
            ]);

            // Aplicar filtros por rol
            if ($userRole === 'USER') {
                if ($user->centro_id) {
                    $query->where('centro_gestor_id', $user->centro_id);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tiene permisos para ver esta programación'
                    ], 403);
                }
            } elseif ($userRole === 'ADMIN') {
                if ($user->gerencia_id) {
                    $query->whereHas('centroGestor.gerencia', function ($q) use ($user) {
                        $q->where('id', $user->gerencia_id);
                    });
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'No tiene permisos para ver esta programación'
                    ], 403);
                }
            }

            // Buscar la programación específica
            $programacion = $query->find($id);

            if (!$programacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'Programación no encontrada o no tiene permisos para verla'
                ], 404);
            }

            // Preparar datos para debug
            $datosDebug = $this->prepararDatosConsolidados($programacion);

            return response()->json([
                'success' => true,
                'debug_data' => [
                    'programacion_id' => $programacion->id,
                    'programacion_codigo' => $programacion->codigo_generado,
                    'total_asignaciones' => $programacion->asignaciones->count(),
                    'estructura_programacion' => [
                        'keys' => array_keys($datosDebug['programacion']),
                        'programacion_data' => $datosDebug['programacion']
                    ],
                    'estructura_completa' => $datosDebug,
                    'medicos_count' => count($datosDebug['medicos']),
                    'dias_count' => count($datosDebug['diasDelMes']),
                    'primer_medico' => $datosDebug['medicos'][0] ?? 'No hay médicos',
                    'primeras_asignaciones' => array_slice($datosDebug['programacion']['asignaciones'] ?? [], 0, 3)
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en debug PDF: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error en debug: ' . $e->getMessage(),
                'trace' => env('APP_DEBUG') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $programacion = Programacione::findOrFail($id);
            $programacion->delete();

            return response()->json([
                'success' => true,
                'message' => 'Programación eliminada correctamente'
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Error al eliminar programación: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Error al eliminar la programación: ' . $e->getMessage()
            ], 500);
        }
    }
}
