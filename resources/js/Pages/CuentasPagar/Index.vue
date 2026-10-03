<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import Tag from 'primevue/tag';
import { diasHasta, fecha, soles } from '@/utils/formato';

const props = defineProps({
    compras: Object,
    porProveedor: Array,
    resumen: Object,
    vistas: Object,
    filtros: Object,
    diasPorVencer: Number,
});

const SEVERIDAD = { pendiente: 'info', vencida: 'danger', pagada: 'success', contado: 'secondary', anulada: 'secondary' };
const ESTADO = { pendiente: 'Por pagar', vencida: 'Vencida', pagada: 'Pagada' };

// Cada vista con su color
const COLORES = {
    por_pagar: { activo: 'bg-sky-600 border-sky-600 text-white', inactivo: 'bg-sky-50 border-sky-200 text-sky-800 hover:bg-sky-100' },
    vencidas: { activo: 'bg-red-600 border-red-600 text-white', inactivo: 'bg-red-50 border-red-200 text-red-800 hover:bg-red-100' },
    por_vencer: { activo: 'bg-amber-500 border-amber-500 text-white', inactivo: 'bg-amber-50 border-amber-200 text-amber-800 hover:bg-amber-100' },
    pagadas: { activo: 'bg-emerald-600 border-emerald-600 text-white', inactivo: 'bg-emerald-50 border-emerald-200 text-emerald-800 hover:bg-emerald-100' },
};

const buscar = ref(props.filtros.buscar ?? '');
const vista = ref(props.filtros.vista);
const proveedor = ref(props.filtros.proveedor);

const recargar = (extra = {}) =>
    router.get(
        '/cuentas-por-pagar',
        { vista: vista.value, buscar: buscar.value || undefined, proveedor: proveedor.value || undefined, ...extra },
        { preserveState: true, preserveScroll: true, replace: true },
    );

let espera;
watch(buscar, () => {
    clearTimeout(espera);
    espera = setTimeout(() => recargar(), 350);
});
const elegirVista = (v) => {
    vista.value = v;
    recargar();
};
const elegirProveedor = (id) => {
    proveedor.value = proveedor.value === id ? null : id;
    recargar();
};

// Texto de vencimiento: "Vence en 5 días", "Vencida hace 3 días"
const textoVence = (c) => {
    if (c.estado_pago === 'pagada') return 'Pagada';
    const d = diasHasta(c.fecha_vencimiento);
    if (d === null) return '';
    if (d < 0) return `Vencida hace ${-d} día${d === -1 ? '' : 's'}`;
    if (d === 0) return 'Vence hoy';
    return `Vence en ${d} día${d === 1 ? '' : 's'}`;
};
</script>

<template>
    <Head title="Cuentas por pagar" />
    <AppLayout titulo="Cuentas por pagar">
        <!-- Indicadores -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <button type="button" class="text-left rounded-xl border border-sky-200 bg-sky-50 p-4 hover:shadow" @click="elegirVista('por_pagar')">
                <p class="text-xs text-sky-700 font-medium">Total por pagar</p>
                <p class="text-2xl font-semibold text-sky-900">{{ soles(resumen.por_pagar) }}</p>
            </button>
            <button type="button" class="text-left rounded-xl border border-red-200 bg-red-50 p-4 hover:shadow" @click="elegirVista('vencidas')">
                <p class="text-xs text-red-700 font-medium">Vencido</p>
                <p class="text-2xl font-semibold text-red-900">{{ soles(resumen.vencido) }}</p>
            </button>
            <button type="button" class="text-left rounded-xl border border-amber-200 bg-amber-50 p-4 hover:shadow" @click="elegirVista('por_vencer')">
                <p class="text-xs text-amber-700 font-medium">Vence en {{ diasPorVencer }} días</p>
                <p class="text-2xl font-semibold text-amber-900">{{ soles(resumen.por_vencer) }}</p>
            </button>
            <button type="button" class="text-left rounded-xl border border-emerald-200 bg-emerald-50 p-4 hover:shadow" @click="elegirVista('pagadas')">
                <p class="text-xs text-emerald-700 font-medium">Pagado este mes</p>
                <p class="text-2xl font-semibold text-emerald-900">{{ soles(resumen.pagado_mes) }}</p>
            </button>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-4 gap-6">
            <!-- Documentos -->
            <section class="xl:col-span-3 bg-white rounded-xl border border-slate-200 self-start">
                <div class="p-4 flex flex-wrap items-center gap-3 border-b border-slate-100">
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="(texto, clave) in vistas"
                            :key="clave"
                            type="button"
                            class="px-3 py-1.5 rounded-full border text-sm font-medium transition-colors"
                            :class="vista === clave ? COLORES[clave].activo : COLORES[clave].inactivo"
                            @click="elegirVista(clave)"
                        >
                            {{ texto }}
                        </button>
                    </div>
                    <IconField class="w-full sm:w-72 sm:ml-auto">
                        <InputIcon class="pi pi-search" />
                        <InputText v-model="buscar" placeholder="Proveedor, RUC o N° de factura" fluid />
                    </IconField>
                </div>

                <DataTable
                    :value="compras.data"
                    lazy
                    paginator
                    :rows="compras.per_page"
                    :totalRecords="compras.total"
                    :first="(compras.current_page - 1) * compras.per_page"
                    @page="(e) => recargar({ page: e.page + 1 })"
                >
                    <template #empty>No hay documentos en esta vista.</template>
                    <Column header="Documento">
                        <template #body="{ data }">
                            <Link :href="`/cuentas-por-pagar/${data.id}`" class="font-medium text-emerald-700 hover:underline">{{ data.documento }}</Link>
                            <p class="text-xs text-slate-500">Emitida {{ fecha(data.fecha_emision) }}</p>
                        </template>
                    </Column>
                    <Column header="Proveedor">
                        <template #body="{ data }">
                            <p>{{ data.proveedor?.razon_social }}</p>
                            <p class="text-xs text-slate-500">RUC {{ data.proveedor?.ruc }}</p>
                        </template>
                    </Column>
                    <Column header="Vencimiento">
                        <template #body="{ data }">
                            <p>{{ fecha(data.fecha_vencimiento) }}</p>
                            <p class="text-xs" :class="data.estado_pago === 'vencida' ? 'text-red-600 font-medium' : 'text-slate-500'">{{ textoVence(data) }}</p>
                        </template>
                    </Column>
                    <Column header="Total" class="text-right">
                        <template #body="{ data }">{{ soles(data.total) }}</template>
                    </Column>
                    <Column header="Pagado" class="text-right">
                        <template #body="{ data }"><span class="text-emerald-700">{{ soles(data.pagado ?? 0) }}</span></template>
                    </Column>
                    <Column header="Saldo" class="text-right">
                        <template #body="{ data }"><span class="font-semibold">{{ soles(data.saldo) }}</span></template>
                    </Column>
                    <Column header="Estado">
                        <template #body="{ data }"><Tag :value="ESTADO[data.estado_pago] ?? data.estado_pago" :severity="SEVERIDAD[data.estado_pago]" /></template>
                    </Column>
                    <Column class="text-right">
                        <template #body="{ data }">
                            <Link :href="`/cuentas-por-pagar/${data.id}`">
                                <Button
                                    :icon="Number(data.saldo) > 0 ? 'pi pi-wallet' : 'pi pi-eye'"
                                    :severity="Number(data.saldo) > 0 ? 'success' : 'info'"
                                    size="small"
                                    v-tooltip.left="Number(data.saldo) > 0 ? 'Ver y pagar' : 'Ver pagos'"
                                />
                            </Link>
                        </template>
                    </Column>
                </DataTable>
            </section>

            <!-- Deuda por proveedor -->
            <aside class="bg-white rounded-xl border border-slate-200 self-start">
                <div class="p-4 border-b border-slate-100 flex items-center justify-between">
                    <h2 class="font-semibold">Deuda por proveedor</h2>
                    <Button v-if="proveedor" label="Ver todos" text size="small" severity="secondary" @click="elegirProveedor(proveedor)" />
                </div>
                <p v-if="!porProveedor.length" class="p-4 text-sm text-slate-400">No se le debe nada a ningún proveedor.</p>
                <button
                    v-for="p in porProveedor"
                    :key="p.proveedor_id"
                    type="button"
                    class="w-full text-left px-4 py-3 border-b border-slate-100 last:border-0 hover:bg-slate-50 transition-colors"
                    :class="proveedor === p.proveedor_id ? 'bg-sky-50' : ''"
                    @click="elegirProveedor(p.proveedor_id)"
                >
                    <p class="text-sm font-medium truncate">{{ p.proveedor }}</p>
                    <div class="flex justify-between text-xs mt-0.5">
                        <span class="text-slate-500">{{ p.documentos }} documento(s)</span>
                        <span class="font-semibold">{{ soles(p.deuda) }}</span>
                    </div>
                    <p v-if="p.vencido > 0" class="text-xs text-red-600 mt-0.5">Vencido: {{ soles(p.vencido) }}</p>
                </button>
            </aside>
        </div>
    </AppLayout>
</template>