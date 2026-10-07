<?php

namespace App\Observers;

use App\Models\Ajuste;
use App\Models\Caja;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\CompraPago;
use App\Models\Comprobante;
use App\Models\Empresa;
use App\Models\Importacion;
use App\Models\Laboratorio;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Serie;
use App\Models\TomaInventario;
use App\Models\User;
use App\Support\Auditor;
use Illuminate\Database\Eloquent\Model;

/**
 * Registra automáticamente en la auditoría las altas, cambios y bajas de los modelos importantes.
 * Se activa en AppServiceProvider para cada modelo de MODELOS.
 */
class AuditoriaObserver
{
    /**
     * Modelo => configuración:
     * - nombre: cómo se llama en la auditoría
     * - eventos: qué se registra (por defecto creado, actualizado y eliminado)
     * - solo: si se indica, solo se registran cambios en estos campos
     * - ignorar: campos que no se registran (cambian solos o no aportan)
     */
    public const MODELOS = [
        Producto::class => ['nombre' => 'Producto'],
        Cliente::class => ['nombre' => 'Cliente', 'ignorar' => ['verificado_at', 'estado_sunat', 'condicion_sunat']],
        Proveedor::class => ['nombre' => 'Proveedor'],
        Laboratorio::class => ['nombre' => 'Laboratorio'],
        User::class => ['nombre' => 'Usuario', 'ignorar' => ['remember_token']],
        Empresa::class => ['nombre' => 'Empresa'],
        Serie::class => ['nombre' => 'Serie', 'ignorar' => ['correlativo']],
        Caja::class => ['nombre' => 'Caja', 'solo' => ['estado', 'efectivo_contado', 'diferencia']],
        Compra::class => ['nombre' => 'Compra', 'solo' => ['estado']],
        CompraPago::class => ['nombre' => 'Pago a proveedor', 'solo' => ['estado']],
        // Comprobantes: su estado SUNAT cambia solo; se registra la emisión y la comunicación de baja
        Comprobante::class => ['nombre' => 'Comprobante', 'solo' => ['baja_estado']],
        Ajuste::class => ['nombre' => 'Ajuste de inventario', 'eventos' => ['creado']],
        TomaInventario::class => ['nombre' => 'Toma de inventario', 'solo' => ['estado']],
        Importacion::class => ['nombre' => 'Importación', 'eventos' => ['creado']],
    ];

    /** Campos que nunca se guardan con su valor real. */
    private const OCULTOS = ['password', 'sol_clave', 'certificado_password'];

    /** Campos que nunca se registran. */
    private const SIEMPRE_IGNORADOS = ['created_at', 'updated_at'];

    public function created(Model $modelo): void
    {
        if ($this->registra($modelo, 'creado')) {
            Auditor::registrar('creado', $this->nombre($modelo), $this->nombre($modelo).' '.$this->etiqueta($modelo).' creado', $modelo);
        }
    }

    public function updated(Model $modelo): void
    {
        if (! $this->registra($modelo, 'actualizado')) {
            return;
        }

        $config = self::MODELOS[$modelo::class] ?? [];
        $cambios = [];
        foreach ($modelo->getChanges() as $campo => $nuevo) {
            if (in_array($campo, self::SIEMPRE_IGNORADOS, true) || in_array($campo, $config['ignorar'] ?? [], true)) {
                continue;
            }
            if (isset($config['solo']) && ! in_array($campo, $config['solo'], true)) {
                continue;
            }
            $cambios[$campo] = in_array($campo, self::OCULTOS, true)
                ? ['(oculto)', '(cambiado)']
                : [$this->valor($modelo->getOriginal($campo)), $this->valor($nuevo)];
        }

        if ($cambios) {
            $resumen = implode(', ', array_keys($cambios));
            Auditor::registrar('actualizado', $this->nombre($modelo), $this->nombre($modelo).' '.$this->etiqueta($modelo).": cambió {$resumen}", $modelo, $cambios);
        }
    }

    public function deleted(Model $modelo): void
    {
        if ($this->registra($modelo, 'eliminado')) {
            Auditor::registrar('eliminado', $this->nombre($modelo), $this->nombre($modelo).' '.$this->etiqueta($modelo).' eliminado', $modelo);
        }
    }

    private function registra(Model $modelo, string $evento): bool
    {
        return in_array($evento, self::MODELOS[$modelo::class]['eventos'] ?? ['creado', 'actualizado', 'eliminado'], true);
    }

    private function nombre(Model $modelo): string
    {
        return self::MODELOS[$modelo::class]['nombre'] ?? class_basename($modelo);
    }

    /** Cómo se identifica el registro: F001-25, AMOX500 AMOXICILINA, 20123456789 BOTICA... */
    private function etiqueta(Model $m): string
    {
        return match (true) {
            $m instanceof Producto => "{$m->codigo} {$m->nombre}",
            $m instanceof Cliente, $m instanceof Proveedor => trim(($m->numero_documento ?? $m->ruc).' '.$m->razon_social),
            $m instanceof User => "{$m->name} ({$m->email})",
            $m instanceof Empresa => (string) $m->razon_social,
            $m instanceof Serie => (string) $m->serie,
            $m instanceof Laboratorio => (string) $m->nombre,
            $m instanceof Compra => (string) $m->documento,
            $m instanceof Comprobante, $m instanceof Ajuste, $m instanceof TomaInventario, $m instanceof Importacion => (string) $m->numero,
            default => '#'.$m->getKey(),
        };
    }

    /** Valores legibles y cortos para guardar en el JSON. */
    private function valor(mixed $valor): mixed
    {
        return match (true) {
            is_bool($valor) => $valor ? 'Sí' : 'No',
            $valor instanceof \DateTimeInterface => $valor->format('Y-m-d H:i'),
            is_array($valor) => '(lista)',
            is_string($valor) && mb_strlen($valor) > 200 => mb_substr($valor, 0, 200).'…',
            default => $valor,
        };
    }
}