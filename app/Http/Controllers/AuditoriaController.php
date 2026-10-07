<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consulta de la auditoría (solo administrador): quién hizo qué, cuándo y desde dónde.
 * Es de solo lectura: los registros no se pueden editar ni borrar desde el sistema.
 */
class AuditoriaController extends Controller
{
    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'usuario' => ['nullable', 'integer'],
            'modulo' => ['nullable', 'string', 'max:40'],
            'evento' => ['nullable', 'string', 'max:30'],
            'buscar' => ['nullable', 'string', 'max:100'],
        ]);

        // Por defecto, los últimos 7 días
        $desde = $filtros['desde'] ?? today()->subDays(6)->toDateString();
        $hasta = $filtros['hasta'] ?? today()->toDateString();

        $registros = Auditoria::query()
            ->with('usuario:id,name,rol')
            ->whereDate('created_at', '>=', $desde)
            ->whereDate('created_at', '<=', $hasta)
            ->when($filtros['usuario'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filtros['modulo'] ?? null, fn ($q, $m) => $q->where('modulo', $m))
            ->when($filtros['evento'] ?? null, fn ($q, $e) => $q->where('evento', $e))
            ->when($filtros['buscar'] ?? null, fn ($q, $b) => $q->where('descripcion', 'like', "%{$b}%"))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Auditoria/Index', [
            'registros' => $registros,
            'filtros' => [...$filtros, 'desde' => $desde, 'hasta' => $hasta],
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
            'modulos' => Auditoria::query()->distinct()->orderBy('modulo')->pluck('modulo'),
            'eventos' => Auditoria::EVENTOS,
        ]);
    }
}