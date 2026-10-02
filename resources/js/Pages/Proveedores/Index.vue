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

const props = defineProps({
    proveedores: Object,
    filtros: Object,
});

const buscar = ref(props.filtros.buscar ?? '');

const recargar = (extra = {}) =>
    router.get('/proveedores', { buscar: buscar.value || undefined, ...extra }, { preserveState: true, preserveScroll: true, replace: true });

let espera;
watch(buscar, () => {
    clearTimeout(espera);
    espera = setTimeout(() => recargar(), 350);
});
</script>

<template>
    <Head title="Proveedores" />
    <AppLayout titulo="Proveedores">
        <div class="bg-white rounded-xl border border-slate-200">
            <div class="p-4 flex flex-wrap gap-3 items-center border-b border-slate-100">
                <IconField class="w-full sm:w-80">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="buscar" placeholder="Razón social o RUC" fluid />
                </IconField>
                <Link href="/proveedores/nuevo" class="sm:ml-auto">
                    <Button label="Nuevo proveedor" icon="pi pi-plus" />
                </Link>
            </div>

            <DataTable
                :value="proveedores.data"
                lazy
                paginator
                :rows="proveedores.per_page"
                :totalRecords="proveedores.total"
                :first="(proveedores.current_page - 1) * proveedores.per_page"
                @page="(e) => recargar({ page: e.page + 1 })"
            >
                <template #empty>No hay proveedores registrados.</template>
                <Column field="ruc" header="RUC" />
                <Column header="Razón social">
                    <template #body="{ data }">
                        <p class="font-medium">{{ data.razon_social }}</p>
                        <p class="text-xs text-slate-500">{{ data.direccion }}</p>
                    </template>
                </Column>
                <Column header="Contacto">
                    <template #body="{ data }">
                        <p>{{ data.contacto }}</p>
                        <p class="text-xs text-slate-500">{{ data.telefono }} {{ data.email }}</p>
                    </template>
                </Column>
                <Column field="compras_count" header="Compras" class="text-right" />
                <Column header="Estado">
                    <template #body="{ data }">
                        <Tag :value="data.activo ? 'Activo' : 'Inactivo'" :severity="data.activo ? 'success' : 'secondary'" />
                    </template>
                </Column>
                <Column class="text-right">
                    <template #body="{ data }">
                        <Link :href="`/proveedores/${data.id}/editar`">
                            <Button icon="pi pi-pencil" text rounded v-tooltip.top="'Editar'" />
                        </Link>
                    </template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>