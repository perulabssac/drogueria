@php
    $documentos = ['6' => 'RUC', '1' => 'DNI', '4' => 'C.E.', '7' => 'PASAPORTE', '0' => 'DOC.'];
    $monto = fn ($v) => number_format((float) $v, 2, '.', ',');
    $direccionEmpresa = collect([$e->direccion, $e->distrito, $e->provincia, $e->departamento])->filter()->implode(' - ');
    $hayGratuitas = (float) $c->op_gratuitas > 0;
    $sinValidez = in_array($c->estado, \App\Models\Comprobante::ESTADOS_SIN_VALIDEZ, true);
    $logo = $e->logoDataUri(); // imagen incrustada: dompdf no descarga nada de internet
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>{{ $c->numero }}</title>
    <style>
        @page { margin: 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #0f172a; line-height: 1.35; }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        .derecha { text-align: right; }
        .centro { text-align: center; }
        .negrita { font-weight: bold; }
        .caja { border: 1px solid #94a3b8; border-radius: 4px; padding: 6px 8px; }
        .recuadro { border: 2px solid #0f172a; border-radius: 4px; text-align: center; padding: 8px 6px; }
        .recuadro .tipo { background: #0f172a; color: #fff; font-weight: bold; padding: 4px 0; margin: 4px 0; }
        .detalle th { background: #0f172a; color: #fff; padding: 4px; font-size: 8pt; text-align: left; white-space: nowrap; }
        .detalle td { padding: 4px; border-bottom: 1px solid #cbd5e1; }
        .totales td { padding: 2px 0; }
        .total { border-top: 2px solid #0f172a; font-weight: bold; font-size: 10pt; }
        .datos th { text-align: left; font-weight: bold; padding: 1px 6px 1px 0; white-space: nowrap; }
        .datos td { padding: 1px 0; }
        .pie { border-top: 1px solid #94a3b8; margin-top: 14px; padding-top: 8px; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.5pt; }
        .marca { position: fixed; top: 38%; left: 0; right: 0; text-align: center; font-size: 70pt; font-weight: bold;
                 color: #dc2626; opacity: 0.15; transform: rotate(-30deg); }
    </style>
</head>
<body>
    @if ($sinValidez)
        <div class="marca">{{ $c->estado === 'anulado' ? 'ANULADO' : 'SIN VALIDEZ' }}</div>
    @endif

    {{-- Cabecera: emisor + recuadro del comprobante --}}
    <table>
        <tr>
            <td style="padding-right: 16px;">
                @if ($logo)
                    {{-- Con logo: el logo ya muestra el nombre comercial; la razón social es obligatoria --}}
                    <img src="{{ $logo }}" alt="Logo" style="max-height: 64px; max-width: 260px; margin-bottom: 6px;">
                    <div class="negrita" style="font-size: 10pt;">{{ $e->razon_social }}</div>
                @else
                    <div style="font-size: 13pt; font-weight: bold;">{{ $e->nombre_comercial ?: $e->razon_social }}</div>
                    @if ($e->nombre_comercial)
                        <div class="negrita">{{ $e->razon_social }}</div>
                    @endif
                @endif
                @if ($e->giro)
                    <div style="font-style: italic; color: #334155; margin-bottom: 2px;">{{ $e->giro }}</div>
                @endif
                <div>{{ $direccionEmpresa }}</div>
                @if ($c->sucursal && $c->sucursal->direccion && $c->sucursal->direccion !== $e->direccion)
                    <div>Establecimiento: {{ $c->sucursal->direccion }}</div>
                @endif
                @if ($e->telefono || $e->email)
                    <div>
                        @if ($e->telefono) Telf.: {{ $e->telefono }} @endif
                        @if ($e->telefono && $e->email) · @endif
                        @if ($e->email) {{ $e->email }} @endif
                    </div>
                @endif
            </td>
            <td style="width: 210px;">
                <div class="recuadro">
                    <div class="negrita" style="font-size: 10pt;">R.U.C. {{ $e->ruc }}</div>
                    <div class="tipo">{{ $titulo }}</div>
                    <div class="negrita" style="font-size: 12pt;">{{ $c->numero }}</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- Datos del cliente y de la operación (tablas: DomPDF respeta mejor los anchos) --}}
    <div class="caja" style="margin-top: 10px;">
        <table>
            <tr>
                <td>
                    <table class="datos">
                        <tr><th style="width: 62pt;">Cliente:</th><td>{{ $c->cliente->razon_social }}</td></tr>
                        <tr><th>{{ $documentos[$c->cliente->tipo_documento] ?? 'DOC.' }}:</th><td>{{ $c->cliente->numero_documento }}</td></tr>
                        @if ($c->cliente->direccion)
                            <tr><th>Dirección:</th><td>{{ $c->cliente->direccion }}</td></tr>
                        @endif
                        @if ($c->vendedor)
                            <tr><th>Vendedor:</th><td>{{ $c->vendedor->name }}</td></tr>
                        @endif
                    </table>
                </td>
                <td style="width: 215pt;">
                    <table class="datos">
                        <tr><th style="width: 92pt;">Fecha de emisión:</th><td>{{ $c->fecha_emision->format('d/m/Y H:i') }}</td></tr>
                        <tr><th>Condición:</th><td>{{ $c->forma_pago === 'credito' ? 'CRÉDITO' : 'CONTADO' }}</td></tr>
                        @if ($c->fecha_vencimiento)
                            <tr><th>Vencimiento:</th><td>{{ $c->fecha_vencimiento->format('d/m/Y') }}</td></tr>
                        @endif
                        <tr><th>Moneda:</th><td>{{ $c->moneda === 'USD' ? 'DÓLARES' : 'SOLES' }}</td></tr>
                        @if ($c->guia_remision)
                            <tr><th>Guía de remisión:</th><td>{{ $c->guia_remision }}</td></tr>
                        @endif
                        @if ($c->orden_compra)
                            <tr><th>Orden de compra:</th><td>{{ $c->orden_compra }}</td></tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>
        @if ($c->referencia)
            <div style="margin-top: 4px;">
                <b>Documento que modifica:</b> {{ $c->referencia->tipo_nombre }} {{ $c->referencia->numero }}
                @if ($c->motivo_descripcion) · Motivo: {{ $c->motivo_descripcion }} @endif
            </div>
        @endif
    </div>

    {{-- Detalle: una línea por lote --}}
    <table class="detalle" style="margin-top: 10px;">
        <thead>
            <tr>
                <th class="derecha" style="width: 40px;">Cant.</th>
                <th style="width: 35px;">Unid.</th>
                <th style="width: 55px;">Código</th>
                <th>Descripción</th>
                <th style="width: 60px;">Lote</th>
                <th style="width: 45px;">Venc.</th>
                <th class="derecha" style="width: 55px;">P. unit.</th>
                <th class="derecha" style="width: 60px;">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($c->items as $i)
                <tr>
                    <td class="derecha">{{ rtrim(rtrim(number_format((float) $i->cantidad, 2, '.', ''), '0'), '.') }}</td>
                    <td>{{ $i->unidad }}</td>
                    <td>{{ $i->codigo }}</td>
                    <td>{{ $i->descripcion }}@if ($i->bonificacion) <b>(BONIFICACIÓN)</b>@endif</td>
                    <td>{{ $i->numero_lote }}</td>
                    <td>{{ $i->fecha_vencimiento ? \Illuminate\Support\Carbon::parse($i->fecha_vencimiento)->format('m/Y') : '' }}</td>
                    <td class="derecha">{{ $i->bonificacion ? '0.000' : number_format((float) $i->precio_unitario, 3, '.', ',') }}</td>
                    <td class="derecha">{{ $monto($i->total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Importe en letras + totales --}}
    <table style="margin-top: 10px;">
        <tr>
            <td style="padding-right: 16px;">
                <div class="caja"><b>SON:</b> {{ $letras }}</div>
                @if ($hayGratuitas)
                    <div class="negrita" style="margin-top: 6px;">TRANSFERENCIA GRATUITA DE UN BIEN Y/O SERVICIO PRESTADO GRATUITAMENTE</div>
                @endif

                @if ($c->cuotas->isNotEmpty())
                    <div class="caja" style="margin-top: 6px;">
                        <div class="negrita">Información del crédito</div>
                        @foreach ($c->cuotas as $q)
                            <div>Cuota {{ $q->numero }}: vence {{ \Illuminate\Support\Carbon::parse($q->fecha_vencimiento)->format('d/m/Y') }} · S/ {{ $monto($q->monto) }}</div>
                        @endforeach
                    </div>
                @endif

                @if ($c->pagos->where('tipo', 'venta')->isNotEmpty())
                    <div class="caja" style="margin-top: 6px;">
                        <div class="negrita">Forma de pago</div>
                        @foreach ($c->pagos->where('tipo', 'venta') as $p)
                            <div>
                                {{ $p->medio_nombre }}: S/ {{ $monto($p->monto) }}
                                @if ($p->referencia) · Op. {{ $p->referencia }} @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($c->observaciones)
                    <div style="margin-top: 6px;"><b>Observaciones:</b> {{ $c->observaciones }}</div>
                @endif
            </td>
            <td style="width: 210px;">
                <table class="totales">
                    <tr><td>Op. gravadas</td><td class="derecha">S/ {{ $monto($c->op_gravadas) }}</td></tr>
                    <tr><td>Op. exoneradas</td><td class="derecha">S/ {{ $monto($c->op_exoneradas) }}</td></tr>
                    @if ((float) $c->op_inafectas > 0)
                        <tr><td>Op. inafectas</td><td class="derecha">S/ {{ $monto($c->op_inafectas) }}</td></tr>
                    @endif
                    @if ($hayGratuitas)
                        <tr><td>Op. gratuitas</td><td class="derecha">S/ {{ $monto($c->op_gratuitas) }}</td></tr>
                    @endif
                    <tr><td>IGV (18%)</td><td class="derecha">S/ {{ $monto($c->igv) }}</td></tr>
                    <tr class="total"><td style="padding-top: 4px;">IMPORTE TOTAL</td><td class="derecha" style="padding-top: 4px;">S/ {{ $monto($c->total) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Pie: QR, hash, leyenda y cuentas bancarias --}}
    <div class="pie">
        <table>
            <tr>
                @if ($qr)
                    <td style="width: 95px;"><img src="{{ $qr }}" style="width: 85px; height: 85px;" alt="QR"></td>
                @endif
                <td>
                    @if ($c->hash)
                        <div><b>Resumen (hash):</b> <span class="mono">{{ $c->hash }}</span></div>
                    @endif
                    @if ($c->esInterno())
                        <div class="negrita">DOCUMENTO DE USO INTERNO. NO ES COMPROBANTE DE PAGO. Solicite su boleta o factura.</div>
                    @else
                        <div>Representación impresa de la {{ $titulo }}. Puede consultarla en <b>www.sunat.gob.pe</b>.</div>
                    @endif
                    @if ($c->estado === 'anulado')
                        <div class="negrita" style="color: #dc2626; margin-top: 4px;">
                            Comprobante dado de baja ante SUNAT ({{ $c->baja_documento }}). No tiene validez.
                        </div>
                    @endif
                    @if ($e->cuentas_bancarias)
                        <div style="margin-top: 6px;">
                            <div class="negrita">Cuentas bancarias</div>
                            <div>{!! nl2br(e($e->cuentas_bancarias)) !!}</div>
                        </div>
                    @endif
                </td>
            </tr>
        </table>
    </div>
</body>
</html>