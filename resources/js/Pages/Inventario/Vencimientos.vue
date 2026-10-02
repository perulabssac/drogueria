<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Textarea from 'primevue/textarea';
import Message from 'primevue/message';
import { soles, fecha, diasHasta, stock as textoStock } from '@/utils/formato';

const props = defineProps({
    lotes: Object,
    vista: String,
    vistas: Object,
    resumen: Object,
});

const rol = usePage().props.auth.user.rol;
const puedeDarBaja = ['admin', 'almacen'].includes(rol);
const esVencidos = computed(() => props.vista === 'vencidos');

// ================= FILTROS (cada uno con su color) =================
const ESTILO = {
    vencidos: { icono: 'pi pi-ban', activo: 'bg-red-600 border-red-600 text-white', inactivo: 'bg-red-50 border-red-200 text-red-800 hover:bg-red-100' },
    30: { icono: 'pi pi-exclamation-triangle', activo: 'bg-orange-500 border-orange-500 text-white', inactivo: 'bg-orange-50 border-orange-200 text-orange-800 hover:bg-orange-100' },
    60: { icono: 'pi pi-clock', activo: 'bg-amber-500 border-amber-500 text-white', inactivo: 'bg-amber-50 border-amber-200 text-amber-800 hover:bg-amber-100' },
    90: { icono: 'pi pi-calendar', activo: 'bg-yellow-500 border-yellow-500 text-white', inactivo: 'bg-yellow-50 border-yellow-200 text-yellow-800 hover:bg-yellow-100' },
    180: { icono: 'pi pi-calendar-plus', activo: 'bg-sky-600 border-sky-600 text-white', inactivo: 'bg-sky-50 border-sky-200 text-sky-800 hover:bg-sky-100' },
};
const opciones = computed(() => Object.entries(props.vistas).map(([value, label]) => ({ value, label, ...ESTILO[value] })));

const ir = (extra = {}) => router.get('/vencimientos', { vista: props.vista, ...extra }, { preserveScroll: true, replace: true });
const elegirVista = (v) => router.get('/vencimientos', { vista: v }, { replace: true });

// ================= FILAS =================
const textoDias = (iso) => {
    const d = diasHasta(iso);
    if (d < 0) return `venció hace ${-d} día${d === -1 ? '' : 's'}`;
    if (d === 0) return 'vence hoy';
    return `en ${d} día${d === 1 ? '' : 's'}`;
};
const colorDias = (iso) => {
    const d = diasHasta(iso);
    if (d < 0) return 'text-red-600 font-medium';
    if (d <= 30) return 'text-orange-600';
    if (d <= 90) return 'text-amber-600';
    return 'text-slate-500';
};

// ================= DAR DE BAJA =================
const seleccion = ref([]);
const totalSeleccion = computed(() => Math.round(seleccion.value.reduce((s, l) => s + l.valor, 0) * 100) / 100);
const dialogo = ref(false);
const form = useForm({ lotes: [], observacion: '' });

const abrirBaja = () => {
    form.clearErrors();
    dialogo.value = true;
};
const darDeBaja = () =>
    form
        .transform((d) => ({ ...d, lotes: seleccion.value.map((l) => l.id) }))
        .post('/vencimientos/baja', { onSuccess: () => (dialogo.value = false) });
</script>

<template>
    <Head title="Vencimientos" />
    <AppLayout titulo="Vencimientos">
        <!-- Resumen -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
            <button type="button" class="text-left bg-white rounded-xl border-2 p-4 transition-colors" :class="esVencidos ? 'border-red-500' : 'border-red-100 hover:border-red-300'" @click="elegirVista('vencidos')">
                <p class="text-xs text-red-700 font-medium"><i class="pi pi-ban text-[10px]"></i> Vencidos (por dar de baja)</p>
                <p class="text-2xl font-semibold" :class="resumen.vencidos.lotes ? 'text-red-600' : 'text-slate-400'">{{ soles(resumen.vencidos.valor) }}</p>
                <p class="text-xs text-slate-500">{{ resumen.vencidos.lotes }} lote(s)</p>
            </button>
            <button type="button" class="text-left bg-white rounded-xl border-2 p-4 transition-colors" :class="vista === '30' ? 'border-orange-500' : 'border-orange-100 hover:border-orange-300'" @click="elegirVista('30')">
                <p class="text-xs text-orange-700 font-medium"><i class="pi pi-exclamation-triangle text-[10px]"></i> Vencen en los próximos 30 días</p>
                <p class="text-2xl font-semibold" :class="resumen['30'].lotes ? 'text-orange-600' : 'text-slate-400'">{{ soles(resumen['30'].valor) }}</p>
                <p class="text-xs text-slate-500">{{ resumen['30'].lotes }} lote(s)</p>
            </button>
            <button type="button" class="text-left bg-white rounded-xl border-2 p-4 transition-colors" :class="vista === '90' ? 'border-amber-500' : 'border-amber-100 hover:border-amber-300'" @click="elegirVista('90')">
                <p class="text-xs text-amber-700 font-medium"><i class="pi pi-calendar text-[10px]"></i> Vencen en los próximos 90 días</p>
                <p class="text-2xl font-semibold" :class="resumen['90'].lotes ? 'text-amber-600' : 'text-slate-400'">{{ soles(resumen['90'].valor) }}</p>
                <p class="text-xs text-slate-500">{{ resumen['90'].lotes }} lote(s)</p>
            </button>
        </div>

        <div class="bg-white rounded-xl border border-slate-200">
            <div class="p-4 space-y-3 border-b border-slate-100">
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-for="o in opciones"
                        :key="o.value"
                        type="button"
                        class="flex items-center gap-2 px-3 py-1.5 rounded-full border text-sm font-medium transition-colors"
                        :class="vista === o.value ? o.activo : o.inactivo"
                        @click="elegirVista(o.value)"
                    >
                        <i :class="o.icono" class="text-xs"></i>
                        {{ o.label }}
                    </button>
                    <Button
                        v-if="esVencidos && puedeDarBaja"
                        :label="`Dar de baja (${seleccion.length})`"
                        icon="pi pi-trash"
                        severity="danger"
                        class="sm:ml-auto"
                        :disabled="!seleccion.length"
                        @click="abrirBaja"
                    />
                </div>

                <Message v-if="esVencidos" severity="error" size="small">
                    Los lotes vencidos ya no se pueden vender: el sistema no los despacha. Sepáralos en el área de <b>cuarentena</b>,
                    márcalos aquí y pulsa <b>Dar de baja</b>. Se genera un acta para firmar y presentar a DIGEMID.
                </Message>
                <Message v-else severity="warn" size="small">
                    Estos lotes salen primero en cada venta (FEFO). Si no se van a vender a tiempo, ofrécelos en promoción
                    o pide al proveedor un <b>canje o devolución</b> antes de que venzan.
                </Message>
            </div>

            <DataTable
                v-model:selection="seleccion"
                :value="lotes.data"
                dataKey="id"
                lazy
                paginator
                :rows="lotes.per_page"
                :totalRecords="lotes.total"
                :first="(lotes.current_page - 1) * lotes.per_page"
                @page="(e) => ir({ page: e.page + 1 })"
            >
                <template #empty>
                    <div class="py-6 text-center text-slate-500">
                        <i class="pi pi-check-circle text-2xl text-emerald-600 block mb-2"></i>
                        {{ esVencidos ? 'No hay lotes vencidos. ¡Bien!' : 'No hay lotes que venzan en ese plazo.' }}
                    </div>
                </template>

                <Column v-if="esVencidos && puedeDarBaja" selectionMode="multiple" style="width: 3rem" />
                <Column header="Producto">
                    <template #body="{ data }">
                        <p class="font-medium">{{ data.producto.descripcion }}</p>
                        <p class="text-xs text-slate-500">{{ data.producto.codigo }}<span v-if="data.producto.laboratorio"> · {{ data.producto.laboratorio }}</span></p>
                    </template>
                </Column>
                <Column header="Lote">
                    <template #body="{ data }"><span class="font-medium">{{ data.numero_lote }}</span></template>
                </Column>
                <Column header="Vence">
                    <template #body="{ data }">
                        <p>{{ fecha(data.fecha_vencimiento) }}</p>
                        <p class="text-xs" :class="colorDias(data.fecha_vencimiento)">{{ textoDias(data.fecha_vencimiento) }}</p>
                    </template>
                </Column>
                <Column header="Cantidad">
                    <template #body="{ data }"><span class="whitespace-nowrap">{{ textoStock(data.cantidad, data.producto) }}</span></template>
                </Column>
                <Column header="Valor" class="text-right">
                    <template #body="{ data }">{{ soles(data.valor) }}</template>
                </Column>
                <Column header="Proveedor">
                    <template #body="{ data }">
                        <template v-if="data.proveedor">
                            <p class="text-sm">{{ data.proveedor.nombre }}</p>
                            <Link :href="`/compras/${data.proveedor.compra_id}`" class="text-xs text-emerald-700 hover:underline">Compra {{ data.proveedor.compra }}</Link>
                        </template>
                        <span v-else class="text-xs text-slate-400">Inventario inicial / ajuste</span>
                    </template>
                </Column>
                <Column class="text-right">
                    <template #body="{ data }">
                        <Link :href="`/kardex?producto=${data.producto.id}&lote=${data.id}`">
                            <Button icon="pi pi-list" severity="info" size="small" v-tooltip.left="'Kárdex del lote'" />
                        </Link>
                    </template>
                </Column>
            </DataTable>
        </div>

        <!-- Confirmar la baja -->
        <Dialog v-model:visible="dialogo" modal header="Dar de baja productos vencidos" :style="{ width: '34rem' }">
            <div class="space-y-4">
                <p class="text-sm">
                    Se sacará del inventario <b>todo el saldo</b> de {{ seleccion.length }} lote(s) vencido(s), por un valor de
                    <b class="text-red-600">{{ soles(totalSeleccion) }}</b> (a costo, sin IGV).
                </p>
                <ul class="text-xs text-slate-600 max-h-32 overflow-y-auto list-disc pl-5">
                    <li v-for="l in seleccion" :key="l.id">{{ l.producto.descripcion }} · lote {{ l.numero_lote }} · {{ textoStock(l.cantidad, l.producto) }}</li>
                </ul>
                <div class="flex flex-col gap-1">
                    <label class="text-sm">¿Qué se hará con los productos? *</label>
                    <Textarea
                        v-model="form.observacion"
                        rows="3"
                        autoResize
                        maxlength="500"
                        fluid
                        :invalid="!!form.errors.observacion"
                        placeholder="Ej. Separados en cuarentena; se entregarán a empresa autorizada para su destrucción."
                    />
                    <small class="text-red-600">{{ form.errors.observacion || form.errors.lotes }}</small>
                </div>
            </div>
            <template #footer>
                <Button label="Cancelar" severity="secondary" text @click="dialogo = false" />
                <Button label="Dar de baja e ir al acta" icon="pi pi-trash" severity="danger" :loading="form.processing" :disabled="form.observacion.trim().length < 5" @click="darDeBaja" />
            </template>
        </Dialog>
    </AppLayout>
</template>