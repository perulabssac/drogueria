<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import { soles, fecha } from '@/utils/formato';
import { estadoSunat } from '@/utils/sunat';

const props = defineProps({
    caja: Object,
    resumen: Object,
    movimientos: Array,
    pagos: Array,
});

const esAdmin = usePage().props.auth.user.rol === 'admin';
const hora = (v) => String(v ?? '').substring(11, 16);
const cerrada = computed(() => props.caja.estado === 'cerrada');
const diferencia = computed(() => Number(props.caja.diferencia ?? 0));
const conteo = computed(() => Object.entries(props.caja.conteo ?? {}).sort((a, b) => Number(b[0]) - Number(a[0])));

// Imprime el reporte sin salir de la pantalla (mismo método que los comprobantes)
const imprimiendo = ref(false);
const imprimir = () => {
    document.getElementById('marco-impresion')?.remove();
    imprimiendo.value = true;
    const marco = document.createElement('iframe');
    marco.id = 'marco-impresion';
    marco.style.cssText = 'position:fixed;left:-10000px;top:0;width:800px;height:600px;border:0';
    marco.src = `/cajas/${props.caja.id}/imprimir?auto=1`;
    marco.onload = () => setTimeout(() => (imprimiendo.value = false), 1500);
    document.body.appendChild(marco);
};
</script>

<template>
    <Head :title="`Caja de ${caja.usuario.name}`" />
    <AppLayout :titulo="`Caja de ${caja.usuario.name}`">
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <Link :href="esAdmin ? '/cajas' : '/caja'"><Button label="Volver" icon="pi pi-arrow-left" text /></Link>
            <Tag :value="cerrada ? 'Cerrada' : 'Abierta'" :severity="cerrada ? 'secondary' : 'success'" :icon="cerrada ? 'pi pi-lock' : 'pi pi-lock-open'" />
            <div class="ml-auto flex gap-2">
                <Button v-if="cerrada" label="Imprimir reporte" icon="pi pi-print" severity="info" :loading="imprimiendo" @click="imprimir" />
                <Link v-if="!esAdmin" href="/caja"><Button label="Abrir nueva caja" icon="pi pi-lock-open" /></Link>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <section class="xl:col-span-2 space-y-6">
                <!-- Datos -->
                <div class="bg-white rounded-xl border border-slate-200 p-5 grid sm:grid-cols-4 gap-4 text-sm">
                    <div>
                        <p class="text-xs text-slate-500">Cajero</p>
                        <p class="font-medium">{{ caja.usuario.name }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Apertura</p>
                        <p class="font-medium">{{ fecha(caja.abierta_at) }} {{ hora(caja.abierta_at) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Cierre</p>
                        <p class="font-medium">{{ cerrada ? fecha(caja.cerrada_at) + ' ' + hora(caja.cerrada_at) : '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Ventas · cobranzas</p>
                        <p class="font-medium">{{ resumen.cantidad_ventas }} · {{ resumen.cantidad_cobranzas ?? 0 }}</p>
                    </div>
                    <p v-if="caja.observaciones" class="sm:col-span-4 text-slate-600"><b>Observaciones:</b> {{ caja.observaciones }}</p>
                </div>

                <!-- Por medio de pago -->
                <div class="bg-white rounded-xl border border-slate-200">
                    <h2 class="font-semibold p-4 border-b border-slate-100">Por medio de pago</h2>
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                            <tr>
                                <th class="text-left p-3">Medio</th>
                                <th class="text-right p-3">Ventas</th>
                                <th class="text-right p-3">Cobranzas</th>
                                <th class="text-right p-3">Ingresos</th>
                                <th class="text-right p-3">Egresos</th>
                                <th class="text-right p-3">Neto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in resumen.medios" :key="m.medio" class="border-t border-slate-100">
                                <td class="p-3">{{ m.nombre }}</td>
                                <td class="p-3 text-right">{{ soles(m.ventas) }}</td>
                                <td class="p-3 text-right">{{ soles(m.cobranzas ?? 0) }}</td>
                                <td class="p-3 text-right">{{ soles(m.ingresos) }}</td>
                                <td class="p-3 text-right">{{ soles(m.egresos) }}</td>
                                <td class="p-3 text-right font-medium">{{ soles(m.neto) }}</td>
                            </tr>
                            <tr class="border-t-2 border-slate-300 font-semibold">
                                <td class="p-3">Total</td>
                                <td class="p-3 text-right">{{ soles(resumen.total_ventas) }}</td>
                                <td class="p-3 text-right">{{ soles(resumen.total_cobranzas ?? 0) }}</td>
                                <td class="p-3 text-right">{{ soles(resumen.total_ingresos) }}</td>
                                <td class="p-3 text-right">{{ soles(resumen.total_egresos) }}</td>
                                <td class="p-3 text-right">{{ soles(resumen.total_ventas + (resumen.total_cobranzas ?? 0) + resumen.total_ingresos - resumen.total_egresos) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Ingresos y egresos -->
                <div class="bg-white rounded-xl border border-slate-200">
                    <h2 class="font-semibold p-4 border-b border-slate-100">Ingresos y egresos</h2>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-if="!movimientos.length">
                                <td class="p-6 text-center text-slate-400">Sin ingresos ni egresos.</td>
                            </tr>
                            <tr v-for="m in movimientos" :key="m.id" class="border-t border-slate-100">
                                <td class="p-3 w-16 text-slate-500">{{ hora(m.created_at) }}</td>
                                <td class="p-3">
                                    <Tag :value="m.tipo === 'ingreso' ? 'Ingreso' : 'Egreso'" :severity="m.tipo === 'ingreso' ? 'success' : 'danger'" class="mr-2" />
                                    {{ m.concepto }}
                                </td>
                                <td class="p-3 text-slate-500">{{ m.medio_nombre }}</td>
                                <td class="p-3 text-right font-medium" :class="m.tipo === 'ingreso' ? 'text-emerald-700' : 'text-red-600'">
                                    {{ m.tipo === 'ingreso' ? '+' : '−' }} {{ soles(m.monto) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Ventas y cobranzas -->
                <div class="bg-white rounded-xl border border-slate-200">
                    <h2 class="font-semibold p-4 border-b border-slate-100">Ventas y cobranzas de esta caja</h2>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-if="!pagos.length">
                                <td class="p-6 text-center text-slate-400">Sin ventas ni cobranzas.</td>
                            </tr>
                            <tr v-for="p in pagos" :key="p.id" class="border-t border-slate-100" :class="{ 'opacity-50 line-through': p.comprobante.estado === 'rechazado' }">
                                <td class="p-3 w-16 text-slate-500">{{ hora(p.fecha) }}</td>
                                <td class="p-3">
                                    <Link :href="`/comprobantes/${p.comprobante.id}`" class="font-medium text-emerald-700 hover:underline">{{ p.comprobante.numero }}</Link>
                                    <span class="text-slate-500"> · {{ p.comprobante.cliente?.razon_social }}</span>
                                </td>
                                <td class="p-3">
                                    <Tag v-if="p.tipo === 'cobranza'" value="Cobranza" severity="info" class="mr-1" />
                                    <Tag
                                        v-if="['rechazado', 'error'].includes(p.comprobante.estado)"
                                        :value="estadoSunat(p.comprobante.estado).texto"
                                        :severity="estadoSunat(p.comprobante.estado).severidad"
                                    />
                                </td>
                                <td class="p-3 text-slate-500">{{ p.medio_nombre }}<span v-if="p.referencia"> · {{ p.referencia }}</span></td>
                                <td class="p-3 text-right font-medium">{{ soles(p.monto) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Arqueo -->
            <section class="space-y-6">
                <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-2 text-sm">
                    <h2 class="font-semibold mb-2">Arqueo de efectivo</h2>
                    <div class="flex justify-between"><span>Monto inicial</span><span>{{ soles(resumen.monto_inicial) }}</span></div>
                    <div class="flex justify-between"><span>+ Ventas en efectivo</span><span>{{ soles(resumen.medios.find((m) => m.medio === 'efectivo').ventas) }}</span></div>
                    <div class="flex justify-between"><span>+ Cobranzas en efectivo</span><span>{{ soles(resumen.medios.find((m) => m.medio === 'efectivo').cobranzas ?? 0) }}</span></div>
                    <div class="flex justify-between"><span>+ Ingresos en efectivo</span><span>{{ soles(resumen.medios.find((m) => m.medio === 'efectivo').ingresos) }}</span></div>
                    <div class="flex justify-between"><span>− Egresos en efectivo</span><span>{{ soles(resumen.medios.find((m) => m.medio === 'efectivo').egresos) }}</span></div>
                    <div class="flex justify-between font-semibold border-t pt-2"><span>Efectivo esperado</span><span>{{ soles(resumen.efectivo_esperado) }}</span></div>
                    <template v-if="cerrada">
                        <div class="flex justify-between font-semibold"><span>Efectivo contado</span><span>{{ soles(caja.efectivo_contado) }}</span></div>
                        <div
                            class="flex justify-between text-lg font-bold rounded-lg px-3 py-2 mt-2"
                            :class="Math.abs(diferencia) < 0.01 ? 'bg-emerald-50 text-emerald-700' : diferencia > 0 ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-700'"
                        >
                            <span>{{ Math.abs(diferencia) < 0.01 ? 'Cuadre exacto' : diferencia > 0 ? 'Sobrante' : 'Faltante' }}</span>
                            <span>{{ soles(Math.abs(diferencia)) }}</span>
                        </div>
                    </template>
                    <p v-else class="text-xs text-slate-500 pt-2">La caja sigue abierta: el arqueo se calcula al cerrarla.</p>
                </div>

                <div v-if="conteo.length" class="bg-white rounded-xl border border-slate-200 p-5 text-sm">
                    <h2 class="font-semibold mb-2">Conteo de billetes y monedas</h2>
                    <div v-for="[d, cant] in conteo" :key="d" class="flex justify-between py-1 border-b border-slate-100 last:border-0">
                        <span>{{ cant }} × S/ {{ Number(d) >= 1 ? d : Number(d).toFixed(2) }}</span>
                        <span>{{ soles(Number(d) * cant) }}</span>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>