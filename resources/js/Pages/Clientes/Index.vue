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
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import Tag from 'primevue/tag';
import Message from 'primevue/message';
import { soles } from '@/utils/formato';

const props = defineProps({
    clientes: Object,
    filtros: Object,
    tipos: Object,
    consultaActiva: Boolean,
});

const buscar = ref(props.filtros.buscar ?? '');
const tipo = ref(props.filtros.tipo ?? null);
const filtro = ref(props.filtros.filtro ?? 'todos');
const opcionesTipo = Object.entries(props.tipos).filter(([v]) => v !== '0').map(([value, label]) => ({ value, label }));
const opcionesFiltro = [
    { value: 'todos', label: 'Todos' },
    { value: 'con_deuda', label: 'Con deuda' },
    { value: 'sunat', label: 'RUC con problemas' },
    { value: 'inactivos', label: 'Inactivos' },
];

const recargar = (extra = {}) =>
    router.get(
        '/clientes',
        { buscar: buscar.value || undefined, tipo: tipo.value || undefined, filtro: filtro.value === 'todos' ? undefined : filtro.value, ...extra },
        { preserveState: true, preserveScroll: true, replace: true },
    );

let espera;
watch(buscar, () => {
    clearTimeout(espera);
    espera = setTimeout(() => recargar(), 350);
});
watch([tipo, filtro], () => recargar());

const nombreDoc = (t) => ({ 1: 'DNI', 4: 'C.E.', 6: 'RUC', 7: 'Pasaporte' })[t] ?? 'Doc.';
const problemaSunat = (c) => c.tipo_documento === '6' && ((c.estado_sunat && c.estado_sunat !== 'ACTIVO') || (c.condicion_sunat && c.condicion_sunat !== 'HABIDO'));
</script>

<template>
    <Head title="Clientes" />
    <AppLayout titulo="Clientes">
        <Message v-if="!consultaActiva" severity="warn" class="mb-4">
            La consulta automática a SUNAT/RENIEC no está configurada (falta <b>DECOLECTA_API_KEY</b> en el .env). Los clientes se registran a mano.
        </Message>

        <div class="bg-white rounded-xl border border-slate-200">
            <div class="p-4 flex flex-wrap gap-3 items-center border-b border-slate-100">
                <IconField class="w-full sm:w-72">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="buscar" placeholder="Nombre, RUC o DNI" fluid />
                </IconField>
                <Select v-model="tipo" :options="opcionesTipo" optionLabel="label" optionValue="value" placeholder="Todos los documentos" showClear class="w-full sm:w-48" />
                <SelectButton v-model="filtro" :options="opcionesFiltro" optionLabel="label" optionValue="value" :allowEmpty="false" />
                <Link href="/clientes/nuevo" class="sm:ml-auto"><Button label="Nuevo cliente" icon="pi pi-user-plus" /></Link>
            </div>

            <DataTable
                :value="clientes.data"
                lazy
                paginator
                :rows="clientes.per_page"
                :totalRecords="clientes.total"
                :first="(clientes.current_page - 1) * clientes.per_page"
                :rowClass="(c) => (c.activo ? '' : 'opacity-60')"
                @page="(e) => recargar({ page: e.page + 1 })"
            >
                <template #empty>No hay clientes con ese filtro.</template>
                <Column header="Cliente">
                    <template #body="{ data }">
                        <Link :href="`/clientes/${data.id}/editar`" class="font-medium text-emerald-700 hover:underline">{{ data.razon_social }}</Link>
                        <p class="text-xs text-slate-500">
                            {{ nombreDoc(data.tipo_documento) }} {{ data.numero_documento }}<span v-if="data.nombre_comercial"> · {{ data.nombre_comercial }}</span>
                        </p>
                    </template>
                </Column>
                <Column header="Ubicación">
                    <template #body="{ data }">
                        <span class="text-sm">{{ [data.distrito, data.provincia].filter(Boolean).join(', ') || '—' }}</span>
                    </template>
                </Column>
                <Column header="Contacto">
                    <template #body="{ data }">
                        <p class="text-sm">{{ data.contacto || '—' }}</p>
                        <p v-if="data.telefono" class="text-xs text-slate-500"><i class="pi pi-phone text-[10px]"></i> {{ data.telefono }}</p>
                    </template>
                </Column>
                <Column header="Crédito">
                    <template #body="{ data }">
                        <span v-if="data.dias_credito > 0" class="text-sm">
                            {{ data.dias_credito }} días<span v-if="Number(data.limite_credito) > 0"> · hasta {{ soles(data.limite_credito) }}</span>
                        </span>
                        <span v-else class="text-sm text-slate-400">Solo contado</span>
                    </template>
                </Column>
                <Column header="Deuda" class="text-right">
                    <template #body="{ data }">
                        <Link v-if="Number(data.deuda) > 0" :href="`/cobranzas?buscar=${data.numero_documento}`" class="font-semibold text-red-600 hover:underline">
                            {{ soles(data.deuda) }}
                        </Link>
                        <span v-else class="text-slate-400">—</span>
                    </template>
                </Column>
                <Column header="SUNAT">
                    <template #body="{ data }">
                        <template v-if="data.tipo_documento === '6' && data.estado_sunat">
                            <Tag v-if="problemaSunat(data)" :value="`${data.estado_sunat} · ${data.condicion_sunat}`" severity="danger" />
                            <Tag v-else value="Activo · Habido" severity="success" />
                        </template>
                        <span v-else class="text-xs text-slate-400">{{ data.tipo_documento === '6' ? 'Sin verificar' : '' }}</span>
                        <Tag v-if="!data.activo" value="Inactivo" severity="secondary" class="ml-1" />
                    </template>
                </Column>
                <Column class="text-right">
                    <template #body="{ data }">
                        <Link :href="`/clientes/${data.id}/editar`"><Button icon="pi pi-pencil" severity="info" size="small" v-tooltip.top="'Ver / editar'" /></Link>
                    </template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>