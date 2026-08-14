<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid; // Añadir esto

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;
    protected $table = 'users';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_name',
        'name',
        'first_lastname',
        'second_lastname',
        'email',
        'dni',
        'colegiatura',
        'cargo',
        'gerencia_id',
        'centro_id',
        'especialidad_id',
        'password',
        'imagen',
        'abreviatura',
        'cod_planilla'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'imagen_url',
        'role_names',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }


    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid(); // O Str::orderedUuid()
            }
        });
    }

    public function horarios()
    {
        return $this->belongsToMany(Horario::class, 'horario_usuarios', 'usuario_id', 'horario_id')
            ->withPivot(['fecha_inicio', 'fecha_fin', 'status'])
            ->withTimestamps();
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_user', 'user_id', 'permission_id');
    }

    public function rutas()
    {
        return $this->belongsToMany(RutaSistema::class, 'ruta_user', 'user_id', 'ruta_id');
    }

    public function asignaciones()
    {
        return $this->hasMany(Asignacione::class, 'usuario_asignado_id', 'id');
    }

    public function especialidad()
    {
        return $this->belongsTo(Especialidade::class, 'especialidad_id');
    }

    public function centro()
    {
        return $this->belongsTo(CentroGestor::class, 'centro_id');
    }

    public function gerencia()
    {
        return $this->belongsTo(Gerencia::class, 'gerencia_id');
    }

    // --- JWT Methods ---
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    // --- Attribute Accessors ---
    public function getImagenUrlAttribute()
    {
        if (!$this->imagen) {
            return url('images/default.png');
        }

        // Si la base de datos dice que tiene imagen, verificamos que el archivo físico exista
        $path = storage_path('app/public/' . $this->imagen);
        if (!file_exists($path)) {
            return url('images/default.png');
        }

        return url('storage/' . $this->imagen);
    }

    // 🔥 NUEVO: Accesor para nombres de roles
    public function getRoleNamesAttribute()
    {
        return $this->roles->pluck('name')->toArray();
    }

    // --- Métodos de Verificación de Permisos MEJORADOS ---

    /**
     * Verificar si el usuario tiene un rol específico
     */
    public function hasRole($role)
    {
        if (is_array($role)) {
            return $this->roles->whereIn('name', $role)->count() > 0;
        }

        return $this->roles->where('name', $role)->count() > 0;
    }

    /**
     * Verificar si el usuario tiene alguno de los roles
     */
    public function hasAnyRole(array $roles)
    {
        return $this->roles->whereIn('name', $roles)->count() > 0;
    }

    /**
     * Verificar si el usuario tiene todos los roles
     */
    public function hasAllRoles(array $roles)
    {
        return $this->roles->whereIn('name', $roles)->count() === count($roles);
    }

    /**
     * Verificar permiso usando bitmask (manteniendo tu lógica actual)
     */
    public function hasPermission($permissionName, $requiredBitmask = null)
    {
        $permissions = $this->combinedPermissions();

        if (!isset($permissions[$permissionName])) {
            return false;
        }

        if ($requiredBitmask === null) {
            // Si no se especifica bitmask, cualquier permiso cuenta
            return true;
        }

        // Verificar el bitmask específico
        return ($permissions[$permissionName] & $requiredBitmask) === $requiredBitmask;
    }

    /**
     * Verificar si tiene alguno de los permisos
     */
    public function hasAnyPermission(array $permissions)
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Permisos combinados MEJORADO - ahora más eficiente
     */
    public function combinedPermissions()
    {
        $result = [];

        // Cargar relaciones si no están cargadas (optimización)
        if (!$this->relationLoaded('permissions')) {
            $this->load('permissions');
        }
        if (!$this->relationLoaded('roles.permissions')) {
            $this->load('roles.permissions');
        }

        // Permisos directos
        foreach ($this->permissions as $perm) {
            $result[$perm->name] = ($result[$perm->name] ?? 0) | $perm->bitmask;
        }

        // Permisos por roles
        foreach ($this->roles as $role) {
            foreach ($role->permissions as $perm) {
                $result[$perm->name] = ($result[$perm->name] ?? 0) | $perm->bitmask;
            }
        }

        return $result;
    }

    /**
     * 🔥 NUEVO: Método para obtener permisos como array simple (sin bitmask)
     * Útil para el frontend y verificaciones simples
     */
    public function getSimplePermissions()
    {
        $permissions = [];
        $combined = $this->combinedPermissions();

        // Convertir permisos con bitmask a array de strings
        foreach ($combined as $permName => $bitmask) {
            $permissions[] = $permName;

            // También agregar permisos específicos basados en bitmask
            if ($bitmask & 1)
                $permissions[] = "{$permName}.delete";
            if ($bitmask & 2)
                $permissions[] = "{$permName}.edit";
            if ($bitmask & 4)
                $permissions[] = "{$permName}.view";
            if ($bitmask & 8)
                $permissions[] = "{$permName}.create";
        }

        return array_unique($permissions);
    }

    /**
     * 🔥 NUEVO: Método para verificar acceso a rutas del sistema
     */
    public function canAccessRoute($routeKey)
    {
        // SUPERADMIN puede acceder a todo
        if ($this->hasRole('SUPERADMIN')) {
            return true;
        }

        // Verificar si la ruta está asignada directamente al usuario
        if ($this->rutas->contains('key', $routeKey)) {
            return true;
        }

        // Verificar si algún rol del usuario tiene acceso a la ruta
        foreach ($this->roles as $role) {
            if ($role->rutas->contains('key', $routeKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 🔥 NUEVO: Método para verificar gestión de gerencia/centro
     */
    public function canManageGerencia($gerenciaId = null)
    {
        // SUPERADMIN puede gestionar cualquier gerencia
        if ($this->hasRole('SUPERADMIN')) {
            return true;
        }

        // ADMIN solo puede gestionar su propia gerencia
        if ($this->hasRole('ADMIN')) {
            return $gerenciaId ? $this->gerencia_id === $gerenciaId : true;
        }

        return false;
    }

    public function canManageCentro($centroId = null)
    {
        // SUPERADMIN puede gestionar cualquier centro
        if ($this->hasRole('SUPERADMIN')) {
            return true;
        }

        // ADMIN solo puede gestionar centros de su gerencia
        if ($this->hasRole('ADMIN')) {
            if ($centroId) {
                // Verificar si el centro pertenece a su gerencia
                $centro = CentroGestor::find($centroId);
                return $centro && $centro->gerencia_id === $this->gerencia_id;
            }
            return true;
        }

        return false;
    }

    /**
     * 🔥 NUEVO: Scope para filtrar usuarios por rol
     */
    public function scopeWithRole($query, $role)
    {
        return $query->whereHas('roles', function ($q) use ($role) {
            $q->where('name', $role);
        });
    }

    /**
     * 🔥 NUEVO: Scope para filtrar usuarios por gerencia
     */
    public function scopeInGerencia($query, $gerenciaId)
    {
        return $query->where('gerencia_id', $gerenciaId);
    }

    /**
     * 🔥 NUEVO: Método para asignar rol
     */
    public function assignRole($role)
    {
        if (is_string($role)) {
            $role = Role::where('name', $role)->firstOrFail();
        }

        $this->roles()->syncWithoutDetaching([$role->id]);

        return $this;
    }

    /**
     * 🔥 NUEVO: Método para remover rol
     */
    public function removeRole($role)
    {
        if (is_string($role)) {
            $role = Role::where('name', $role)->firstOrFail();
        }

        $this->roles()->detach($role->id);

        return $this;
    }

    /**
     * 🔥 NUEVO: Método para sincronizar roles
     */
    public function syncRoles(array $roles)
    {
        $roleIds = [];

        foreach ($roles as $role) {
            if (is_string($role)) {
                $role = Role::where('name', $role)->firstOrFail();
            }
            $roleIds[] = $role->id;
        }

        $this->roles()->sync($roleIds);

        return $this;
    }

    public function evaluacionesComoMedico()
    {
        return $this->hasMany(EvaluacionMedico::class, 'medico_id');
    }

    // Evaluaciones como evaluador
    public function evaluacionesComoEvaluador()
    {
        return $this->hasMany(EvaluacionMedico::class, 'evaluador_id');
    }

    // Períodos creados (si el usuario crea períodos)
    public function periodosEvaluacion()
    {
        return $this->hasMany(EvaluacionPeriodo::class, 'creado_por'); // Si agregas este campo
    }

    // Estadísticas
    public function estadisticasEvaluaciones()
    {
        $evaluaciones = $this->evaluacionesComoMedico()
            ->where('estado', 'publicado')
            ->get();

        return [
            'total_evaluaciones' => $evaluaciones->count(),
            'promedio_general' => $evaluaciones->avg('calificacion_final'),
            'ultima_evaluacion' => $evaluaciones->sortByDesc('fecha_evaluacion')->first(),
            'mejor_evaluacion' => $evaluaciones->sortByDesc('calificacion_final')->first(),
            'peor_evaluacion' => $evaluaciones->sortBy('calificacion_final')->first(),
        ];
    }
}