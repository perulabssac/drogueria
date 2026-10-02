<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AutoComplete from 'primevue/autocomplete';
import Button from 'primevue/button';
import DatePicker from 'primevue/datepicker';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import Message from 'primevue/message';
import { stock as textoStock, fecha, precio } from '@/utils/formato';
import { obtenerJson, aFechaISO } from '@/utils/http';

const props = defineProps({
    filtros: Object,
    producto: Object, // null hasta que se elige uno
    lotes: Array,
    kardex: Object,
});

// Enlaces a documentos solo si el usuario puede abrirlos
const rol = usePage().props.auth.user.rol;
const puedeVerComprobantes = ['admin', 'vendedor', 'contador'].includes(rol);
const puedeVerCompras = ['admin', 'almacen', 'contador'].includes(rol);
const enlace = (url) => {
    if (!url) return null;
    if (url.startsWith('/compras') || url.startsWith('/ajustes')) return puedeVerCompras ? url : null;
    return puedeVerComprobantes ? url : null;
};

// ================= FILTROS =================
const productoElegido = ref(props.producto);
const sugerencias = ref([]);
const buscarProductos = async (e) => {
    sugerencias.value = await obtenerJson('/api/productos/buscar', { q: e.query });
};

const aFecha = (iso) => new Date(`${iso}T00:00:00`);
const desde = ref(aFecha(props.filtros.desde));
const hasta = ref(aFecha(props.filtros.hasta));
const lote = ref(props.filtros.lote);

const consultar = (productoId = props.producto?.id, conLote = true) => {
    if (!productoId || !desde.value || !hasta.value) return;
    router.get(
        '/kardex',
        {
            producto: productoId,
            desde: aFechaISO(desde.value),
            hasta: aFechaISO(hasta.value),
            lote: conLote && lote.value ? lote.value : undefined,
        },
        { preserveScroll: true, replace: true },
    );
};
// Al cambiar de producto se quita el filtro de lote (los lotes son de cada producto)
const elegirProducto = (e) => consultar(e.value.id, false);

const rango = (tipo) => {
    const hoy = new Date();
    if (tipo === 'mes') desde.value = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
    if (tipo === 'mes_anterior') {
        desde.value = new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1);
        hasta.value = new Date(hoy.getFullYear(), hoy.getMonth(), 0);
        return consultar();
    }
    if (tipo === 'anio') desde.value = new Date(hoy.getFullYear(), 0, 1);
    hasta.value = hoy;
    consultar();
};

const opcionesLote = computed(() =>
    props.lotes.map((l) => ({
        value: l.id,
        label: `${l.numero_lote} · vence ${fecha(l.fecha_vencimiento)} · stock ${textoStock(l.cantidad, props.producto)}`,
    })),
);

const urlExcel = computed(() => {
    if (!props.producto) return null;
    const p = new URLSearchParams({ desde: props.filtros.desde, hasta: props.filtros.hasta });
    if (props.filtros.lote) p.set('lote', props.filtros.lote);
    return `/kardex/${props.producto.id}/excel?${p}`;
});

// ================= FORMATO =================
const cant = (valor) => (valor === null || valor === undefined ? '' : textoStock(valor, props.producto));
const unidadMinima = computed(() => (props.producto?.fraccionable ? props.producto.unidad_fraccion : props.producto?.unidad_venta));
const vencido = (iso) => iso && new Date(`${iso}T00:00:00`) < new Date(new Date().toDateString());
</script>

<template>
    <Head title="Kárdex" />
    <AppLayout titulo="Kárdex">
        <!-- Filtros -->
        <section class="bg-white rounded-xl border border-slate-200 p-4 mb-4 space-y-3">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 items-end">
                <div class="lg:col-span-5 flex flex-col gap-1">
                    <label class="text-sm">Producto</label>
                    <AutoComplete
                        v-model="productoElegido"
                        :suggestions="sugerencias"
                        optionLabel="descripcion"
                        placeholder="Busca por nombre, principio activo, código o código de barras"
                        :delay="250"
                        forceSelection
                        fluid
                        :autofocus="!producto"
                        @complete="buscarProductos"
                        @option-select="elegirProducto"
                    >
                        <template #option="{ option }">
                            <div>
                                <p>{{ option.descripcion }}</p>
                                <p class="text-xs text-slate-500">{{ option.codigo }} · {{ option.laboratorio }}</p>
                            </div>
                        </template>
                    </AutoComplete>
                </div>
                <div class="lg:col-span-2 flex flex-col gap-1">
                    <label class="text-sm">Desde</label>
                    <DatePicker v-model="desde" dateFormat="dd/mm/yy" :maxDate="hasta" showIcon fluid @date-select="consultar()" />
                </div>
                <div class="lg:col-span-2 flex flex-col gap-1">
                    <label class="text-sm">Hasta</label>
                    <DatePicker v-model="hasta" dateFormat="dd/mm/yy" :minDate="desde" :maxDate="new Date()" showIcon fluid @date-select="consultar()" />
                </div>
                <div class="lg:col-span-3 flex flex-col gap-1">
                    <label class="text-sm">Lote</label>
                    <Select
                        v-model="lote"
                        :options="opcionesLote"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Todos los lotes"
                        showClear
                        :disabled="!producto"
                        fluid
                        @update:modelValue="consultar()"
                    />
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-1">
                <span class="text-xs text-slate-500 mr-1">Periodo:</span>
                <Button label="Este mes" size="small" text :disabled="!producto" @click="rango('mes')" />
                <Button label="Mes anterior" size="small" text :disabled="!producto" @click="rango('mes_anterior')" />
                <Button label="Este año" size="small" text :disabled="!producto" @click="rango('anio')" />
                <a v-if="urlExcel" :href="urlExcel" class="ml-auto">
                    <Button label="Descargar Excel" icon="pi pi-file-excel" severity="success" size="small" />
                </a>
            </div>
        </section>

        <!-- Sin producto elegido -->
        <div v-if="!producto" class="bg-white rounded-xl border border-slate-200 p-10 text-center text-slate-500">
            <i class="pi pi-list text-3xl block mb-3"></i>
            Elige un producto para ver todas sus entradas y salidas, con el saldo después de cada movimiento.
        </div>

        <template v-else>
            <!-- Producto y resumen -->
            <section class="bg-white rounded-xl border border-slate-200 p-4 mb-4">
                <div class="flex flex-wrap items-start justify-between gap-2 mb-4">
                    <div>
                        <p class="font-semibold">{{ producto.descripcion }}</p>
                        <p class="text-xs text-slate-500">
                            {{ producto.codigo }}<span v-if="producto.laboratorio"> · {{ producto.laboratorio }}</span>
                            <span v-if="producto.fraccionable"> · {{ producto.unidad_venta }} x {{ producto.unidades_por_presentacion }} {{ producto.unidad_fraccion }}</span>
                        </p>
                    </div>
                    <Tag :value="`Stock actual: ${textoStock(producto.stock, producto)}`" severity="info" />
                </div>
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="rounded-lg bg-slate-50 p-3">
                        <p class="text-xs text-slate-500">Saldo al {{ fecha(filtros.desde) }}</p>
                        <p class="text-lg font-semibold">{{ cant(kardex.saldo_inicial) }}</p>
                    </div>
                    <div class="rounded-lg bg-emerald-50 p-3">
                        <p class="text-xs text-emerald-700">Entradas del periodo</p>
                        <p class="text-lg font-semibold text-emerald-700">+ {{ cant(kardex.entradas) }}</p>
                    </div>
                    <div class="rounded-lg bg-red-50 p-3">
                        <p class="text-xs text-red-700">Salidas del periodo</p>
                        <p class="text-lg font-semibold text-red-700">− {{ cant(kardex.salidas) }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-800 text-white p-3">
                        <p class="text-xs text-slate-300">Saldo al {{ fecha(filtros.hasta) }}</p>
                        <p class="text-lg font-semibold">{{ cant(kardex.saldo_final) }}</p>
                    </div>
                </div>
            </section>

            <Message v-if="kardex.recortado" severity="warn" class="mb-4">
                Hay demasiados movimientos en este periodo: se muestran los primeros 3,000. Acorta las fechas para ver el resto. Los totales de arriba sí están completos.
            </Message>

            <!-- Movimientos -->
            <section class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                        <tr>
                            <th class="text-left p-3">Fecha</th>
                            <th class="text-left p-3">Movimiento</th>
                            <th class="text-left p-3">Documento</th>
                            <th class="text-left p-3">Lote</th>
                            <th class="text-right p-3">Entrada</th>
                            <th class="text-right p-3">Salida</th>
                            <th class="text-right p-3">Saldo</th>
                            <th class="text-right p-3">Costo unit.</th>
                            <th class="text-left p-3">Usuario</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-t border-slate-100 bg-slate-50/60">
                            <td class="p-3 whitespace-nowrap">{{ fecha(filtros.desde) }}</td>
                            <td class="p-3 font-medium" colspan="5">Saldo inicial</td>
                            <td class="p-3 text-right font-semibold whitespace-nowrap">{{ cant(kardex.saldo_inicial) }}</td>
                            <td colspan="2"></td>
                        </tr>
                        <tr v-if="!kardex.filas.length">
                            <td colspan="9" class="p-8 text-center text-slate-400">No hubo movimientos en este periodo.</td>
                        </tr>
                        <tr v-for="f in kardex.filas" :key="f.id" class="border-t border-slate-100 align-top">
                            <td class="p-3 whitespace-nowrap">
                                {{ fecha(f.fecha) }}
                                <span class="block text-xs text-slate-500">{{ f.fecha.substring(11) }}</span>
                            </td>
                            <td class="p-3">
                                <Tag :value="f.tipo === 'entrada' ? 'Entrada' : 'Salida'" :severity="f.tipo === 'entrada' ? 'success' : 'danger'" class="mr-1" />
                                <span>{{ f.motivo }}</span>
                                <span v-if="f.observacion" class="block text-xs text-slate-500">{{ f.observacion }}</span>
                            </td>
                            <td class="p-3">
                                <Link v-if="enlace(f.url)" :href="f.url" class="text-emerald-700 hover:underline whitespace-nowrap">{{ f.documento }}</Link>
                                <span v-else class="whitespace-nowrap">{{ f.documento || '—' }}</span>
                                <span v-if="f.tercero" class="block text-xs text-slate-500">{{ f.tercero }}</span>
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                {{ f.lote }}
                                <span v-if="f.vencimiento" class="block text-xs" :class="vencido(f.vencimiento) ? 'text-red-600' : 'text-slate-500'">
                                    Vence {{ fecha(f.vencimiento) }}
                                </span>
                            </td>
                            <td class="p-3 text-right whitespace-nowrap text-emerald-700">{{ cant(f.entrada) }}</td>
                            <td class="p-3 text-right whitespace-nowrap text-red-600">{{ cant(f.salida) }}</td>
                            <td class="p-3 text-right whitespace-nowrap font-semibold">{{ cant(f.saldo) }}</td>
                            <td class="p-3 text-right whitespace-nowrap text-slate-500">{{ precio(f.costo_unitario) }}</td>
                            <td class="p-3 text-slate-500">{{ f.usuario || '—' }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="kardex.filas.length" class="border-t-2 border-slate-200 font-semibold">
                        <tr>
                            <td class="p-3" colspan="4">Totales del periodo</td>
                            <td class="p-3 text-right whitespace-nowrap text-emerald-700">{{ cant(kardex.entradas) }}</td>
                            <td class="p-3 text-right whitespace-nowrap text-red-600">{{ cant(kardex.salidas) }}</td>
                            <td class="p-3 text-right whitespace-nowrap">{{ cant(kardex.saldo_final) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </section>
            <p class="text-xs text-slate-500 mt-2">
                Las cantidades se guardan en {{ unidadMinima }}<span v-if="producto.fraccionable"> y se muestran en {{ producto.unidad_venta }} + {{ producto.unidad_fraccion }}</span>.
                El costo unitario es por {{ producto.unidad_venta }}, sin IGV.
            </p>
        </template>
    </AppLayout>
</template>