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
import { fecha } from '@/utils/formato';

const props = defineProps({
    guias: Object,
    filtros: Object,
    estados: Object,
});

const rol = usePage().props.auth.user.rol;
const puedeEmitir = ['admin', 'vendedor', 'almacen'].includes(rol);

const SEVERIDAD = { pendiente: 'secondary', enviado: 'info', aceptado: 'success', rechazado: 'danger', error: 'warn' };

// Filtros por estado, cada uno con su color
const FILTROS = [
    { value: null, label: 'Todas', activo: 'bg-slate-700 border-slate-700 text-white', inactivo: 'bg-slate-50 border-slate-300 text-slate-700 hover:bg-slate-100' },
    { value: 'aceptado', label: 'Aceptadas', activo: 'bg-emerald-600 border-emerald-600 text-white', inactivo: 'bg-emerald-50 border-emerald-200 text-emerald-800 hover:bg-emerald-100' },
    { value: 'enviado', label: 'En proceso', activo: 'bg-sky-600 border-sky-600 text-white', inactivo: 'bg-sky-50 border-sky-200 text-sky-800 hover:bg-sky-100' },
    { value: 'error', label: 'Con error', activo: 'bg-amber-500 border-amber-500 text-white', inactivo: 'bg-amber-50 border-amber-200 text-amber-800 hover:bg-amber-100' },
    { value: 'rechazado', label: 'Rechazadas', activo: 'bg-red-600 border-red-600 text-white', inactivo: 'bg-red-50 border-red-200 text-red-800 hover:bg-red-100' },
];

const buscar = ref(props.filtros.buscar ?? '');
const estado = ref(props.filtros.estado ?? null);

const recargar = (extra = {}) =>
    router.get('/guias', { buscar: buscar.value || undefined, estado: estado.value || undefined, ...extra }, { preserveState: true, preserveScroll: true, replace: true });

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
    <Head title="Guías de remisión" />
    <AppLayout titulo="Guías de remisión">
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
                    <InputText v-model="buscar" placeholder="Cliente, RUC o N° de guía" fluid />
                </IconField>
                <Link v-if="puedeEmitir" href="/guias/nueva" class="sm:ml-auto">
                    <Button label="Nueva guía" icon="pi pi-plus" />
                </Link>
            </div>

            <DataTable
                :value="guias.data"
                lazy
                paginator
                :rows="guias.per_page"
                :totalRecords="guias.total"
                :first="(guias.current_page - 1) * guias.per_page"
                @page="(e) => recargar({ page: e.page + 1 })"
            >
                <template #empty>Aún no hay guías de remisión. Emítelas desde una factura con el botón "Emitir guía".</template>
                <Column header="Guía">
                    <template #body="{ data }">
                        <Link :href="`/guias/${data.id}`" class="font-medium text-emerald-700 hover:underline">{{ data.numero }}</Link>
                        <p class="text-xs text-slate-500">Emitida {{ fecha(data.fecha_emision) }}</p>
                    </template>
                </Column>
                <Column header="Destinatario">
                    <template #body="{ data }">
                        <p>{{ data.destinatario_nombre }}</p>
                        <p class="text-xs text-slate-500">{{ data.destinatario_num_doc }}</p>
                    </template>
                </Column>
                <Column header="Traslado">
                    <template #body="{ data }">
                        <p>{{ fecha(data.fecha_traslado) }}</p>
                        <p class="text-xs text-slate-500">{{ data.modalidad === '02' ? `Privado · ${data.vehiculo_placa}` : `Público · ${data.transportista_nombre ?? ''}` }}</p>
                    </template>
                </Column>
                <Column header="Comprobante">
                    <template #body="{ data }">
                        <Link v-if="data.comprobante" :href="`/comprobantes/${data.comprobante.id}`" class="text-emerald-700 hover:underline">{{ data.comprobante.numero }}</Link>
                        <span v-else class="text-slate-400">—</span>
                    </template>
                </Column>
                <Column header="Productos" class="text-center">
                    <template #body="{ data }">{{ data.items_count }}</template>
                </Column>
                <Column header="SUNAT">
                    <template #body="{ data }"><Tag :value="estados[data.estado]" :severity="SEVERIDAD[data.estado]" /></template>
                </Column>
                <Column class="text-right">
                    <template #body="{ data }">
                        <Link :href="`/guias/${data.id}`">
                            <Button icon="pi pi-eye" severity="info" size="small" v-tooltip.left="'Ver guía'" />
                        </Link>
                    </template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>