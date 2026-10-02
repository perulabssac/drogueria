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
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import Tag from 'primevue/tag';
import { soles, fecha } from '@/utils/formato';
import { aFechaISO } from '@/utils/http';
import { ESTADOS_SUNAT, estadoSunat } from '@/utils/sunat';

const props = defineProps({
    comprobantes: Object,
    filtros: Object,
    tipos: Object,
});
// El contador solo consulta: no ve botones para vender
const soloLectura = usePage().props.auth.user.rol === 'contador';
const buscar = ref(props.filtros.buscar ?? '');
const tipo = ref(props.filtros.tipo ?? null);
const estado = ref(props.filtros.estado ?? null);
const desde = ref(props.filtros.desde ? new Date(props.filtros.desde + 'T00:00:00') : null);
const hasta = ref(props.filtros.hasta ? new Date(props.filtros.hasta + 'T00:00:00') : null);

const opcionesTipo = Object.entries(props.tipos).map(([value, label]) => ({ value, label }));
const opcionesEstado = Object.entries(ESTADOS_SUNAT).map(([value, e]) => ({ value, label: e.texto }));

const recargar = (extra = {}) =>
    router.get(
        '/comprobantes',
        {
            buscar: buscar.value || undefined,
            tipo: tipo.value || undefined,
            estado: estado.value || undefined,
            desde: aFechaISO(desde.value) || undefined,
            hasta: aFechaISO(hasta.value) || undefined,
            ...extra,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );

let espera;
watch(buscar, () => {
    clearTimeout(espera);
    espera = setTimeout(() => recargar(), 350);
});
watch([tipo, estado, desde, hasta], () => recargar());
</script>

<template>
    <Head title="Comprobantes" />
    <AppLayout titulo="Comprobantes">
        <div class="bg-white rounded-xl border border-slate-200">
            <div class="p-4 flex flex-wrap gap-3 items-center border-b border-slate-100">
                <IconField class="w-full sm:w-72">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="buscar" placeholder="Cliente, RUC/DNI o número (F001-25)" fluid />
                </IconField>
                <Select v-model="tipo" :options="opcionesTipo" optionLabel="label" optionValue="value" placeholder="Todos los tipos" showClear class="w-full sm:w-44" />
                <Select v-model="estado" :options="opcionesEstado" optionLabel="label" optionValue="value" placeholder="Todos los estados" showClear class="w-full sm:w-44" />
                <DatePicker v-model="desde" placeholder="Desde" dateFormat="dd/mm/yy" showButtonBar class="w-full sm:w-36" />
                <DatePicker v-model="hasta" placeholder="Hasta" dateFormat="dd/mm/yy" showButtonBar class="w-full sm:w-36" />
                    <Link v-if="!soloLectura" href="/ventas/nueva" class="sm:ml-auto">
                    <Button label="Nueva venta" icon="pi pi-plus" />
                </Link>
            </div>

            <DataTable
                :value="comprobantes.data"
                lazy
                paginator
                :rows="comprobantes.per_page"
                :totalRecords="comprobantes.total"
                :first="(comprobantes.current_page - 1) * comprobantes.per_page"
                @page="(e) => recargar({ page: e.page + 1 })"
            >
                <template #empty>Aún no hay comprobantes emitidos.</template>
                <Column header="Fecha">
                    <template #body="{ data }">{{ fecha(data.fecha_emision) }}</template>
                </Column>
                <Column header="Comprobante">
                    <template #body="{ data }">
                        <p class="font-medium">{{ data.numero }}</p>
                        <p class="text-xs text-slate-500">{{ data.tipo_nombre }}</p>
                    </template>
                </Column>
                <Column header="Cliente">
                    <template #body="{ data }">
                        <p>{{ data.cliente.razon_social }}</p>
                        <p class="text-xs text-slate-500">{{ data.cliente.numero_documento }}</p>
                    </template>
                </Column>
                <Column header="Pago">
                    <template #body="{ data }">
                        <Tag v-if="data.forma_pago === 'credito'" :value="'Crédito · ' + fecha(data.fecha_vencimiento)" severity="warn" />
                        <span v-else class="text-slate-500">Contado</span>
                    </template>
                </Column>
                <Column header="Total" class="text-right">
                    <template #body="{ data }">{{ soles(data.total) }}</template>
                </Column>
                <Column header="SUNAT">
                    <template #body="{ data }">
                        <Tag :value="estadoSunat(data.estado).texto" :severity="estadoSunat(data.estado).severidad" :icon="estadoSunat(data.estado).icono" />
                    </template>
                </Column>
                <Column class="text-right">
                    <template #body="{ data }">
                        <Link :href="`/comprobantes/${data.id}`"><Button icon="pi pi-eye" text rounded v-tooltip.top="'Ver detalle'" /></Link>
                    </template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>