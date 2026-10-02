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
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';
import { precio, stock } from '@/utils/formato';

const props = defineProps({
    productos: Object, // paginación de Laravel: data, total, per_page, current_page
    filtros: Object,
    laboratorios: Array,
    categorias: Array,
});

const confirm = useConfirm();

// ---------- Búsqueda, filtros y paginación (se hacen en el servidor) ----------
const buscar = ref(props.filtros.buscar ?? '');
const laboratorioId = ref(props.filtros.laboratorio_id ? Number(props.filtros.laboratorio_id) : null);
const categoria = ref(props.filtros.categoria ?? null);

const recargar = (extra = {}) => {
    router.get(
        '/productos',
        {
            buscar: buscar.value || undefined,
            laboratorio_id: laboratorioId.value || undefined,
            categoria: categoria.value || undefined,
            ...extra,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

let espera;
watch(buscar, () => {
    clearTimeout(espera);
    espera = setTimeout(() => recargar(), 350); // espera a que termine de escribir
});
watch([laboratorioId, categoria], () => recargar());

const cambiarPagina = (e) => recargar({ page: e.page + 1, por_pagina: e.rows });

const desactivar = (p) => {
    confirm.require({
        header: 'Desactivar producto',
        message: `¿Desactivar "${p.nombre} ${p.concentracion ?? ''}"? Ya no aparecerá para la venta, pero se conserva su historial.`,
        icon: 'pi pi-exclamation-triangle',
        acceptProps: { label: 'Desactivar', severity: 'danger' },
        rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
        accept: () => router.delete(`/productos/${p.id}`, { preserveScroll: true }),
    });
};

// stock_minimo está en presentaciones; el stock, en unidades mínimas
const stockBajo = (p) => {
    const factor = p.fraccionable ? p.unidades_por_presentacion : 1;
    return Number(p.stock ?? 0) <= Number(p.stock_minimo) * factor;
};
</script>

<template>
    <Head title="Productos" />
    <AppLayout titulo="Productos">
        <div class="bg-white rounded-xl border border-slate-200">
            <!-- Barra de filtros -->
            <div class="p-4 flex flex-wrap gap-3 items-center border-b border-slate-100">
                <IconField class="w-full sm:w-80">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="buscar" placeholder="Nombre, principio activo o código" fluid />
                </IconField>
                <Select v-model="laboratorioId" :options="laboratorios" optionLabel="nombre" optionValue="id" placeholder="Todos los laboratorios" showClear filter class="w-full sm:w-56" />
                <Select v-model="categoria" :options="categorias" placeholder="Todas las categorías" showClear filter class="w-full sm:w-56" />
                <Link href="/productos/nuevo" class="sm:ml-auto">
                    <Button label="Nuevo producto" icon="pi pi-plus" />
                </Link>
            </div>

            <DataTable
                :value="productos.data"
                lazy
                paginator
                :rows="productos.per_page"
                :totalRecords="productos.total"
                :first="(productos.current_page - 1) * productos.per_page"
                :rowsPerPageOptions="[15, 30, 50]"
                currentPageReportTemplate="{first} a {last} de {totalRecords}"
                paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown CurrentPageReport"
                :rowClass="(p) => (!p.activo ? 'opacity-50' : '')"
                @page="cambiarPagina"
            >
                <template #empty>No se encontraron productos.</template>

                <Column field="codigo" header="Código" class="whitespace-nowrap" />
                <Column header="Producto">
                    <template #body="{ data }">
                        <p class="font-medium">{{ data.nombre }} {{ data.concentracion }} <span class="text-slate-500 font-normal">{{ data.presentacion }}</span></p>
                        <p class="text-xs text-slate-500">
                            {{ data.principio_activo }}
                            <span v-if="data.registro_sanitario"> · R.S. {{ data.registro_sanitario }}</span>
                            <span v-if="data.categoria"> · {{ data.categoria }}</span>
                        </p>
                    </template>
                </Column>
                <Column header="Laboratorio">
                    <template #body="{ data }">{{ data.laboratorio?.nombre }}</template>
                </Column>
                <Column header="Condición">
                    <template #body="{ data }">
                        <div class="flex flex-wrap gap-1">
                            <Tag v-if="data.condicion_venta !== 'sin_receta'" value="Receta" severity="warn" />
                            <Tag v-if="data.controlado" value="Controlado" severity="danger" />
                            <Tag v-if="data.cadena_frio" value="Frío" icon="pi pi-asterisk" severity="info" />
                            <Tag v-if="data.tipo_afectacion_igv !== '10'" value="Exonerado" severity="secondary" />
                            <Tag v-if="!data.activo" value="Inactivo" severity="secondary" />
                        </div>
                    </template>
                </Column>
                <Column header="Precio" class="text-right whitespace-nowrap">
                    <template #body="{ data }">
                        <p>{{ precio(data.precio_venta) }} <span class="text-xs text-slate-500">/ {{ data.unidad_venta }}</span></p>
                        <p v-if="data.fraccionable" class="text-xs text-slate-500">{{ precio(data.precio_fraccion) }} / {{ data.unidad_fraccion }}</p>
                    </template>
                </Column>
                <Column header="Stock" class="text-right">
                    <template #body="{ data }">
                        <Tag :value="stock(data.stock, data)" :severity="stockBajo(data) ? 'danger' : 'success'" />
                    </template>
                </Column>
                <Column class="text-right whitespace-nowrap">
                    <template #body="{ data }">
                        <Link :href="`/productos/${data.id}/editar`">
                            <Button icon="pi pi-pencil" text rounded v-tooltip.top="'Editar'" />
                        </Link>
                        <Button v-if="data.activo" icon="pi pi-ban" text rounded severity="danger" v-tooltip.top="'Desactivar'" @click="desactivar(data)" />
                    </template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>