@php
    $c = $comprobante;
    $e = $empresa;
    $nombre = $e->nombre_comercial ?: $e->razon_social;
    $titulo = \App\Services\ComprobantePdfService::TITULOS[$c->tipo_comprobante] ?? $c->tipo_nombre;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }} {{ $c->numero }}</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family: Arial, Helvetica, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:10px; overflow:hidden; border:1px solid #e2e8f0;">
                    <tr>
                        <td style="background:#047857; color:#ffffff; padding:18px 24px;">
                            <div style="font-size:18px; font-weight:bold;">{{ $nombre }}</div>
                            <div style="font-size:12px; opacity:.9;">RUC {{ $e->ruc }}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px; font-size:14px; line-height:1.6;">
                            <p style="margin:0 0 12px;">Estimado(a) <b>{{ $c->cliente->razon_social }}</b>:</p>
                            <p style="margin:0 0 16px;">Le enviamos su <b>{{ mb_strtolower($titulo) }} {{ $c->numero }}</b>, emitida el {{ $c->fecha_emision->format('d/m/Y') }}.</p>

                            @if ($mensaje)
                                <p style="margin:0 0 16px; padding:10px 12px; background:#f8fafc; border-left:3px solid #047857;">{!! nl2br(e($mensaje)) !!}</p>
                            @endif

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:20px;">
                                <tr>
                                    <td style="padding:12px 16px; font-size:13px; color:#475569;">Comprobante</td>
                                    <td style="padding:12px 16px; font-size:13px; text-align:right; font-weight:bold;">{{ $c->numero }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:0 16px 12px; font-size:13px; color:#475569;">Importe total</td>
                                    <td style="padding:0 16px 12px; font-size:16px; text-align:right; font-weight:bold;">{{ $c->moneda === 'USD' ? 'US$' : 'S/' }} {{ number_format((float) $c->total, 2) }}</td>
                                </tr>
                            </table>

                            <p style="margin:0 0 20px; text-align:center;">
                                <a href="{{ $enlace }}" style="display:inline-block; background:#047857; color:#ffffff; text-decoration:none; padding:12px 22px; border-radius:6px; font-weight:bold;">Ver comprobante (PDF)</a>
                            </p>

                            <p style="margin:0; font-size:12px; color:#64748b;">
                                Adjuntamos el PDF, el XML firmado y la constancia de recepción de SUNAT (CDR).
                                Puede verificar su validez en www.sunat.gob.pe.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:14px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; font-size:12px; color:#64748b;">
                            {{ $e->razon_social }} · {{ $e->direccion }}
                            @if ($e->telefono) · Telf. {{ $e->telefono }} @endif
                            @if ($e->email) · {{ $e->email }} @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>