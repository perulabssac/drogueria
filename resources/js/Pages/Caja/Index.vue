<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import Tag from 'primevue/tag';
import { soles, fecha } from '@/utils/formato';
import { aFechaISO } from '@/utils/http';

const props = defineProps({
    cajas: Object,
    filtros: Object,
    usuarios: Array,
});

const hora = (v) => String(v ?? '').substring(11, 16);
const usuario = ref(props.filtros.usuario ? Number(props.filtros.usuario) : null);
const estado = ref(props.filtros.estado ?? null);
const desde = ref(props.filtros.desde ? new Date(props.filtros.desde + 'T00:00:00') : null);
const hasta = ref(props.filtros.hasta ? new Date(props.filtros.hasta + 'T00:00:00') : null);

const recargar = (extra = {}) =>
    router.get(
        '/cajas',
        {
            usuario: usuario.value || undefined,
            estado: estado.value || undefined,
            desde: aFechaISO(desde.value) || undefined,
            hasta: aFechaISO(hasta.value) || undefined,
            ...extra,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
watch([usuario, estado, desde, hasta], () => recargar());

// Color de la diferencia: cuadre (verde), sobrante (ámbar), faltante (rojo)
const severidad = (d) => (Math.abs(Number(d)) < 0.01 ? 'success' : Number(d) > 0 ? 'warn' : 'danger');
</script>

<template>
    <Head title="Historial de cajas" />
    <AppLayout titulo="Historial de cajas">
        <div class="bg-white rounded-xl border border-slate-200">
            <div class="p-4 flex flex-wrap gap-3 items-center border-b border-slate-100">
                <Select v-model="usuario" :options="usuarios" optionLabel="name" optionValue="id" placeholder="Todos los cajeros" showClear class="w-full sm:w-52" />
                <Select
                    v-model="estado"
                    :options="[{ value: 'abierta', label: 'Abiertas' }, { value: 'cerrada', label: 'Cerradas' }]"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Todas"
                    showClear
                    class="w-full sm:w-40"
                />
                <DatePicker v-model="desde" placeholder="Desde" dateFormat="dd/mm/yy" showButtonBar class="w-full sm:w-36" />
                <DatePicker v-model="hasta" placeholder="Hasta" dateFormat="dd/mm/yy" showButtonBar class="w-full sm:w-36" />
                <Link href="/caja" class="sm:ml-auto"><Button label="Mi caja" icon="pi pi-wallet" /></Link>
            </div>

            <DataTable
                :value="cajas.data"
                lazy
                paginator
                :rows="cajas.per_page"
                :totalRecords="cajas.total"
                :first="(cajas.current_page - 1) * cajas.per_page"
                @page="(e) => recargar({ page: e.page + 1 })"
            >
                <template #empty>Aún no hay cajas registradas.</template>
                <Column header="Cajero">
                    <template #body="{ data }">{{ data.usuario.name }}</template>
                </Column>
                <Column header="Apertura">
                    <template #body="{ data }">{{ fecha(data.abierta_at) }} {{ hora(data.abierta_at) }}</template>
                </Column>
                <Column header="Cierre">
                    <template #body="{ data }">
                        <span v-if="data.cerrada_at">{{ fecha(data.cerrada_at) }} {{ hora(data.cerrada_at) }}</span>
                        <Tag v-else value="Abierta" severity="success" icon="pi pi-lock-open" />
                    </template>
                </Column>
                <Column header="Inicial" class="text-right">
                    <template #body="{ data }">{{ soles(data.monto_inicial) }}</template>
                </Column>
                <Column header="Esperado" class="text-right">
                    <template #body="{ data }">{{ data.efectivo_esperado == null ? '—' : soles(data.efectivo_esperado) }}</template>
                </Column>
                <Column header="Contado" class="text-right">
                    <template #body="{ data }">{{ data.efectivo_contado == null ? '—' : soles(data.efectivo_contado) }}</template>
                </Column>
                <Column header="Diferencia" class="text-right">
                    <template #body="{ data }">
                        <Tag v-if="data.diferencia != null" :value="soles(data.diferencia)" :severity="severidad(data.diferencia)" />
                    </template>
                </Column>
                <Column class="text-right">
                    <template #body="{ data }">
                        <Link :href="`/cajas/${data.id}`"><Button icon="pi pi-eye" text rounded v-tooltip.top="'Ver reporte'" /></Link>
                    </template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>