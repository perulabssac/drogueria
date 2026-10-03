<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import Tag from 'primevue/tag';
import { fecha, soles } from '@/utils/formato';

const props = defineProps({
    cotizaciones: Object,
    filtros: Object,
    estados: Object,
});

const rol = usePage().props.auth.user.rol;
const puedeCrear = ['admin', 'vendedor'].includes(rol);

const SEVERIDAD = { pendiente: 'info', vendida: 'success', vencida: 'warn', anulada: 'danger' };

// Filtros por estado, cada uno con su color
const FILTROS = [
    { value: null, label: 'Todas', activo: 'bg-slate-700 border-slate-700 text-white', inactivo: 'bg-slate-50 border-slate-300 text-slate-700 hover:bg-slate-100' },
    { value: 'pendiente', label: 'Pendientes', activo: 'bg-sky-600 border-sky-600 text-white', inactivo: 'bg-sky-50 border-sky-200 text-sky-800 hover:bg-sky-100' },
    { value: 'vendida', label: 'Vendidas', activo: 'bg-emerald-600 border-emerald-600 text-white', inactivo: 'bg-emerald-50 border-emerald-200 text-emerald-800 hover:bg-emerald-100' },
    { value: 'vencida', label: 'Vencidas', activo: 'bg-amber-500 border-amber-500 text-white', inactivo: 'bg-amber-50 border-amber-200 text-amber-800 hover:bg-amber-100' },
    { value: 'anulada', label: 'Anuladas', activo: 'bg-red-600 border-red-600 text-white', inactivo: 'bg-red-50 border-red-200 text-red-800 hover:bg-red-100' },
];

const buscar = ref(props.filtros.buscar ?? '');
const estado = ref(props.filtros.estado ?? null);

const recargar = (extra = {}) =>
    router.get('/cotizaciones', { buscar: buscar.value || undefined, estado: estado.value || undefined, ...extra }, { preserveState: true, preserveScroll: true, replace: true });

let espera;
watch(buscar, () => {
    clearTimeout(espera);
    espera = setTimeout(() => recargar(), 350);
});
const elegirEstado = (e) => {
    estado.value = e;
    recargar();
};
</script>

<template>
    <Head title="Cotizaciones" />
    <AppLayout titulo="Cotizaciones">
        <div class="bg-white rounded-xl border border-slate-200">
            <div class="p-4 flex flex-wrap items-center gap-3 border-b border-slate-100">
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="f in FILTROS"
                        :key="String(f.value)"
                        type="button"
                        class="px-3 py-1.5 rounded-full border text-sm font-medium transition-colors"
                        :class="estado === f.value ? f.activo : f.inactivo"
                        @click="elegirEstado(f.value)"
                    >
                        {{ f.label }}
                    </button>
                </div>
                <IconField class="w-full sm:w-72">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="buscar" placeholder="Cliente, RUC o N° (COT-12)" fluid />
                </IconField>
                <Link v-if="puedeCrear" href="/cotizaciones/nueva" class="sm:ml-auto">
                    <Button label="Nueva cotización" icon="pi pi-plus" />
                </Link>
            </div>

            <DataTable
                :value="cotizaciones.data"
                lazy
                paginator
                :rows="cotizaciones.per_page"
                :totalRecords="cotizaciones.total"
                :first="(cotizaciones.current_page - 1) * cotizaciones.per_page"
                @page="(e) => recargar({ page: e.page + 1 })"
            >
                <template #empty>Aún no hay cotizaciones. Crea la primera con "Nueva cotización".</template>
                <Column header="Cotización">
                    <template #body="{ data }">
                        <Link :href="`/cotizaciones/${data.id}`" class="font-medium text-emerald-700 hover:underline">{{ data.numero }}</Link>
                        <p class="text-xs text-slate-500">{{ fecha(data.fecha) }} · {{ data.items_count }} producto(s)</p>
                    </template>
                </Column>
                <Column header="Cliente">
                    <template #body="{ data }">
                        <p>{{ data.cliente?.razon_social }}</p>
                        <p class="text-xs text-slate-500">{{ data.cliente?.numero_documento }}</p>
                    </template>
                </Column>
                <Column header="Válida hasta">
                    <template #body="{ data }">
                        <span :class="data.estado_actual === 'vencida' ? 'text-amber-600 font-medium' : ''">{{ fecha(data.fecha_vencimiento) }}</span>
                    </template>
                </Column>
                <Column header="Total" class="text-right">
                    <template #body="{ data }"><span class="font-medium">{{ soles(data.total) }}</span></template>
                </Column>
                <Column header="Estado">
                    <template #body="{ data }"><Tag :value="estados[data.estado_actual]" :severity="SEVERIDAD[data.estado_actual]" /></template>
                </Column>
                <Column header="Venta">
                    <template #body="{ data }">
                        <Link v-if="data.comprobante" :href="`/comprobantes/${data.comprobante.id}`" class="text-emerald-700 hover:underline">{{ data.comprobante.numero }}</Link>
                        <span v-else class="text-slate-400">—</span>
                    </template>
                </Column>
                <Column class="text-right">
                    <template #body="{ data }">
                        <Link :href="`/cotizaciones/${data.id}`">
                            <Button icon="pi pi-eye" severity="info" size="small" v-tooltip.left="'Ver cotización'" />
                        </Link>
                    </template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>