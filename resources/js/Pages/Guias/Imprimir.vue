<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { fecha, cantidad } from '@/utils/formato';

const props = defineProps({
    guia: Object,
    empresa: Object,
    motivos: Object,
});

const g = props.guia;
const qrSvg = ref('');
const imprimir = () => window.print();

// La guía se imprime siempre en A4 (aunque la impresora predeterminada sea la ticketera)
let estiloPagina;

onMounted(async () => {
    estiloPagina = document.createElement('style');
    estiloPagina.textContent = '@page { size: A4; margin: 10mm; } html, body { background: #fff; }';
    document.head.appendChild(estiloPagina);

    // SUNAT entrega en el CDR un enlace para consultar la guía: ese enlace va en el QR
    const contenido = g.enlace_qr || [props.empresa.ruc, '09', g.serie, g.correlativo, g.fecha_emision.substring(0, 10), g.destinatario_tipo_doc, g.destinatario_num_doc].join('|');
    qrSvg.value = await QRCode.toString(contenido, { type: 'svg', margin: 0, errorCorrectionLevel: 'Q' });
    setTimeout(imprimir, 400);
});

onUnmounted(() => estiloPagina?.remove());
</script>

<template>
    <Head :title="`Guía ${g.numero}`" />

    <div class="no-print bg-slate-800 text-white px-4 py-2 flex items-center gap-3 text-sm">
        <span>Representación impresa de la guía {{ g.numero }}.</span>
        <span v-if="g.estado !== 'aceptado'" class="bg-amber-500 text-black rounded px-2">Aún no aceptada por SUNAT</span>
        <button type="button" class="ml-auto bg-purple-600 hover:bg-purple-700 rounded px-3 py-1" @click="imprimir">
            <i class="pi pi-print mr-1"></i> Imprimir
        </button>
    </div>

    <div class="max-w-4xl mx-auto p-6 text-[12px] text-black bg-white">
        <!-- Cabecera -->
        <header class="flex justify-between gap-6 mb-4">
            <div>
                <p class="text-base font-bold">{{ empresa.razon_social }}</p>
                <p v-if="empresa.nombre_comercial && empresa.nombre_comercial !== empresa.razon_social">{{ empresa.nombre_comercial }}</p>
                <p>{{ empresa.direccion }}</p>
                <p v-if="empresa.telefono || empresa.email">{{ [empresa.telefono, empresa.email].filter(Boolean).join(' · ') }}</p>
            </div>
            <div class="border-2 border-black rounded-lg px-5 py-3 text-center min-w-60">
                <p class="font-bold">RUC {{ empresa.ruc }}</p>
                <p class="font-bold">GUÍA DE REMISIÓN</p>
                <p class="font-bold">ELECTRÓNICA REMITENTE</p>
                <p class="text-base font-bold mt-1">{{ g.numero }}</p>
            </div>
        </header>

        <!-- Datos del traslado -->
        <section class="grid grid-cols-2 gap-x-6 gap-y-1 border border-slate-400 rounded p-3 mb-3">
            <p><b>Fecha de emisión:</b> {{ fecha(g.fecha_emision) }}</p>
            <p><b>Fecha de inicio de traslado:</b> {{ fecha(g.fecha_traslado) }}</p>
            <p><b>Motivo:</b> {{ motivos[g.motivo] ?? g.motivo }}<span v-if="g.motivo_descripcion"> · {{ g.motivo_descripcion }}</span></p>
            <p><b>Peso bruto total:</b> {{ cantidad(g.peso_total) }} {{ g.unidad_peso }}</p>
            <p class="col-span-2"><b>Destinatario:</b> {{ g.destinatario_nombre }} · {{ g.destinatario_tipo_doc === '6' ? 'RUC' : 'Doc.' }} {{ g.destinatario_num_doc }}</p>
            <p class="col-span-2"><b>Punto de partida:</b> {{ g.partida_direccion }} (Ubigeo {{ g.partida_ubigeo }})</p>
            <p class="col-span-2"><b>Punto de llegada:</b> {{ g.llegada_direccion }} (Ubigeo {{ g.llegada_ubigeo }})</p>
            <p v-if="g.comprobante" class="col-span-2"><b>Comprobante relacionado:</b> {{ g.comprobante.numero }}</p>
        </section>

        <!-- Transporte -->
        <section class="border border-slate-400 rounded p-3 mb-3">
            <p class="font-bold mb-1">{{ g.modalidad === '02' ? 'TRANSPORTE PRIVADO' : 'TRANSPORTE PÚBLICO' }}</p>
            <template v-if="g.modalidad === '02'">
                <p><b>Vehículo (placa):</b> {{ g.vehiculo_placa }}</p>
                <p><b>Conductor:</b> {{ g.conductor_nombres }} {{ g.conductor_apellidos }} · Doc. {{ g.conductor_num_doc }} · Licencia {{ g.conductor_licencia }}</p>
            </template>
            <template v-else>
                <p><b>Transportista:</b> {{ g.transportista_nombre }} · RUC {{ g.transportista_ruc }}<span v-if="g.transportista_mtc"> · Registro MTC {{ g.transportista_mtc }}</span></p>
            </template>
        </section>

        <!-- Bienes -->
        <table class="w-full border-collapse mb-3">
            <thead>
                <tr class="bg-slate-100">
                    <th class="border border-slate-400 p-1 w-8">N°</th>
                    <th class="border border-slate-400 p-1 text-left">Código</th>
                    <th class="border border-slate-400 p-1 text-left">Descripción</th>
                    <th class="border border-slate-400 p-1">Unidad</th>
                    <th class="border border-slate-400 p-1 text-right">Cantidad</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(it, i) in g.items" :key="it.id">
                    <td class="border border-slate-400 p-1 text-center">{{ i + 1 }}</td>
                    <td class="border border-slate-400 p-1">{{ it.codigo }}</td>
                    <td class="border border-slate-400 p-1">{{ it.descripcion }}</td>
                    <td class="border border-slate-400 p-1 text-center">{{ it.unidad }}</td>
                    <td class="border border-slate-400 p-1 text-right">{{ cantidad(it.cantidad) }}</td>
                </tr>
            </tbody>
        </table>

        <p v-if="g.observaciones" class="mb-3"><b>Observaciones:</b> {{ g.observaciones }}</p>

        <footer class="flex items-center gap-4 border-t border-slate-400 pt-3">
            <div v-if="qrSvg" class="w-28 h-28 shrink-0" v-html="qrSvg"></div>
            <div>
                <p>Representación impresa de la Guía de Remisión Electrónica Remitente.</p>
                <p>Consulte su validez en www.sunat.gob.pe</p>
                <p v-if="g.sunat_codigo === '0' || g.estado === 'aceptado'" class="mt-1">Aceptada por SUNAT.</p>
            </div>
        </footer>
    </div>
</template>