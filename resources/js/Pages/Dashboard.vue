<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import { stock, fecha, diasHasta } from '@/utils/formato';

defineProps({
    kpis: Object,
    stockBajo: Array,
    porVencer: Array,
});

const tarjetas = [
    { clave: 'productos', texto: 'Productos activos', icono: 'pi pi-box', color: 'text-emerald-600 bg-emerald-50' },
    { clave: 'stock_bajo', texto: 'Con stock bajo', icono: 'pi pi-arrow-down', color: 'text-amber-600 bg-amber-50' },
    { clave: 'lotes_por_vencer', texto: 'Lotes por vencer (90 días)', icono: 'pi pi-clock', color: 'text-orange-600 bg-orange-50' },
    { clave: 'lotes_vencidos', texto: 'Lotes vencidos con stock', icono: 'pi pi-exclamation-triangle', color: 'text-red-600 bg-red-50' },
];

const severidadVencimiento = (f) => {
    const dias = diasHasta(f);
    if (dias <= 30) return 'danger';
    if (dias <= 60) return 'warn';
    return 'info';
};

const textoVencimiento = (f) => {
    const dias = diasHasta(f);
    return dias < 0 ? `Vencido hace ${-dias} d` : `${dias} días`;
};
</script>

<template>
    <Head title="Inicio" />
    <AppLayout titulo="Inicio">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div v-for="t in tarjetas" :key="t.clave" class="bg-white rounded-xl border border-slate-200 p-4 flex items-center gap-4">
                <div class="w-11 h-11 rounded-lg flex items-center justify-center" :class="t.color">
                    <i :class="t.icono" class="text-lg"></i>
                </div>
                <div>
                    <p class="text-2xl font-semibold">{{ kpis[t.clave] }}</p>
                    <p class="text-xs text-slate-500">{{ t.texto }}</p>
                </div>
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-6">
            <section class="bg-white rounded-xl border border-slate-200 p-4">
                <h2 class="font-semibold mb-3"><i class="pi pi-clock text-orange-500 mr-2"></i>Próximos a vencer</h2>
                <DataTable :value="porVencer" size="small">
                    <template #empty>No hay lotes por vencer en los próximos 90 días.</template>
                    <Column header="Producto">
                        <template #body="{ data }">{{ data.producto.nombre }} {{ data.producto.concentracion }}</template>
                    </Column>
                    <Column field="numero_lote" header="Lote" />
                    <Column header="Cantidad" class="text-right">
                        <template #body="{ data }">{{ stock(data.cantidad, data.producto) }}</template>
                    </Column>
                    <Column header="Vence">
                        <template #body="{ data }">
                            <div class="flex items-center gap-2">
                                <span>{{ fecha(data.fecha_vencimiento) }}</span>
                                <Tag :value="textoVencimiento(data.fecha_vencimiento)" :severity="severidadVencimiento(data.fecha_vencimiento)" />
                            </div>
                        </template>
                    </Column>
                </DataTable>
            </section>

            <section class="bg-white rounded-xl border border-slate-200 p-4">
                <h2 class="font-semibold mb-3"><i class="pi pi-arrow-down text-amber-500 mr-2"></i>Stock bajo el mínimo</h2>
                <DataTable :value="stockBajo" size="small">
                    <template #empty>Todos los productos están sobre su stock mínimo.</template>
                    <Column header="Producto">
                        <template #body="{ data }">{{ data.nombre }} {{ data.concentracion }}</template>
                    </Column>
                    <Column header="Stock" class="text-right">
                        <template #body="{ data }"><span class="text-red-600 font-medium">{{ stock(data.stock, data) }}</span></template>
                    </Column>
                    <Column header="Mínimo" class="text-right">
                        <template #body="{ data }">{{ data.stock_minimo }} {{ data.unidad_venta }}</template>
                    </Column>
                </DataTable>
            </section>
        </div>
    </AppLayout>
</template>