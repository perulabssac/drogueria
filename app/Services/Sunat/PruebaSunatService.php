<?php

namespace App\Services\Sunat;

use App\Models\Empresa;
use App\Support\NumeroALetras;
use DateTime;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\SaleDetail;
use Throwable;

/**
 * Envía a SUNAT beta una factura de prueba que NO se guarda en el sistema
 * ni consume correlativos. Sirve para comprobar que todo está bien configurado.
 */
class PruebaSunatService
{
    public function __construct(private GreenterFactory $factory) {}

    public function enviarFacturaPrueba(Empresa $empresa): array
    {
        try {
            $see = $this->factory->crear($empresa);
            $resultado = $see->send($this->facturaPrueba($empresa));
        } catch (Throwable $e) {
            return ['exito' => false, 'codigo' => null, 'mensaje' => $e->getMessage(), 'notas' => []];
        }

        if (! $resultado->isSuccess()) {
            return [
                'exito' => false,
                'codigo' => $resultado->getError()?->getCode(),
                'mensaje' => $resultado->getError()?->getMessage(),
                'notas' => [],
            ];
        }

        $cdr = $resultado->getCdrResponse();

        return [
            'exito' => (int) $cdr->getCode() === 0,
            'codigo' => $cdr->getCode(),
            'mensaje' => $cdr->getDescription(),
            'notas' => $cdr->getNotes() ?? [],
            'hash' => preg_match('/<ds:DigestValue>([^<]+)</', $see->getFactory()->getLastXml() ?? '', $m) ? $m[1] : null,
        ];
    }

    private function facturaPrueba(Empresa $empresa): Invoice
    {
        // 1 producto de S/ 118.00 (100.00 + 18.00 de IGV)
        $detalle = (new SaleDetail())
            ->setCodProducto('PRUEBA')
            ->setUnidad('NIU')
            ->setCantidad(1)
            ->setDescripcion('PRODUCTO DE PRUEBA - CONEXION SUNAT')
            ->setMtoValorUnitario(100)
            ->setMtoBaseIgv(100)
            ->setPorcentajeIgv(18)
            ->setIgv(18)
            ->setTipAfeIgv('10')
            ->setTotalImpuestos(18)
            ->setMtoValorVenta(100)
            ->setMtoPrecioUnitario(118);

        return (new Invoice())
            ->setUblVersion('2.1')
            ->setTipoOperacion('0101')
            ->setTipoDoc('01')
            ->setSerie('F999')
            ->setCorrelativo((string) random_int(1, 99999999))
            ->setFechaEmision(new DateTime())
            ->setFormaPago(new FormaPagoContado())
            ->setTipoMoneda('PEN')
            ->setCompany($this->empresa($empresa))
            ->setClient((new Client())->setTipoDoc('6')->setNumDoc('20123456786')->setRznSocial('CLIENTE DE PRUEBA S.A.C.'))
            ->setMtoOperGravadas(100)
            ->setMtoIGV(18)
            ->setTotalImpuestos(18)
            ->setValorVenta(100)
            ->setSubTotal(118)
            ->setMtoImpVenta(118)
            ->setDetails([$detalle])
            ->setLegends([(new Legend())->setCode('1000')->setValue(NumeroALetras::convertir(118))]);
    }

    private function empresa(Empresa $e): Company
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
                ->setCodLocal('0000'));
    }
}