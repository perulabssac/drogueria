<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import DatePicker from 'primevue/datepicker';
import Tag from 'primevue/tag';
import { soles, cantidad } from '@/utils/formato';
import { aFechaISO } from '@/utils/http';

const props = defineProps({
    filtros: Object,
    resumen: Object,
    productos: Array,
    vendedores: Array,
    alertas: Object,
    margenBajo: Number,
});

// ---------- Rango de fechas ----------
const aFecha = (iso) => new Date(iso + 'T00:00:00');
const desde = ref(aFecha(props.filtros.desde));
const hasta = ref(aFecha(props.filtros.hasta));

const recargar = () => {
    if (!desde.value || !hasta.value) return;
    router.get('/rentabilidad', { desde: aFechaISO(desde.value), hasta: aFechaISO(hasta.value) }, { preserveScroll: true, replace: true });
};
watch([desde, hasta], recargar);

// Atajos: este mes, mes anterior y este año
const hoy = new Date();
const atajo = (d, h) => {
    desde.value = d;
    hasta.value = h;
};
const esteMes = () => atajo(new Date(hoy.getFullYear(), hoy.getMonth(), 1), new Date(hoy));
const mesAnterior = () => atajo(new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1), new Date(hoy.getFullYear(), hoy.getMonth(), 0));
const esteAnio = () => atajo(new Date(hoy.getFullYear(), 0, 1), new Date(hoy));

const urlExcel = (tipo) => `/rentabilidad/excel?tipo=${tipo}&desde=${props.filtros.desde}&hasta=${props.filtros.hasta}`;

// ---------- Formato ----------
// Verde: buen margen · ámbar: bajo · rojo: pérdida
const colorMargen = (m) =>
    m === null || m === undefined ? 'text-slate-500' : m < 0 ? 'text-red-600' : m < props.margenBajo ? 'text-amber-600' : 'text-emerald-700';
const pct = (v) => (v === null || v === undefined ? '—' : `${Number(v).toFixed(1)} %`);

// ---------- Tabla por producto ----------
const buscar = ref('');
const productosFiltrados = computed(() => {
    const texto = buscar.value.trim().toLowerCase();
    if (!texto) return props.productos;
    return props.productos.filter((p) => p.nombre.toLowerCase().includes(texto) || p.codigo.toLowerCase().includes(texto));
});

const totalAlertas = computed(() => props.alertas.perdida.length + props.alertas.bajo.length + props.alertas.sin_margen.length);
</script>

<template>
    <Head title="Rentabilidad" />
    <AppLayout titulo="Rentabilidad">
        <!-- Filtros -->
        <section class="bg-white rounded-xl border border-slate-200 p-4 mb-6 flex flex-wrap items-center gap-3">
            <DatePicker v-model="desde" placeholder="Desde" dateFormat="dd/mm/yy" showIcon class="w-full sm:w-44" />
            <DatePicker v-model="hasta" placeholder="Hasta" dateFormat="dd/mm/yy" showIcon class="w-full sm:w-44" />
            <Button label="Este mes" size="small" severity="info" outlined @click="esteMes" />
            <Button label="Mes anterior" size="small" severity="secondary" outlined @click="mesAnterior" />
            <Button label="Este año" size="small" severity="contrast" outlined @click="esteAnio" />
            <div class="flex flex-wrap gap-2 sm:ml-auto">
                <a :href="urlExcel('productos')"><Button label="Excel por producto" icon="pi pi-file-excel" severity="success" /></a>
                <a :href="urlExcel('vendedores')"><Button label="Excel por vendedor" icon="pi pi-file-excel" severity="help" /></a>
            </div>
        </section>

        <!-- Indicadores -->
        <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-xs text-slate-500">Ventas (sin IGV)</p>
                <p class="text-2xl font-semibold">{{ soles(resumen.venta) }}</p>
                <p class="text-xs text-slate-500">{{ resumen.documentos }} comprobante(s)</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-xs text-slate-500">Costo de lo vendido</p>
                <p class="text-2xl font-semibold text-slate-700">{{ soles(resumen.costo) }}</p>
                <p class="text-xs text-slate-500">Según el costo de cada lote</p>
            </div>
            <div class="rounded-xl border p-4" :class="resumen.ganancia < 0 ? 'bg-red-50 border-red-200' : 'bg-emerald-50 border-emerald-200'">
                <p class="text-xs" :class="resumen.ganancia < 0 ? 'text-red-700' : 'text-emerald-700'">Ganancia</p>
                <p class="text-2xl font-semibold" :class="resumen.ganancia < 0 ? 'text-red-700' : 'text-emerald-800'">{{ soles(resumen.ganancia) }}</p>
                <p class="text-xs text-slate-500">Venta − costo</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-xs text-slate-500">Margen promedio</p>
                <p class="text-2xl font-semibold" :class="colorMargen(resumen.margen_costo)">{{ pct(resumen.margen_costo) }}</p>
                <p class="text-xs text-slate-500">sobre el costo · {{ pct(resumen.margen_venta) }} sobre la venta</p>
            </div>
        </section>

        <p v-if="resumen.sin_costo > 0" class="mb-6 p-3 rounded-lg bg-amber-50 text-amber-800 text-sm">
            <i class="pi pi-exclamation-triangle mr-1"></i>{{ resumen.sin_costo }} línea(s) vendida(s) no tienen lote, así que se tomaron con costo 0: la ganancia puede estar inflada.
        </p>

        <!-- Alertas -->
        <section v-if="totalAlertas" class="grid md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-red-200 p-4 text-sm">
                <h2 class="font-semibold text-red-700 mb-2"><i class="pi pi-arrow-down mr-1"></i>Vendidos a pérdida ({{ alertas.perdida.length }})</h2>
                <p v-if="!alertas.perdida.length" class="text-slate-400">Ninguno. 👍</p>
                <div v-for="p in alertas.perdida.slice(0, 8)" :key="p.id" class="flex justify-between gap-2 border-t border-slate-100 py-1">
                    <Link :href="`/productos/${p.id}/editar`" class="truncate hover:underline">{{ p.nombre }}</Link>
                    <span class="text-red-600 font-medium whitespace-nowrap">{{ soles(p.ganancia) }}</span>
                </div>
            </div>
            <div class="bg-white rounded-xl border border-amber-200 p-4 text-sm">
                <h2 class="font-semibold text-amber-700 mb-2"><i class="pi pi-exclamation-circle mr-1"></i>Margen bajo, menos de {{ margenBajo }} % ({{ alertas.bajo.length }})</h2>
                <p v-if="!alertas.bajo.length" class="text-slate-400">Ninguno. 👍</p>
                <div v-for="p in alertas.bajo.slice(0, 8)" :key="p.id" class="flex justify-between gap-2 border-t border-slate-100 py-1">
                    <Link :href="`/productos/${p.id}/editar`" class="truncate hover:underline">{{ p.nombre }}</Link>
                    <span class="text-amber-600 font-medium whitespace-nowrap">{{ pct(p.margen_costo) }}</span>
                </div>
            </div>
            <div class="bg-white rounded-xl border border-sky-200 p-4 text-sm">
                <h2 class="font-semibold text-sky-700 mb-2"><i class="pi pi-percentage mr-1"></i>Vendidos sin margen asignado ({{ alertas.sin_margen.length }})</h2>
                <p v-if="!alertas.sin_margen.length" class="text-slate-400">Ninguno. 👍</p>
                <div v-for="p in alertas.sin_margen.slice(0, 8)" :key="p.id" class="flex justify-between gap-2 border-t border-slate-100 py-1">
                    <Link :href="`/productos/${p.id}/editar`" class="truncate text-sky-700 hover:underline">{{ p.nombre }}</Link>
                    <span class="text-slate-500 whitespace-nowrap">{{ pct(p.margen_costo) }}</span>
                </div>
            </div>
        </section>

        <!-- Por producto -->
        <section class="bg-white rounded-xl border border-slate-200 mb-6">
            <div class="p-4 flex flex-wrap items-center gap-3 border-b border-slate-100">
                <h2 class="font-semibold">Ganancia por producto</h2>
                <IconField class="w-full sm:w-72 sm:ml-auto">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="buscar" placeholder="Buscar producto o código" fluid />
                </IconField>
            </div>
            <DataTable :value="productosFiltrados" size="small" paginator :rows="20" sortField="ganancia" :sortOrder="-1" removableSort>
                <template #empty>No hay ventas en este rango de fechas.</template>
                <Column field="nombre" header="Producto" sortable>
                    <template #body="{ data }">
                        <span class="text-slate-500">{{ data.codigo }}</span> · {{ data.nombre }}
                        <Tag v-if="data.margen_asignado === null" value="Sin margen" severity="info" class="ml-1" />
                    </template>
                </Column>
                <Column field="cantidad" header="Cant." sortable class="text-right">
                    <template #body="{ data }">{{ cantidad(data.cantidad) }} {{ data.unidad_venta }}</template>
                </Column>
                <Column field="venta" header="Venta" sortable class="text-right">
                    <template #body="{ data }">{{ soles(data.venta) }}</template>
                </Column>
                <Column field="costo" header="Costo" sortable class="text-right">
                    <template #body="{ data }">{{ soles(data.costo) }}</template>
                </Column>
                <Column field="ganancia" header="Ganancia" sortable class="text-right">
                    <template #body="{ data }"><span class="font-semibold" :class="colorMargen(data.margen_costo)">{{ soles(data.ganancia) }}</span></template>
                </Column>
                <Column field="margen_costo" header="Margen s/costo" sortable class="text-right">
                    <template #body="{ data }"><span class="font-semibold" :class="colorMargen(data.margen_costo)">{{ pct(data.margen_costo) }}</span></template>
                </Column>
                <Column field="margen_venta" header="Margen s/venta" sortable class="text-right">
                    <template #body="{ data }"><span :class="colorMargen(data.margen_venta)">{{ pct(data.margen_venta) }}</span></template>
                </Column>
            </DataTable>
        </section>

        <!-- Por vendedor -->
        <section class="bg-white rounded-xl border border-slate-200">
            <h2 class="font-semibold p-4 border-b border-slate-100">Ganancia por vendedor</h2>
            <DataTable :value="vendedores" size="small">
                <template #empty>No hay ventas en este rango de fechas.</template>
                <Column field="vendedor" header="Vendedor" />
                <Column field="documentos" header="Comprobantes" class="text-right" />
                <Column header="Venta" class="text-right">
                    <template #body="{ data }">{{ soles(data.venta) }}</template>
                </Column>
                <Column header="Costo" class="text-right">
                    <template #body="{ data }">{{ soles(data.costo) }}</template>
                </Column>
                <Column header="Ganancia" class="text-right">
                    <template #body="{ data }"><span class="font-semibold" :class="colorMargen(data.margen_costo)">{{ soles(data.ganancia) }}</span></template>
                </Column>
                <Column header="Margen s/costo" class="text-right">
                    <template #body="{ data }"><span class="font-semibold" :class="colorMargen(data.margen_costo)">{{ pct(data.margen_costo) }}</span></template>
                </Column>
                <Column header="Margen s/venta" class="text-right">
                    <template #body="{ data }"><span :class="colorMargen(data.margen_venta)">{{ pct(data.margen_venta) }}</span></template>
                </Column>
            </DataTable>
        </section>
    </AppLayout>
</template>