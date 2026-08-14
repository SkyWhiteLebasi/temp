<?php

namespace App\Http\Controllers;

use App\Models\Asignacione;
use App\Models\Programacione;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class ReporteController extends Controller
{
    public function getStockReport()
    {
        $year = Carbon::now()->year;
        $today = Carbon::now()->toDateString();

        $neasBienesSinStock = DB::table('nea_bienes')
            ->where('cantidad', 0)
            ->count();

        $invBienesSinStock = DB::table('inventario_inicials')
            ->where('cantidad', 0)
            ->count();

        $neasBienesConStock = DB::table('nea_bienes')
            ->where('cantidad', '>', 0)
            ->count();

        $invBienesConStock = DB::table('inventario_inicials')
            ->where('cantidad', '>', 0)
            ->count();

        $cantidadPecosa = DB::table('pecosa_pedidos')->count();
        $cantidadNeas = DB::table('nea_entradas')->count();

        return response()->json([
            'year' => $year,
            'neas_bienes_sin_stock' => $neasBienesSinStock,
            'inv_bienes_sin_stock' => $invBienesSinStock,
            'neas_bienes_con_stock' => $neasBienesConStock,
            'inv_bienes_con_stock' => $invBienesConStock,
            'cantidad_pecosa' => $cantidadPecosa,
            'cantidad_neas' => $cantidadNeas,
        ]);
    }

    public function reporteProgramacionMedica(Request $request)
    {
        $hoy = Carbon::today();
        $manana = Carbon::tomorrow();
        $inicioSemana = Carbon::now()->startOfWeek();
        $finSemana = Carbon::now()->endOfWeek();
        $inicioMes = Carbon::now()->startOfMonth();
        $finMes = Carbon::now()->endOfMonth();

        $gerenciaId = $request->query('gerencia_id');
        $centroGestorId = $request->query('centro_gestor_id');

        // 🔥 FILTRADO JERÁRQUICO POR ROL SI NO SE ESPECIFICÓ EN QUERY
        $user = Auth::user();
        $userRole = null;
        if ($user) {
            $userRole = $user->roles->first()->name ?? null;
            if ($userRole === 'ADMIN') {
                if (empty($gerenciaId) && $user->gerencia_id) {
                    $gerenciaId = $user->gerencia_id;
                }
            } elseif ($userRole === 'USER' || $userRole === 'MEDICO') {
                if (empty($centroGestorId) && $user->centro_id) {
                    $centroGestorId = $user->centro_id;
                }
                if (empty($gerenciaId) && $user->gerencia_id) {
                    $gerenciaId = $user->gerencia_id;
                }
            }
        }

        $applyFilters = function ($query) use ($gerenciaId, $centroGestorId) {
            if (!empty($centroGestorId)) {
                $query->whereHas('programacion', function ($q) use ($centroGestorId) {
                    $q->where('centro_gestor_id', $centroGestorId);
                });
            } elseif (!empty($gerenciaId)) {
                $query->whereHas('programacion.centroGestor', function ($q) use ($gerenciaId) {
                    $q->where('gerencia_id', $gerenciaId);
                });
            }
        };

        // 1. Médicos programados para hoy
        $queryHoy = Asignacione::whereDate('dia_fecha', $hoy)->distinct('usuario_asignado_id');
        $applyFilters($queryHoy);
        $medicosHoy = $queryHoy->count('usuario_asignado_id');

        // 2. Médicos programados para mañana
        $queryManana = Asignacione::whereDate('dia_fecha', $manana)->distinct('usuario_asignado_id');
        $applyFilters($queryManana);
        $medicosManana = $queryManana->count('usuario_asignado_id');

        // 3. Médicos programados para esta semana
        $querySemanal = Asignacione::whereBetween('dia_fecha', [$inicioSemana, $finSemana])->distinct('usuario_asignado_id');
        $applyFilters($querySemanal);
        $medicosSemanal = $querySemanal->count('usuario_asignado_id');

        // 4. Médicos programados para este mes
        $queryMensual = Asignacione::whereBetween('dia_fecha', [$inicioMes, $finMes])->distinct('usuario_asignado_id');
        $applyFilters($queryMensual);
        $medicosMensual = $queryMensual->count('usuario_asignado_id');

        // 5. Total de programaciones activas (este mes o generales)
        $queryProg = Programacione::where('status', 1);
        if (!empty($centroGestorId)) {
            $queryProg->where('centro_gestor_id', $centroGestorId);
        } elseif (!empty($gerenciaId)) {
            $queryProg->whereHas('centroGestor', function ($q) use ($gerenciaId) {
                $q->where('gerencia_id', $gerenciaId);
            });
        }
        $programacionesTotal = $queryProg->count();

        // 6. Total de asignaciones este mes
        $queryAsig = Asignacione::whereBetween('dia_fecha', [$inicioMes, $finMes])->where('status', 1);
        $applyFilters($queryAsig);
        $asignacionesMensuales = $queryAsig->count();

        // 7. Consultorios utilizados este mes
        $queryConsultorios = Asignacione::whereBetween('dia_fecha', [$inicioMes, $finMes])
            ->whereNotNull('consultorio_id')
            ->where('status', 1)
            ->distinct('consultorio_id');
        $applyFilters($queryConsultorios);
        $consultoriosUtilizados = $queryConsultorios->count('consultorio_id');

        // 8. Actividades programadas este mes
        $queryActividades = Asignacione::whereBetween('dia_fecha', [$inicioMes, $finMes])
            ->where('status', 1)
            ->distinct('actividad_id');
        $applyFilters($queryActividades);
        $actividadesProgramadas = $queryActividades->count('actividad_id');

        // 9. Breakdown de Actividades más programadas este mes
        $queryBreakdownActividades = Asignacione::with('actividad')
            ->whereBetween('dia_fecha', [$inicioMes, $finMes])
            ->where('status', 1)
            ->select('actividad_id', DB::raw('count(*) as total'));
        $applyFilters($queryBreakdownActividades);
        $actividadesBreakdown = $queryBreakdownActividades->groupBy('actividad_id')
            ->orderBy('total', 'desc')
            ->take(6)
            ->get()
            ->map(function ($item) {
                return [
                    'nombre' => $item->actividad ? $item->actividad->nombre : 'Sin actividad',
                    'total' => (int) $item->total
                ];
            });

        // 10. Breakdown de Top Consultorios utilizados este mes
        $queryBreakdownConsultorios = Asignacione::with('consultorio')
            ->whereBetween('dia_fecha', [$inicioMes, $finMes])
            ->whereNotNull('consultorio_id')
            ->where('status', 1)
            ->select('consultorio_id', DB::raw('count(*) as total'));
        $applyFilters($queryBreakdownConsultorios);
        $consultoriosBreakdown = $queryBreakdownConsultorios->groupBy('consultorio_id')
            ->orderBy('total', 'desc')
            ->take(6)
            ->get()
            ->map(function ($item) {
                return [
                    'nombre' => $item->consultorio ? $item->consultorio->nombre : 'Sin consultorio',
                    'total' => (int) $item->total
                ];
            });

        // 11. Breakdown por Turnos
        $queryBreakdownTurnos = Asignacione::with('turno')
            ->whereBetween('dia_fecha', [$inicioMes, $finMes])
            ->where('status', 1)
            ->select('turno_id', DB::raw('count(*) as total'));
        $applyFilters($queryBreakdownTurnos);
        $turnosBreakdown = $queryBreakdownTurnos->groupBy('turno_id')
            ->orderBy('total', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'nombre' => $item->turno ? $item->turno->nombre : 'Turno no especificado',
                    'total' => (int) $item->total
                ];
            });

        // 12. Tendencia mensual (Últimos 6 meses)
        $mesesLabels = [];
        $mesesAsignaciones = [];
        $mesesMedicos = [];

        $nombresMeses = [
            1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
            7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'
        ];

        for ($i = 5; $i >= 0; $i--) {
            $mDate = Carbon::now()->subMonths($i);
            $mStart = $mDate->copy()->startOfMonth();
            $mEnd = $mDate->copy()->endOfMonth();
            $mesNombre = $nombresMeses[$mDate->month] . ' ' . $mDate->format('y');

            $mesesLabels[] = $mesNombre;

            $qMAsig = Asignacione::whereBetween('dia_fecha', [$mStart, $mEnd])->where('status', 1);
            $applyFilters($qMAsig);
            $mesesAsignaciones[] = $qMAsig->count();

            $qMMed = Asignacione::whereBetween('dia_fecha', [$mStart, $mEnd])->where('status', 1)->distinct('usuario_asignado_id');
            $applyFilters($qMMed);
            $mesesMedicos[] = $qMMed->count('usuario_asignado_id');
        }

        return response()->json([
            'medicos_hoy' => $medicosHoy,
            'medicos_manana' => $medicosManana,
            'medicos_semanal' => $medicosSemanal,
            'medicos_mensual' => $medicosMensual,
            'programaciones_mensuales' => $programacionesTotal,
            'asignaciones_mensuales' => $asignacionesMensuales,
            'consultorios_utilizados' => $consultoriosUtilizados,
            'actividades_programadas' => $actividadesProgramadas,
            'actividades_breakdown' => $actividadesBreakdown,
            'consultorios_breakdown' => $consultoriosBreakdown,
            'turnos_breakdown' => $turnosBreakdown,
            'tendencia_mensual' => [
                'labels' => $mesesLabels,
                'asignaciones' => $mesesAsignaciones,
                'medicos' => $mesesMedicos
            ],
            'user_info' => [
                'role' => $userRole,
                'gerencia_id' => $user ? $user->gerencia_id : null,
                'centro_id' => $user ? $user->centro_id : null
            ],
            'fecha_consulta' => now()->toDateTimeString()
        ]);
    }
}
