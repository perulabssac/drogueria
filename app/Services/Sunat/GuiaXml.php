<?php

namespace App\Services\Sunat;

use App\Models\Empresa;
use App\Models\Guia;

/**
 * Arma el XML de la guía de remisión remitente en el formato de SUNAT 2022 (UBL 2.1, DespatchAdvice).
 * Se arma aquí y no con Greenter porque la versión de Greenter compatible con Laravel 13 aún no trae este formato.
 * La firma la pone después greenter/xmldsig (la misma librería que firma las facturas).
 */
class GuiaXml
{
    private const CATALOGO = 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo';

    public function generar(Guia $g, Empresa $e): string
    {
        $g->loadMissing('items', 'comprobante');

        $x = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<DespatchAdvice xmlns="urn:oasis:names:specification:ubl:schema:xsd:DespatchAdvice-2"'
            .' xmlns:ds="http://www.w3.org/2000/09/xmldsig#"'
            .' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"'
            .' xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">'
            .'<ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent/></ext:UBLExtension></ext:UBLExtensions>'
            .'<cbc:UBLVersionID>2.1</cbc:UBLVersionID>'
            .'<cbc:CustomizationID>2.0</cbc:CustomizationID>'
            .'<cbc:ID>'.$this->t($g->serie.'-'.$g->correlativo).'</cbc:ID>'
            .'<cbc:IssueDate>'.$g->fecha_emision->format('Y-m-d').'</cbc:IssueDate>'
            .'<cbc:IssueTime>'.$g->fecha_emision->format('H:i:s').'</cbc:IssueTime>'
            .'<cbc:DespatchAdviceTypeCode listAgencyName="PE:SUNAT" listName="Tipo de Documento" listURI="'.self::CATALOGO.'01">'.Guia::TIPO.'</cbc:DespatchAdviceTypeCode>';

        if ($g->observaciones) {
            $x .= '<cbc:Note>'.$this->t($g->observaciones).'</cbc:Note>';
        }

        // Comprobante que sustenta el traslado (factura o boleta de la venta)
        $c = $g->comprobante;
        if ($c && in_array($c->tipo_comprobante, ['01', '03'], true)) {
            $x .= '<cac:AdditionalDocumentReference>'
                .'<cbc:ID>'.$this->t($c->numero).'</cbc:ID>'
                .'<cbc:DocumentTypeCode listAgencyName="PE:SUNAT" listName="Documento relacionado al transporte" listURI="'.self::CATALOGO.'61">'.$c->tipo_comprobante.'</cbc:DocumentTypeCode>'
                .'<cbc:DocumentType>'.($c->tipo_comprobante === '01' ? 'Factura' : 'Boleta de Venta').'</cbc:DocumentType>'
                .'<cac:IssuerParty><cac:PartyIdentification>'.$this->documento('6', $e->ruc).'</cac:PartyIdentification></cac:IssuerParty>'
                .'</cac:AdditionalDocumentReference>';
        }

        // Firma (el contenido lo completa greenter/xmldsig)
        $x .= '<cac:Signature>'
            .'<cbc:ID>SIGN'.$e->ruc.'</cbc:ID>'
            .'<cac:SignatoryParty>'
            .'<cac:PartyIdentification><cbc:ID>'.$e->ruc.'</cbc:ID></cac:PartyIdentification>'
            .'<cac:PartyName><cbc:Name>'.$this->t($e->razon_social).'</cbc:Name></cac:PartyName>'
            .'</cac:SignatoryParty>'
            .'<cac:DigitalSignatureAttachment><cac:ExternalReference><cbc:URI>#GREENTER-SIGN</cbc:URI></cac:ExternalReference></cac:DigitalSignatureAttachment>'
            .'</cac:Signature>';

        // Remitente (nosotros) y destinatario
        $x .= '<cac:DespatchSupplierParty>'.$this->parte('6', $e->ruc, $e->razon_social).'</cac:DespatchSupplierParty>'
            .'<cac:DeliveryCustomerParty>'.$this->parte($g->destinatario_tipo_doc, $g->destinatario_num_doc, $g->destinatario_nombre).'</cac:DeliveryCustomerParty>';

        // Datos del envío
        $x .= '<cac:Shipment>'
            .'<cbc:ID>SUNAT_Envio</cbc:ID>'
            .'<cbc:HandlingCode listAgencyName="PE:SUNAT" listName="Motivo de traslado" listURI="'.self::CATALOGO.'20">'.$g->motivo.'</cbc:HandlingCode>'
            .'<cbc:HandlingInstructions>'.$this->t($g->motivo_descripcion ?: (Guia::MOTIVOS[$g->motivo] ?? 'Otros')).'</cbc:HandlingInstructions>'
            .'<cbc:GrossWeightMeasure unitCode="'.$g->unidad_peso.'">'.number_format((float) $g->peso_total, 3, '.', '').'</cbc:GrossWeightMeasure>'
            .'<cac:ShipmentStage>'
            .'<cbc:TransportModeCode listName="Modalidad de traslado" listAgencyName="PE:SUNAT" listURI="'.self::CATALOGO.'18">'.$g->modalidad.'</cbc:TransportModeCode>'
            .'<cac:TransitPeriod><cbc:StartDate>'.$g->fecha_traslado->format('Y-m-d').'</cbc:StartDate></cac:TransitPeriod>';

        if ($g->esTransportePrivado()) {
            $x .= '<cac:DriverPerson>'
                .$this->documento($g->conductor_tipo_doc ?: '1', $g->conductor_num_doc)
                .'<cbc:FirstName>'.$this->t($g->conductor_nombres).'</cbc:FirstName>'
                .'<cbc:FamilyName>'.$this->t($g->conductor_apellidos).'</cbc:FamilyName>'
                .'<cbc:JobTitle>Principal</cbc:JobTitle>'
                .'<cac:IdentityDocumentReference><cbc:ID>'.$this->t($g->conductor_licencia).'</cbc:ID></cac:IdentityDocumentReference>'
                .'</cac:DriverPerson>';
        } else {
            $x .= '<cac:CarrierParty>'
                .'<cac:PartyIdentification><cbc:ID schemeID="6">'.$this->t($g->transportista_ruc).'</cbc:ID></cac:PartyIdentification>'
                .'<cac:PartyLegalEntity>'
                .'<cbc:RegistrationName>'.$this->t($g->transportista_nombre).'</cbc:RegistrationName>'
                .($g->transportista_mtc ? '<cbc:CompanyID>'.$this->t($g->transportista_mtc).'</cbc:CompanyID>' : '')
                .'</cac:PartyLegalEntity>'
                .'</cac:CarrierParty>'
                // Fecha de entrega de los bienes al transportista (obligatoria desde junio 2026: error 3617)
                .'<cac:LoadingTransportEvent><cbc:OccurrenceDate>'.$g->fecha_traslado->format('Y-m-d').'</cbc:OccurrenceDate></cac:LoadingTransportEvent>';
        }

        $x .= '</cac:ShipmentStage>'
            .'<cac:Delivery>'
            .'<cac:DeliveryAddress>'.$this->direccion($g->llegada_ubigeo, $g->llegada_direccion).'</cac:DeliveryAddress>'
            .'<cac:Despatch><cac:DespatchAddress>'.$this->direccion($g->partida_ubigeo, $g->partida_direccion).'</cac:DespatchAddress></cac:Despatch>'
            .'</cac:Delivery>';

        if ($g->esTransportePrivado()) {
            $placa = strtoupper(str_replace(['-', ' '], '', $g->vehiculo_placa));
            $x .= '<cac:TransportHandlingUnit><cac:TransportEquipment><cbc:ID>'.$this->t($placa).'</cbc:ID></cac:TransportEquipment></cac:TransportHandlingUnit>';
        }

        $x .= '</cac:Shipment>';

        // Bienes
        foreach ($g->items->values() as $n => $item) {
            $linea = $n + 1;
            $x .= '<cac:DespatchLine>'
                .'<cbc:ID>'.$linea.'</cbc:ID>'
                .'<cbc:DeliveredQuantity unitCode="'.$this->t($item->unidad).'">'.$this->cantidad($item->cantidad).'</cbc:DeliveredQuantity>'
                .'<cac:OrderLineReference><cbc:LineID>'.$linea.'</cbc:LineID></cac:OrderLineReference>'
                .'<cac:Item>'
                .'<cbc:Description>'.$this->t($item->descripcion).'</cbc:Description>'
                .($item->codigo ? '<cac:SellersItemIdentification><cbc:ID>'.$this->t($item->codigo).'</cbc:ID></cac:SellersItemIdentification>' : '')
                .'</cac:Item>'
                .'</cac:DespatchLine>';
        }

        return $x.'</DespatchAdvice>';
    }

    // ================= Apoyo =================

    /** Texto escapado para XML (&, <, >, comillas). */
    private function t(?string $texto): string
    {
        return htmlspecialchars(trim((string) $texto), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function documento(string $tipo, ?string $numero): string
    {
        return '<cbc:ID schemeID="'.$this->t($tipo).'" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="'.self::CATALOGO.'06">'.$this->t($numero).'</cbc:ID>';
    }

    private function parte(string $tipo, ?string $numero, ?string $nombre): string
    {
        return '<cac:Party>'
            .'<cac:PartyIdentification>'.$this->documento($tipo, $numero).'</cac:PartyIdentification>'
            .'<cac:PartyLegalEntity><cbc:RegistrationName>'.$this->t($nombre).'</cbc:RegistrationName></cac:PartyLegalEntity>'
            .'</cac:Party>';
    }

    private function direccion(?string $ubigeo, ?string $direccion): string
    {
        return '<cbc:ID schemeAgencyName="PE:INEI" schemeName="Ubigeos">'.$this->t($ubigeo).'</cbc:ID>'
            .'<cac:AddressLine><cbc:Line>'.$this->t($direccion).'</cbc:Line></cac:AddressLine>';
    }

    /** Cantidad sin ceros de más: 5.0000 → 5 ; 2.5000 → 2.5 */
    private function cantidad($valor): string
    {
        $texto = rtrim(rtrim(number_format((float) $valor, 10, '.', ''), '0'), '.');

        return $texto === '' ? '0' : $texto;
    }
}