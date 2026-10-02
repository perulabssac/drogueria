<?php

namespace App\Services\Sunat;

use App\Models\Comprobante;
use App\Models\ComprobanteItem;
use App\Models\Empresa;
use App\Support\NumeroALetras;
use DateTime;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\Cuota;
use Greenter\Model\Sale\Document;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\FormaPagos\FormaPagoCredito;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;

/**
 * Traduce los comprobantes del sistema a los objetos de Greenter (que luego se convierten en XML).
 */
class DocumentoBuilder
{
    /** Factura, boleta o nota de crédito/débito. */
    public function documento(Comprobante $c, Empresa $empresa): Invoice|Note
    {
        $c->loadMissing(['items', 'cliente', 'sucursal', 'referencia', 'cuotas']);

        $documento = $c->esNota() ? $this->nota($c) : $this->factura($c);
        $gratuitas = $c->items->where('bonificacion', true);
        $igvGratuitas = (float) $gratuitas->whereIn('tipo_afectacion_igv', ['11', '12', '13', '14', '15', '16'])->sum('igv');
        $valorVenta = (float) $c->op_gravadas + (float) $c->op_exoneradas + (float) $c->op_inafectas;

        $documento
            ->setUblVersion('2.1')
            ->setTipoDoc($c->tipo_comprobante)
            ->setSerie($c->serie)
            ->setCorrelativo((string) $c->correlativo)
            ->setFechaEmision(new DateTime($c->fecha_emision->format('Y-m-d H:i:s')))
            ->setTipoMoneda($c->moneda ?: 'PEN')
            ->setCompany($this->empresa($empresa, $c->sucursal->codigo_establecimiento))
            ->setClient($this->cliente($c))
            ->setMtoOperGravadas((float) $c->op_gravadas)
            ->setMtoOperExoneradas((float) $c->op_exoneradas)
            ->setMtoOperInafectas((float) $c->op_inafectas)
            ->setMtoIGV((float) $c->igv)
            ->setTotalImpuestos((float) $c->igv)
            ->setValorVenta($valorVenta)
            ->setSubTotal((float) $c->total)
            ->setMtoImpVenta((float) $c->total)
            ->setDetails($c->items->map(fn (ComprobanteItem $i) => $this->detalle($i))->all())
            ->setLegends($this->leyendas($c, $gratuitas->isNotEmpty()));

        if ($gratuitas->isNotEmpty()) {
            $documento->setMtoOperGratuitas((float) $c->op_gratuitas)->setMtoIGVGratuitas($igvGratuitas);
        }

        return $documento;
    }

    /**
     * Resumen diario con un solo comprobante (boleta o nota de boleta), para informarlo en el momento.
     * $estado: 1 = adicionar, 3 = anular (comunicar baja).
     */
    public function resumen(Comprobante $c, Empresa $empresa, string $correlativoResumen, string $estado = '1'): Summary
    {
        $c->loadMissing(['cliente', 'referencia']);

        $detalle = (new SummaryDetail())
            ->setTipoDoc($c->tipo_comprobante)
            ->setSerieNro($c->numero)
            ->setEstado($estado)
            ->setClienteTipo($c->cliente->tipo_documento)
            ->setClienteNro($c->cliente->numero_documento)
            ->setTotal((float) $c->total)
            ->setMtoOperGravadas((float) $c->op_gravadas)
            ->setMtoOperExoneradas((float) $c->op_exoneradas)
            ->setMtoOperInafectas((float) $c->op_inafectas)
            ->setMtoOtrosCargos(0)
            ->setMtoIGV((float) $c->igv);

        if ((float) $c->op_gratuitas > 0) {
            $detalle->setMtoOperGratuitas((float) $c->op_gratuitas);
        }

        if ($c->esNota()) {
            $detalle->setDocReferencia((new Document())
                ->setTipoDoc($c->referencia->tipo_comprobante)
                ->setNroDoc($c->referencia->numero));
        }

        return (new Summary())
            ->setFecGeneracion(new DateTime($c->fecha_emision->format('Y-m-d')))
            ->setFecResumen(new DateTime())
            ->setCorrelativo($correlativoResumen)
            ->setCompany($this->empresa($empresa, '0000'))
            ->setDetails([$detalle]);
    }

    private function factura(Comprobante $c): Invoice
    {
        $factura = (new Invoice())->setTipoOperacion('0101'); // venta interna

        if ($c->esCredito()) {
            $factura
                ->setFormaPago(new FormaPagoCredito((float) $c->total))
                ->setCuotas($c->cuotas->map(fn ($cuota) => (new Cuota())
                    ->setMonto((float) $cuota->monto)
                    ->setFechaPago(new DateTime($cuota->fecha_vencimiento->format('Y-m-d'))))->all());
        } else {
            $factura->setFormaPago(new FormaPagoContado());
        }

        if ($c->guia_remision) {
            $factura->setGuias([(new Document())->setTipoDoc('09')->setNroDoc($c->guia_remision)]);
        }
        if ($c->orden_compra) {
            $factura->setCompra($c->orden_compra);
        }

        return $factura;
    }

    private function nota(Comprobante $c): Note
    {
        return (new Note())
            ->setTipDocAfectado($c->referencia->tipo_comprobante)
            ->setNumDocfectado($c->referencia->numero)
            ->setCodMotivo($c->motivo_codigo)
            ->setDesMotivo($c->motivo_descripcion);
    }

    private function empresa(Empresa $e, string $codigoLocal): Company
    {
        return (new Company())
            ->setRuc($e->ruc)
            ->setRazonSocial($e->razon_social)
            ->setNombreComercial($e->nombre_comercial ?: $e->razon_social)
            ->setAddress((new Address())
                ->setUbigueo($e->ubigeo)
                ->setDepartamento($e->departamento)
                ->setProvincia($e->provincia)
                ->setDistrito($e->distrito)
                ->setUrbanizacion($e->urbanizacion ?: '-')
                ->setDireccion($e->direccion)
                ->setCodLocal($codigoLocal));
    }

    private function cliente(Comprobante $c): Client
    {
        $cliente = (new Client())
            ->setTipoDoc($c->cliente->tipo_documento)
            ->setNumDoc($c->cliente->numero_documento)
            ->setRznSocial($c->cliente->razon_social);

        if ($c->cliente->direccion) {
            $cliente->setAddress((new Address())->setDireccion($c->cliente->direccion));
        }

        return $cliente;
    }

    private function detalle(ComprobanteItem $i): SaleDetail
    {
        $afectacion = $i->tipo_afectacion_igv;
        $gravado = in_array($afectacion, ['10', '11', '12', '13', '14', '15', '16'], true);

        $detalle = (new SaleDetail())
            ->setCodProducto($i->codigo)
            ->setUnidad($i->unidad_sunat)
            ->setCantidad((float) $i->cantidad)
            ->setDescripcion($i->descripcion)
            ->setMtoBaseIgv((float) $i->valor_venta)
            ->setPorcentajeIgv($gravado ? 18.0 : 0.0)
            ->setIgv((float) $i->igv)
            ->setTipAfeIgv($afectacion)
            ->setTotalImpuestos((float) $i->igv)
            ->setMtoValorVenta((float) $i->valor_venta);

        if ($i->bonificacion) {
            // Entrega gratuita: no tiene precio, solo valor referencial
            $detalle->setMtoValorUnitario(0)
                ->setMtoValorGratuito((float) $i->valor_unitario)
                ->setMtoPrecioUnitario(0);
        } else {
            $detalle->setMtoValorUnitario((float) $i->valor_unitario)
                ->setMtoPrecioUnitario((float) $i->precio_unitario);
        }

        return $detalle;
    }

    private function leyendas(Comprobante $c, bool $tieneGratuitas): array
    {
        $leyendas = [
            (new Legend())->setCode('1000')->setValue(NumeroALetras::convertir((float) $c->total, $c->moneda === 'USD' ? 'DOLARES AMERICANOS' : 'SOLES')),
        ];

        if ($tieneGratuitas) {
            $leyendas[] = (new Legend())->setCode('1002')->setValue('TRANSFERENCIA GRATUITA DE UN BIEN Y/O SERVICIO PRESTADO GRATUITAMENTE');
        }

        return $leyendas;
    }
}