<script setup>
import { onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { cantidad, fecha, precio, soles } from '@/utils/formato';

const props = defineProps({
    cotizacion: Object,
    empresa: Object,
});

const c = props.cotizacion;
const imprimir = () => window.print();

// La cotización se imprime en A4 (o "Guardar como PDF" para enviarla por WhatsApp o correo)
let estiloPagina;

onMounted(() => {
    estiloPagina = document.createElement('style');
    estiloPagina.textContent = '@page { size: A4; margin: 10mm; } html, body { background: #fff; }';
    document.head.appendChild(estiloPagina);
    setTimeout(imprimir, 400);
});

onUnmounted(() => estiloPagina?.remove());
</script>

<template>
    <Head :title="`Cotización ${c.numero}`" />

    <div class="no-print bg-slate-800 text-white px-4 py-2 flex items-center gap-3 text-sm">
        <span>Cotización {{ c.numero }}. Para enviarla al cliente elige <b>"Guardar como PDF"</b> en Destino.</span>
        <span v-if="c.estado_actual === 'vencida'" class="bg-amber-500 text-black rounded px-2">Vencida</span>
        <span v-if="c.estado_actual === 'anulada'" class="bg-red-500 rounded px-2">Anulada</span>
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
                <p class="font-bold text-base">COTIZACIÓN</p>
                <p class="text-base font-bold mt-1">{{ c.numero }}</p>
            </div>
        </header>

        <!-- Cliente y condiciones -->
        <section class="grid grid-cols-2 gap-x-6 gap-y-1 border border-slate-400 rounded p-3 mb-3">
            <p class="col-span-2"><b>Cliente:</b> {{ c.cliente.razon_social }}</p>
            <p><b>{{ c.cliente.tipo_documento === '6' ? 'RUC' : 'Documento' }}:</b> {{ c.cliente.numero_documento }}</p>
            <p><b>Fecha:</b> {{ fecha(c.fecha) }}</p>
            <p v-if="c.cliente.direccion" class="col-span-2"><b>Dirección:</b> {{ c.cliente.direccion }}</p>
            <p><b>Válida hasta:</b> {{ fecha(c.fecha_vencimiento) }} ({{ c.validez_dias }} días)</p>
            <p><b>Forma de pago:</b> <span class="capitalize">{{ c.forma_pago }}</span></p>
            <p v-if="c.vendedor" class="col-span-2"><b>Atendido por:</b> {{ c.vendedor.name }}</p>
        </section>

        <!-- Productos -->
        <table class="w-full border-collapse mb-3">
            <thead>
                <tr class="bg-slate-100">
                    <th class="border border-slate-400 p-1 w-8">N°</th>
                    <th class="border border-slate-400 p-1 text-left">Descripción</th>
                    <th class="border border-slate-400 p-1">Unidad</th>
                    <th class="border border-slate-400 p-1 text-right">Cant.</th>
                    <th class="border border-slate-400 p-1 text-right">P. unit.</th>
                    <th class="border border-slate-400 p-1 text-right">Importe</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(it, i) in c.items" :key="it.id">
                    <td class="border border-slate-400 p-1 text-center">{{ i + 1 }}</td>
                    <td class="border border-slate-400 p-1">{{ it.descripcion }}</td>
                    <td class="border border-slate-400 p-1 text-center">{{ it.unidad }}</td>
                    <td class="border border-slate-400 p-1 text-right">{{ cantidad(it.cantidad) }}</td>
                    <td class="border border-slate-400 p-1 text-right">{{ it.bonificacion ? '—' : precio(it.precio_unitario) }}</td>
                    <td class="border border-slate-400 p-1 text-right">{{ it.bonificacion ? 'GRATIS' : soles(it.importe) }}</td>
                </tr>
            </tbody>
        </table>

        <!-- Totales -->
        <div class="flex justify-end mb-3">
            <table class="min-w-64">
                <tbody>
                    <tr><td class="p-1">Op. gravadas</td><td class="p-1 text-right">{{ soles(c.op_gravadas) }}</td></tr>
                    <tr v-if="Number(c.op_exoneradas) > 0"><td class="p-1">Op. exoneradas</td><td class="p-1 text-right">{{ soles(c.op_exoneradas) }}</td></tr>
                    <tr><td class="p-1">IGV (18%)</td><td class="p-1 text-right">{{ soles(c.igv) }}</td></tr>
                    <tr class="border-t-2 border-black font-bold text-sm"><td class="p-1">TOTAL</td><td class="p-1 text-right">{{ soles(c.total) }}</td></tr>
                </tbody>
            </table>
        </div>

        <section v-if="c.condiciones" class="border border-slate-400 rounded p-3 mb-3">
            <p class="font-bold mb-1">Condiciones</p>
            <p class="whitespace-pre-line">{{ c.condiciones }}</p>
        </section>
        <p v-if="c.observaciones" class="mb-3 whitespace-pre-line"><b>Observaciones:</b> {{ c.observaciones }}</p>

        <footer class="border-t border-slate-400 pt-3 text-center text-[11px] text-slate-600">
            Documento sin valor tributario. No es comprobante de pago. Precios válidos hasta el {{ fecha(c.fecha_vencimiento) }}.
        </footer>
    </div>
</template>