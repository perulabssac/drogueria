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
import DatePicker from 'primevue/datepicker';
import Tag from 'primevue/tag';
import { soles, fecha } from '@/utils/formato';
import { aFechaISO } from '@/utils/http';

const props = defineProps({
    compras: Object,
    filtros: Object,
});

const buscar = ref(props.filtros.buscar ?? '');
const desde = ref(props.filtros.desde ? new Date(props.filtros.desde + 'T00:00:00') : null);
const hasta = ref(props.filtros.hasta ? new Date(props.filtros.hasta + 'T00:00:00') : null);

const recargar = (extra = {}) =>
    router.get(
        '/compras',
        { buscar: buscar.value || undefined, desde: aFechaISO(desde.value) || undefined, hasta: aFechaISO(hasta.value) || undefined, ...extra },
        { preserveState: true, preserveScroll: true, replace: true },
    );

let espera;
watch(buscar, () => {
    clearTimeout(espera);
    espera = setTimeout(() => recargar(), 350);
});
watch([desde, hasta], () => recargar());
// El contador solo consulta: no registra compras
const soloLectura = usePage().props.auth.user.rol === 'contador';
</script>

<template>
    <Head title="Compras" />
    <AppLayout titulo="Compras">
        <div class="bg-white rounded-xl border border-slate-200">
            <div class="p-4 flex flex-wrap gap-3 items-center border-b border-slate-100">
                <IconField class="w-full sm:w-72">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="buscar" placeholder="Proveedor, RUC o N° de documento" fluid />
                </IconField>
                <DatePicker v-model="desde" placeholder="Desde" dateFormat="dd/mm/yy" showIcon showButtonBar class="w-full sm:w-44" />
                <DatePicker v-model="hasta" placeholder="Hasta" dateFormat="dd/mm/yy" showIcon showButtonBar class="w-full sm:w-44" />
                    <Link v-if="!soloLectura" href="/compras/nueva" class="sm:ml-auto">
                    <Button label="Registrar compra" icon="pi pi-plus" />
                </Link>
            </div>

            <DataTable
                :value="compras.data"
                lazy
                paginator
                :rows="compras.per_page"
                :totalRecords="compras.total"
                :first="(compras.current_page - 1) * compras.per_page"
                :rowClass="(c) => (c.estado === 'anulada' ? 'opacity-50' : '')"
                @page="(e) => recargar({ page: e.page + 1 })"
            >
                <template #empty>Aún no hay compras registradas.</template>
                <Column header="Fecha">
                    <template #body="{ data }">{{ fecha(data.fecha_emision) }}</template>
                </Column>
                <Column header="Documento">
                    <template #body="{ data }">
                        <span class="font-medium">{{ data.tipo_documento === '01' ? 'Factura' : 'Boleta' }} {{ data.documento }}</span>
                    </template>
                </Column>
                <Column header="Proveedor">
                    <template #body="{ data }">
                        <p>{{ data.proveedor.razon_social }}</p>
                        <p class="text-xs text-slate-500">RUC {{ data.proveedor.ruc }}</p>
                    </template>
                </Column>
                <Column field="items_count" header="Ítems" class="text-right" />
                <Column header="Pago">
                    <template #body="{ data }">
                        <Tag v-if="data.forma_pago === 'credito'" :value="'Crédito · vence ' + fecha(data.fecha_vencimiento)" severity="warn" />
                        <Tag v-else value="Contado" severity="secondary" />
                    </template>
                </Column>
                <Column header="Total" class="text-right">
                    <template #body="{ data }">{{ soles(data.total) }}</template>
                </Column>
                <Column header="Estado">
                    <template #body="{ data }">
                        <Tag :value="data.estado === 'anulada' ? 'Anulada' : 'Registrada'" :severity="data.estado === 'anulada' ? 'danger' : 'success'" />
                    </template>
                </Column>
                <Column class="text-right">
                    <template #body="{ data }">
                        <Link :href="`/compras/${data.id}`"><Button icon="pi pi-eye" text rounded v-tooltip.top="'Ver detalle'" /></Link>
                    </template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>