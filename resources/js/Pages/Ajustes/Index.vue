<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import { soles, fecha } from '@/utils/formato';

const props = defineProps({
    ajustes: Object,
    filtros: Object,
    motivos: Object,
});

const rol = usePage().props.auth.user.rol;
const puedeRegistrar = ['admin', 'almacen'].includes(rol);

const buscar = ref(props.filtros.buscar ?? '');
const tipo = ref(props.filtros.tipo ?? 'todos');
const motivo = ref(props.filtros.motivo ?? null);

const recargar = (extra = {}) =>
    router.get(
        '/ajustes',
        {
            buscar: buscar.value || undefined,
            tipo: tipo.value === 'todos' ? undefined : tipo.value,
            motivo: motivo.value || undefined,
            ...extra,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );

let espera;
watch(buscar, () => {
    clearTimeout(espera);
    espera = setTimeout(() => recargar(), 350);
});
const elegirTipo = (t) => {
    tipo.value = t;
    motivo.value = null;
    recargar();
};

// Filtros por tipo, cada uno con su color
const TIPOS = [
    { value: 'todos', label: 'Todos', icono: 'pi pi-list', activo: 'bg-slate-700 border-slate-700 text-white', inactivo: 'bg-slate-50 border-slate-300 text-slate-700 hover:bg-slate-100' },
    { value: 'salida', label: 'Salidas', icono: 'pi pi-minus-circle', activo: 'bg-red-600 border-red-600 text-white', inactivo: 'bg-red-50 border-red-200 text-red-800 hover:bg-red-100' },
    { value: 'entrada', label: 'Entradas', icono: 'pi pi-plus-circle', activo: 'bg-emerald-600 border-emerald-600 text-white', inactivo: 'bg-emerald-50 border-emerald-200 text-emerald-800 hover:bg-emerald-100' },
];

const opcionesMotivo = computed(() => {
    const grupos = tipo.value === 'todos' ? Object.keys(props.motivos) : [tipo.value];
    return grupos.flatMap((t) => Object.entries(props.motivos[t]).map(([value, label]) => ({ value, label: tipo.value === 'todos' ? `${label} (${t})` : label })));
});
</script>

<template>
    <Head title="Ajustes de inventario" />
    <AppLayout titulo="Ajustes de inventario">
        <div class="bg-white rounded-xl border border-slate-200">
            <div class="p-4 flex flex-wrap gap-3 items-center border-b border-slate-100">
                <div class="flex gap-2">
                    <button
                        v-for="t in TIPOS"
                        :key="t.value"
                        type="button"
                        class="flex items-center gap-2 px-3 py-1.5 rounded-full border text-sm font-medium transition-colors"
                        :class="tipo === t.value ? t.activo : t.inactivo"
                        @click="elegirTipo(t.value)"
                    >
                        <i :class="t.icono" class="text-xs"></i>
                        {{ t.label }}
                    </button>
                </div>
                <Select
                    v-model="motivo"
                    :options="opcionesMotivo"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="Todos los motivos"
                    showClear
                    class="w-full sm:w-60"
                    @update:modelValue="recargar()"
                />
                <IconField class="w-full sm:w-64">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="buscar" placeholder="N° de ajuste, producto o detalle" fluid />
                </IconField>
                <Link v-if="puedeRegistrar" href="/ajustes/nuevo" class="sm:ml-auto">
                    <Button label="Nuevo ajuste" icon="pi pi-plus" />
                </Link>
            </div>

            <DataTable
                :value="ajustes.data"
                lazy
                paginator
                :rows="ajustes.per_page"
                :totalRecords="ajustes.total"
                :first="(ajustes.current_page - 1) * ajustes.per_page"
                @page="(e) => recargar({ page: e.page + 1 })"
            >
                <template #empty>No hay ajustes con ese filtro.</template>
                <Column header="N°">
                    <template #body="{ data }">
                        <Link :href="`/ajustes/${data.id}`" class="font-medium text-emerald-700 hover:underline">{{ data.numero }}</Link>
                        <p class="text-xs text-slate-500">{{ fecha(data.created_at) }} {{ data.created_at.substring(11, 16) }}</p>
                    </template>
                </Column>
                <Column header="Tipo">
                    <template #body="{ data }">
                        <Tag :value="data.tipo === 'salida' ? 'Salida' : 'Entrada'" :severity="data.tipo === 'salida' ? 'danger' : 'success'" />
                    </template>
                </Column>
                <Column header="Motivo">
                    <template #body="{ data }">
                        <p>{{ data.motivo_nombre }}</p>
                        <p class="text-xs text-slate-500 line-clamp-1 max-w-sm">{{ data.observacion }}</p>
                    </template>
                </Column>
                <Column header="Productos" class="text-center">
                    <template #body="{ data }">{{ data.items_count }}</template>
                </Column>
                <Column header="Valor" class="text-right">
                    <template #body="{ data }">
                        <span :class="data.tipo === 'salida' ? 'text-red-600' : 'text-emerald-700'">
                            {{ data.tipo === 'salida' ? '−' : '+' }} {{ soles(data.valor) }}
                        </span>
                    </template>
                </Column>
                <Column header="Registró">
                    <template #body="{ data }"><span class="text-slate-600">{{ data.usuario?.name }}</span></template>
                </Column>
                <Column class="text-right">
                    <template #body="{ data }">
                        <Link :href="`/ajustes/${data.id}`">
                            <Button icon="pi pi-eye" severity="info" size="small" v-tooltip.left="'Ver acta'" />
                        </Link>
                    </template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>