<?php

namespace App\Http\Controllers;

use App\Imports\CentroGestorImport;
use App\Models\CentroGestor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class CentroGestorController extends Controller
{

    public function index(Request $request)
    {
        try {
            // Obtener el usuario autenticado
            $user = Auth::user();

            $query = DB::table('centro_gestors')
                ->select(
                    'centro_gestors.*',
                    'gerencia.desgerencia as desgerencia',
                    'gerencia.codgerencia as codgerencia'
                )
                ->join('gerencias as gerencia', 'centro_gestors.gerencia_id', '=', 'gerencia.id');

            // 🔥 FILTRADO JERÁRQUICO POR ROLES
            if ($user) {
                if ($user->hasRole('SUPERADMIN')) {
                    // SUPERADMIN: ve todo - sin filtros

                } elseif ($user->hasRole('ADMIN')) {
                    // ADMIN: ve según su gerencia
                    if ($user->gerencia_id) {
                        $query->where('centro_gestors.gerencia_id', $user->gerencia_id);
                    }
                    // Si no tiene gerencia, no ve centros?

                } elseif ($user->hasRole('USER') || $user->hasRole('MEDICO')) {
                    // USER/MEDICO: ve solo su centro específico
                    if ($user->centro_id) {
                        $query->where('centro_gestors.id', $user->centro_id);
                    } else {
                        // Si no tiene centro, no ve centros
                        $query->where('centro_gestors.id', null);
                    }
                }
            }

            // Búsqueda dinámica
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where('centro_gestors.descengest', 'like', "%$search%")
                    ->orWhere('centro_gestors.codcengest', 'like', "%$search%")
                    ->orWhere('gerencia.desgerencia', 'like', "%$search%")
                    ->orWhere('gerencia.codgerencia', 'like', "%$search%")
                    ->orWhere('centro_gestors.id', 'like', "%$search%");
            }

            // Obtener los resultados sin paginación
            $data = $query->get();

            return response()->json([
                'data' => $data,
                'user_info' => [ // 🔥 Info útil para debug
                    'role' => $user->roles->first()->name ?? 'No role',
                    'gerencia_id' => $user->gerencia_id,
                    'centro_id' => $user->centro_id
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['valid' => false, 'error' => $e->getMessage()], 401);
        }
    }

    public function listEnable(Request $request)
    {
        try {
            // Obtener el usuario autenticado
            $user = Auth::user();

            $query = DB::table('centro_gestors')
                ->select(
                    'centro_gestors.*',
                    'gerencia.desgerencia as desgerencia',
                    'gerencia.codgerencia as codgerencia',
                    'gerencia.id as gerencia_id'
                )
                ->join('gerencias as gerencia', 'centro_gestors.gerencia_id', '=', 'gerencia.id')
                ->where('centro_gestors.status', 1);

            // 🔥 FILTRADO JERÁRQUICO POR ROLES
            if ($user) {
                if ($user->hasRole('SUPERADMIN')) {
                    // Sin filtros

                } elseif ($user->hasRole('ADMIN')) {
                    if ($user->gerencia_id) {
                        $query->where('centro_gestors.gerencia_id', $user->gerencia_id);
                    }

                } elseif ($user->hasRole('USER') || $user->hasRole('MEDICO')) {
                    if ($user->centro_id) {
                        $query->where('centro_gestors.id', $user->centro_id);
                    } else {
                        $query->where('centro_gestors.id', null);
                    }
                }
            }

            $data = $query->get();

            return response()->json([
                'data' => $data,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['valid' => false, 'error' => $e->getMessage()], 401);
        }
    }

    public function listEnableData(Request $request)
    {
        try {
            // Obtener el usuario autenticado
            $user = Auth::user();

            $query = DB::table('centro_gestors')
                ->select(
                    'centro_gestors.*',
                    'gerencia.desgerencia as desgerencia',
                    'gerencia.codgerencia as codgerencia',
                    'gerencia.id as gerencia_id'
                )
                ->join('gerencias as gerencia', 'centro_gestors.gerencia_id', '=', 'gerencia.id')
                ->where('centro_gestors.status', 1);

            // 🔥 FILTRADO JERÁRQUICO POR ROLES
            if ($user) {
                if ($user->hasRole('SUPERADMIN')) {
                    // Sin filtros

                } elseif ($user->hasRole('ADMIN')) {
                    if ($user->gerencia_id) {
                        $query->where('centro_gestors.gerencia_id', $user->gerencia_id);
                    }

                } elseif ($user->hasRole('USER') || $user->hasRole('MEDICO')) {
                    if ($user->centro_id) {
                        $query->where('centro_gestors.id', $user->centro_id);
                    } else {
                        $query->where('centro_gestors.id', null);
                    }
                }
            }

            $data = $query->get();

            return response()->json([
                'data' => $data,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['valid' => false, 'error' => $e->getMessage()], 401);
        }
    }

    public function listDisableData(Request $request)
    {
        try {
            // Obtener el usuario autenticado
            $user = Auth::user();

            $query = DB::table('centro_gestors')
                ->select(
                    'centro_gestors.*',
                    'gerencia.desgerencia as desgerencia',
                    'gerencia.codgerencia as codgerencia'
                )
                ->join('gerencias as gerencia', 'centro_gestors.gerencia_id', '=', 'gerencia.id')
                ->where('centro_gestors.status', 0);

            // 🔥 FILTRADO JERÁRQUICO POR ROLES
            if ($user) {
                if ($user->hasRole('SUPERADMIN')) {
                    // Sin filtros

                } elseif ($user->hasRole('ADMIN')) {
                    if ($user->gerencia_id) {
                        $query->where('centro_gestors.gerencia_id', $user->gerencia_id);
                    }

                } elseif ($user->hasRole('USER') || $user->hasRole('MEDICO')) {
                    if ($user->centro_id) {
                        $query->where('centro_gestors.id', $user->centro_id);
                    } else {
                        $query->where('centro_gestors.id', null);
                    }
                }
            }

            $data = $query->get();

            return response()->json([
                'data' => $data,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['valid' => false, 'error' => $e->getMessage()], 401);
        }
    }
    public function store(Request $request)
    {

        try {
            $validator = Validator::make($request->all(), [
                'codcengest' => 'required|string|max:10',
                'descengest' => 'required|string',
                'abreviatura' => 'required|string',
                'gerencia_id' => 'required|string',
            ]);
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            DB::table('centro_gestors')->insert([
                'id' => Str::uuid(),
                'codcengest' => $request->codcengest,
                'descengest' => $request->descengest,
                'abreviatura' => $request->abreviatura,
                'gerencia_id' => $request->gerencia_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json(['mensaje' => 'Centro Gestor creado'], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error Server'], 500);
        }
    }

    public function import(Request $request)
    {

        $request->validate([ //ojo ambos estan requeridos - tener cuidado
            'file' => 'required|mimes:xlsx,xls',
        ]);

        try {
            $file = $request->file('file');

            // Importar el primer archivo
            if ($file) {
                Excel::import(new CentroGestorImport(), $file);
                return response()->json(['message' => 'Importación exitosa de Centros Gestores']);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al importar las Centros Gestores: ' . $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {

        //$query = DB::table('unidad_medidas')->where('id', $id)->first();
        try {

            $query = DB::table('centro_gestors')->where('id', $id)->first();

            // Devolver la respuesta JSON con los datos
            return response()->json([
                'data' => $query,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error Server'], 500);
        }

    }

    public function update(Request $request, $id)
    {

        try {
            $validator = Validator::make($request->all(), [
                'codcengest' => 'required|string|max:10',
                'descengest' => 'required|string',
                'abreviatura' => 'required|string',
                'gerencia_id' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $updated = DB::table('centro_gestors')
                ->where('id', $id)
                ->update([
                    'codcengest' => $request->codcengest,
                    'descengest' => $request->descengest,
                    'abreviatura' => $request->abreviatura,
                    'gerencia_id' => $request->gerencia_id,
                    'updated_at' => now(),
                ]);

            if ($updated) {
                return response()->json(['mensaje' => 'Centro Gestor actualizado'], 201);
            } else {
                return response()->json(['mensaje' => 'Centro Gestor no encontradao'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error Server'], 500);
        }
    }

    public function destroy(CentroGestor $centro_gestors)
    {
        //
    }

    public function disable(Request $request)
    {

        $id = $request->input('id'); // Obtener el ID del body

        if (!$id) {
            return response()->json(['error' => 'ID requerido'], 400);
        }

        try {

            $updated = DB::table('centro_gestors')
                ->where('id', $id)
                ->update([
                    'status' => 0,
                ]);

            if ($updated) {
                return response()->json(['mensaje' => 'Centro Gestor deshabilitado'], 201);
            } else {
                return response()->json(['mensaje' => 'Centro Gestor no encontrado'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error Server'], 500);
        }
    }

    public function enable(Request $request)
    {

        $id = $request->input('id'); // Obtener el ID del body

        if (!$id) {
            return response()->json(['error' => 'ID requerido'], 400);
        }

        try {

            $updated = DB::table('centro_gestors')
                ->where('id', $id)
                ->update([
                    'status' => 1,
                ]);

            if ($updated) {
                return response()->json(['mensaje' => 'Centro Gestor habilitado'], 201);
            } else {
                return response()->json(['mensaje' => 'Centro Gestor no encontrado'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error Server'], 500);
        }
    }
}
