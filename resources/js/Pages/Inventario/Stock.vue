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
import Tag from 'primevue/tag';
import { soles, precio, fecha, diasHasta, stock as textoStock } from '@/utils/formato';

const props = defineProps({
    productos: Object,
    filtros: Object,
    vistas: Object,
    conteos: Object,
    valor: Object,
    diasPorVencer: Number,
});

// ================= FILTROS =================
const buscar = ref(props.filtros.buscar ?? '');
const vista = ref(props.filtros.vista);

const recargar = (extra = {}) =>
    router.get(
        '/inventario',
        { buscar: buscar.value || undefined, vista: vista.value === 'todos' ? undefined : vista.value, ...extra },
        { preserveState: true, preserveScroll: true, replace: true },
    );

let espera;
watch(buscar, () => {
    clearTimeout(espera);
    espera = setTimeout(() => recargar(), 350);
});
const elegirVista = (v) => {
    vista.value = v;
    recargar();
};

// Cada filtro con su propio color (activo = relleno; inactivo = suave)
const ESTILO_VISTA = {
    todos: {
        icono: 'pi pi-th-large',
        activo: 'bg-emerald-600 border-emerald-600 text-white',
        inactivo: 'bg-emerald-50 border-emerald-200 text-emerald-800 hover:bg-emerald-100',
    },
    bajo_minimo: {
        icono: 'pi pi-arrow-down',
        activo: 'bg-sky-600 border-sky-600 text-white',
        inactivo: 'bg-sky-50 border-sky-200 text-sky-800 hover:bg-sky-100',
    },
    por_vencer: {
        icono: 'pi pi-clock',
        activo: 'bg-amber-500 border-amber-500 text-white',
        inactivo: 'bg-amber-50 border-amber-200 text-amber-800 hover:bg-amber-100',
    },
    vencidos: {
        icono: 'pi pi-ban',
        activo: 'bg-red-600 border-red-600 text-white',
        inactivo: 'bg-red-50 border-red-200 text-red-800 hover:bg-red-100',
    },
    sin_stock: {
        icono: 'pi pi-inbox',
        activo: 'bg-slate-700 border-slate-700 text-white',
        inactivo: 'bg-slate-50 border-slate-300 text-slate-700 hover:bg-slate-100',
    },
};
const opcionesVista = computed(() =>
    Object.entries(props.vistas).map(([value, label]) => ({ value, label, total: props.conteos[value], ...ESTILO_VISTA[value] })),
);

// Crear productos y registrar compras: solo almacén y administrador (el contador solo consulta)
const rol = usePage().props.auth.user.rol;
const puedeEditar = ['admin', 'almacen'].includes(rol);

const urlExcel = computed(() => {
    const p = new URLSearchParams();
    if (props.filtros.buscar) p.set('buscar', props.filtros.buscar);
    if (props.filtros.vista !== 'todos') p.set('vista', props.filtros.vista);
    return `/inventario/excel?${p}`;
});

// ================= FILAS =================
const expandidas = ref({});

const ESTADOS = {
    vigente: { texto: 'Vigente', severidad: 'success' },
    por_vencer: { texto: 'Por vencer', severidad: 'warn' },
    vencido: { texto: 'Vencido', severidad: 'danger' },
};

const textoDias = (iso) => {
    const d = diasHasta(iso);
    if (d === null) return '';
    if (d < 0) return `venció hace ${-d} días`;
    if (d === 0) return 'vence hoy';
    if (d < 60) return `en ${d} días`;
    return `en ${Math.round(d / 30)} meses`;
};
const colorDias = (iso) => {
    const d = diasHasta(iso);
    if (d === null) return 'text-slate-400';
    if (d < 0) return 'text-red-600';
    if (d <= props.diasPorVencer) return 'text-amber-600';
    return 'text-slate-500';
};
</script>

<template>
    <Head title="Stock" />
    <AppLayout titulo="Stock">
        <!-- Resumen -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-xs text-slate-500">Valor del inventario (a costo, sin IGV)</p>
                <p class="text-2xl font-semibold">{{ soles(valor.total) }}</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-xs text-slate-500">Mercadería vencida (por dar de baja)</p>
                <p class="text-2xl font-semibold" :class="valor.vencido > 0 ? 'text-red-600' : 'text-slate-400'">{{ soles(valor.vencido) }}</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-xs text-slate-500">Productos bajo stock mínimo</p>
                <p class="text-2xl font-semibold" :class="conteos.bajo_minimo > 0 ? 'text-red-600' : 'text-slate-400'">{{ conteos.bajo_minimo }}</p>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200">
            <!-- Filtros -->
            <div class="p-4 space-y-3 border-b border-slate-100">
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="o in opcionesVista"
                        :key="o.value"
                        type="button"
                        class="flex items-center gap-2 px-3 py-1.5 rounded-full border text-sm font-medium transition-colors"
                        :class="vista === o.value ? o.activo : o.inactivo"
                        @click="elegirVista(o.value)"
                    >
                        <i :class="o.icono" class="text-xs"></i>
                        {{ o.label }}
                        <span
                            v-if="o.total !== undefined"
                            class="text-xs rounded-full px-1.5 min-w-5 text-center"
                            :class="vista === o.value ? 'bg-white/25' : 'bg-white'"
                        >
                            {{ o.total }}
                        </span>
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <IconField class="w-full sm:w-80">
                        <InputIcon class="pi pi-search" />
                        <InputText v-model="buscar" placeholder="Producto, principio activo o código" fluid />
                    </IconField>
                    <span class="text-xs text-slate-500">"Por vencer" = vence en los próximos {{ diasPorVencer }} días.</span>
                    <div class="flex flex-wrap gap-2 sm:ml-auto">
                        <template v-if="puedeEditar">
                            <Link href="/productos/nuevo">
                                <Button label="Nuevo producto" icon="pi pi-plus" size="small" />
                            </Link>
                            <Link href="/compras/nueva">
                                <Button label="Registrar compra" icon="pi pi-truck" severity="warn" size="small" />
                            </Link>
                        </template>
                        <a :href="urlExcel">
                            <Button label="Descargar Excel" icon="pi pi-file-excel" severity="help" size="small" />
                        </a>
                    </div>
                </div>
            </div>

            <!-- Productos -->
            <DataTable
                v-model:expandedRows="expandidas"
                :value="productos.data"
                dataKey="id"
                lazy
                paginator
                :rows="productos.per_page"
                :totalRecords="productos.total"
                :first="(productos.current_page - 1) * productos.per_page"
                @page="(e) => recargar({ page: e.page + 1 })"
            >
                <template #empty>No hay productos con ese filtro.</template>

                <Column expander style="width: 3rem" />
                <Column header="Producto">
                    <template #body="{ data }">
                        <p class="font-medium">{{ data.descripcion }}</p>
                        <p class="text-xs text-slate-500">
                            {{ data.codigo }}<span v-if="data.laboratorio"> · {{ data.laboratorio }}</span>
                            <Tag v-if="!data.activo" value="Inactivo" severity="secondary" class="ml-1" />
                        </p>
                    </template>
                </Column>
                <Column header="Stock disponible">
                    <template #body="{ data }">
                        <span class="font-semibold" :class="data.stock <= 0 ? 'text-slate-400' : data.bajo_minimo ? 'text-red-600' : ''">
                            {{ textoStock(data.stock, data) }}
                        </span>
                        <p v-if="data.stock_minimo > 0" class="text-xs" :class="data.bajo_minimo ? 'text-red-600' : 'text-slate-500'">
                            <i v-if="data.bajo_minimo" class="pi pi-arrow-down text-[10px]"></i>
                            Mínimo {{ data.stock_minimo }} {{ data.unidad_venta }}
                        </p>
                    </template>
                </Column>
                <Column header="Vencido">
                    <template #body="{ data }">
                        <Tag v-if="data.stock_vencido > 0" :value="textoStock(data.stock_vencido, data)" severity="danger" />
                        <span v-else class="text-slate-300">—</span>
                    </template>
                </Column>
                <Column header="Próximo vencimiento">
                    <template #body="{ data }">
                        <template v-if="data.proximo_vencimiento">
                            <p>{{ fecha(data.proximo_vencimiento) }}</p>
                            <p class="text-xs" :class="colorDias(data.proximo_vencimiento)">{{ textoDias(data.proximo_vencimiento) }}</p>
                        </template>
                        <span v-else class="text-slate-300">—</span>
                    </template>
                </Column>
                <Column header="Valor" class="text-right">
                    <template #body="{ data }">
                        <span :class="data.valor > 0 ? '' : 'text-slate-300'">{{ soles(data.valor) }}</span>
                    </template>
                </Column>
                <Column class="text-right">
                    <template #body="{ data }">
                        <Link :href="`/kardex?producto=${data.id}`">
                            <Button icon="pi pi-list" severity="info" size="small" v-tooltip.left="'Ver kárdex'" />
                        </Link>
                    </template>
                </Column>

                <!-- Lotes del producto (en el orden en que saldrán: FEFO) -->
                <template #expansion="{ data }">
                    <div class="px-4 py-3 bg-slate-50 rounded-lg">
                        <p v-if="!data.lotes.length" class="text-sm text-slate-500">Sin lotes con stock.</p>
                        <table v-else class="w-full text-sm">
                            <thead class="text-xs uppercase text-slate-500">
                                <tr>
                                    <th class="text-left py-1 pr-3">Lote</th>
                                    <th class="text-left py-1 pr-3">Vence</th>
                                    <th class="text-left py-1 pr-3">Estado</th>
                                    <th class="text-right py-1 pr-3">Cantidad</th>
                                    <th class="text-right py-1 pr-3">Costo unit.</th>
                                    <th class="text-right py-1 pr-3">Valor</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="l in data.lotes" :key="l.id" class="border-t border-slate-200">
                                    <td class="py-1.5 pr-3 font-medium">{{ l.numero_lote }}</td>
                                    <td class="py-1.5 pr-3">
                                        {{ fecha(l.fecha_vencimiento) }}
                                        <span class="text-xs ml-1" :class="colorDias(l.fecha_vencimiento)">{{ textoDias(l.fecha_vencimiento) }}</span>
                                    </td>
                                    <td class="py-1.5 pr-3"><Tag :value="ESTADOS[l.estado].texto" :severity="ESTADOS[l.estado].severidad" /></td>
                                    <td class="py-1.5 pr-3 text-right whitespace-nowrap">{{ textoStock(l.cantidad, data) }}</td>
                                    <td class="py-1.5 pr-3 text-right text-slate-500">{{ precio(l.costo_unitario) }}</td>
                                    <td class="py-1.5 pr-3 text-right">{{ soles(l.valor) }}</td>
                                    <td class="py-1.5 text-right">
                                        <Link :href="`/kardex?producto=${data.id}&lote=${l.id}`" class="text-xs text-emerald-700 hover:underline">kárdex del lote</Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>