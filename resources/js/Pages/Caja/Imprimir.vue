<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { fecha } from '@/utils/formato';

const props = defineProps({
    caja: Object,
    resumen: Object,
    movimientos: Array,
    pagos: Array,
    autoImprimir: Boolean,
});

const empresa = usePage().props.empresa;
const hora = (v) => String(v ?? '').substring(11, 16);
const dinero = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const monto = (v) => dinero.format(Number(v ?? 0));
const diferencia = computed(() => Number(props.caja.diferencia ?? 0));
const efectivo = computed(() => props.resumen.medios.find((m) => m.medio === 'efectivo'));
const conteo = computed(() => Object.entries(props.caja.conteo ?? {}).sort((a, b) => Number(b[0]) - Number(a[0])));

// Mismo ancho de ticket que eligió el usuario para los comprobantes (58 u 80 mm)
const es58 = (() => {
    try {
        return localStorage.getItem('anchoTicket') === '58';
    } catch {
        return false;
    }
})();
const anchoUtil = es58 ? 48 : 72;

const estiloPagina = document.createElement('style');
onMounted(() => {
    estiloPagina.textContent = '@page { margin: 0; } html, body { background: #fff; margin: 0; }';
    document.head.appendChild(estiloPagina);
    if (props.autoImprimir) setTimeout(() => window.print(), 300);
});
onBeforeUnmount(() => estiloPagina.remove());
</script>

<template>
    <Head title="Reporte de caja" />
    <div class="bg-slate-200 print:bg-white min-h-screen print:min-h-0 py-6 print:py-0">
        <article
            class="ticket bg-white mx-auto shadow print:shadow-none leading-tight text-black font-mono font-bold"
            :class="es58 ? 'text-[9px]' : 'text-[11px]'"
            :style="{ width: anchoUtil - 3 + 'mm' }"
        >
            <div class="text-center">
                <p class="text-[1.2em]">{{ empresa?.razon_social }}</p>
                <p class="mt-1 text-[1.2em]">CIERRE DE CAJA</p>
            </div>
            <div class="raya"></div>
            <p>Cajero: {{ caja.usuario.name }}</p>
            <p>Apertura: {{ fecha(caja.abierta_at) }} {{ hora(caja.abierta_at) }}</p>
            <p>Cierre: {{ fecha(caja.cerrada_at) }} {{ hora(caja.cerrada_at) }}</p>
            <p>Ventas: {{ resumen.cantidad_ventas }} · Cobranzas: {{ resumen.cantidad_cobranzas ?? 0 }}</p>

            <div class="raya"></div>
            <p class="text-center">POR MEDIO DE PAGO (NETO)</p>
            <p v-for="m in resumen.medios.filter((x) => x.ventas || x.cobranzas || x.ingresos || x.egresos)" :key="m.medio" class="flex justify-between">
                <span>{{ m.nombre }}</span><span>{{ monto(m.neto) }}</span>
            </p>
            <p class="flex justify-between"><span>Total ventas</span><span>{{ monto(resumen.total_ventas) }}</span></p>
            <p class="flex justify-between"><span>Total cobranzas</span><span>{{ monto(resumen.total_cobranzas ?? 0) }}</span></p>

            <div class="raya"></div>
            <p class="text-center">ARQUEO DE EFECTIVO</p>
            <p class="flex justify-between"><span>Monto inicial</span><span>{{ monto(resumen.monto_inicial) }}</span></p>
            <p class="flex justify-between"><span>+ Ventas efectivo</span><span>{{ monto(efectivo.ventas) }}</span></p>
            <p class="flex justify-between"><span>+ Cobranzas efectivo</span><span>{{ monto(efectivo.cobranzas ?? 0) }}</span></p>
            <p class="flex justify-between"><span>+ Ingresos</span><span>{{ monto(efectivo.ingresos) }}</span></p>
            <p class="flex justify-between"><span>- Egresos</span><span>{{ monto(efectivo.egresos) }}</span></p>
            <p class="flex justify-between"><span>Esperado</span><span>{{ monto(resumen.efectivo_esperado) }}</span></p>
            <p class="flex justify-between"><span>Contado</span><span>{{ monto(caja.efectivo_contado) }}</span></p>
            <p class="flex justify-between text-[1.2em] mt-1">
                <span>{{ Math.abs(diferencia) < 0.01 ? 'CUADRE' : diferencia > 0 ? 'SOBRANTE' : 'FALTANTE' }}</span>
                <span>{{ monto(Math.abs(diferencia)) }}</span>
            </p>

            <template v-if="conteo.length">
                <div class="raya"></div>
                <p class="text-center">CONTEO</p>
                <p v-for="[d, cant] in conteo" :key="d" class="flex justify-between">
                    <span>{{ cant }} x S/ {{ Number(d) >= 1 ? d : Number(d).toFixed(2) }}</span><span>{{ monto(Number(d) * cant) }}</span>
                </p>
            </template>

            <template v-if="movimientos.length">
                <div class="raya"></div>
                <p class="text-center">INGRESOS / EGRESOS</p>
                <div v-for="m in movimientos" :key="m.id">
                    <p class="flex justify-between">
                        <span>{{ m.tipo === 'ingreso' ? '+' : '-' }} {{ m.medio_nombre }}</span><span>{{ monto(m.monto) }}</span>
                    </p>
                    <p class="text-[0.9em]">{{ m.concepto }}</p>
                </div>
            </template>

            <p v-if="caja.observaciones" class="mt-2">Obs.: {{ caja.observaciones }}</p>

            <div class="raya"></div>
            <p class="mt-6 text-center">______________________</p>
            <p class="text-center">Firma del cajero</p>
            <p class="mt-6 text-center">______________________</p>
            <p class="text-center">Firma del supervisor</p>
        </article>
    </div>
</template>

<style scoped>
.ticket {
    color: #000;
    box-sizing: content-box;
    padding: 4mm 5mm;
    overflow-wrap: anywhere;
}
.raya {
    border-top: 1px dashed #000;
    margin: 6px 0;
}
@media print {
    .ticket {
        margin: 0;
        padding: 0 1.5mm 4mm 1.5mm;
    }
}
</style>