<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import DatePicker from 'primevue/datepicker';
import SelectButton from 'primevue/selectbutton';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { soles, fecha } from '@/utils/formato';
import { estadoSunat } from '@/utils/sunat';

const props = defineProps({
    periodo: String, // "AAAA-MM"
    ventas: Array,
    compras: Array,
    resumen: Object,
});

// Selector de mes: al cambiarlo se recargan los datos
const mes = ref(new Date(props.periodo + '-01T00:00:00'));
const aPeriodo = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
watch(mes, (d) => d && router.get('/reportes', { periodo: aPeriodo(d) }, { preserveState: true, replace: true }));

const nombreMes = computed(() => {
    const texto = mes.value?.toLocaleDateString('es-PE', { month: 'long', year: 'numeric' }) ?? '';
    return texto.charAt(0).toUpperCase() + texto.slice(1);
});
const vista = ref('ventas');
const r = computed(() => props.resumen);
const TIPO_DOC = { 0: 'Sin doc.', 1: 'DNI', 4: 'C.E.', 6: 'RUC', 7: 'Pasaporte' };

// Descargas (enlaces normales: el navegador baja el archivo)
const url = (ruta) => `${ruta}?periodo=${props.periodo}`;
</script>

<template>
    <Head title="Reportes contables" />
    <AppLayout titulo="Reportes contables">
        <!-- Periodo y descargas -->
        <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6 flex flex-wrap items-center gap-3">
            <div class="flex items-center gap-2">
                <span class="text-sm font-medium">Periodo</span>
                <DatePicker v-model="mes" view="month" dateFormat="mm/yy" showIcon class="w-40" />
                <span class="text-sm text-slate-500">{{ nombreMes }}</span>
            </div>
            <div class="sm:ml-auto flex flex-wrap gap-2">
                <a :href="url('/reportes/ventas.xlsx')"><Button label="Registro de ventas" icon="pi pi-file-excel" severity="success" /></a>
                <a :href="url('/reportes/compras.xlsx')"><Button label="Registro de compras" icon="pi pi-file-excel" severity="help" /></a>
                <a :href="url('/reportes/xml.zip')"><Button label="XML y CDR (ZIP)" icon="pi pi-download" severity="info" /></a>
            </div>
        </div>

        <!-- Avisos para el contador -->
        <Message v-if="r.pendientes_sunat" severity="warn" class="mb-4">
            Hay <b>{{ r.pendientes_sunat }}</b> comprobante(s) del mes que aún no tienen respuesta de SUNAT. Verifícalos antes de declarar.
        </Message>
        <Message v-if="r.rechazados" severity="error" class="mb-4">
            <b>{{ r.rechazados }}</b> comprobante(s) rechazado(s) por SUNAT: figuran en el registro con montos en cero.
        </Message>

        <!-- Resumen del mes -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <p class="text-xs text-slate-500 uppercase">Ventas del mes</p>
                <p class="text-2xl font-semibold mt-1">{{ soles(r.ventas_total) }}</p>
                <p class="text-xs text-slate-500 mt-1">
                    Base {{ soles(r.ventas_gravadas) }} · Exon./inaf. {{ soles(r.ventas_exoneradas) }} · {{ r.cantidad_ventas }} comprobantes
                </p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <p class="text-xs text-slate-500 uppercase">IGV de ventas</p>
                <p class="text-2xl font-semibold mt-1">{{ soles(r.igv_ventas) }}</p>
                <p class="text-xs text-slate-500 mt-1">Ya descontadas las notas de crédito</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <p class="text-xs text-slate-500 uppercase">IGV de compras (crédito fiscal)</p>
                <p class="text-2xl font-semibold mt-1">{{ soles(r.igv_compras) }}</p>
                <p class="text-xs text-slate-500 mt-1">Compras {{ soles(r.compras_total) }} · {{ r.cantidad_compras }} documentos</p>
            </div>
            <div class="rounded-xl border p-5" :class="r.igv_resultado >= 0 ? 'bg-amber-50 border-amber-200' : 'bg-emerald-50 border-emerald-200'">
                <p class="text-xs uppercase" :class="r.igv_resultado >= 0 ? 'text-amber-700' : 'text-emerald-700'">
                    {{ r.igv_resultado >= 0 ? 'IGV estimado a pagar' : 'Saldo a favor (estimado)' }}
                </p>
                <p class="text-2xl font-bold mt-1" :class="r.igv_resultado >= 0 ? 'text-amber-700' : 'text-emerald-700'">{{ soles(Math.abs(r.igv_resultado)) }}</p>
                <p class="text-xs text-slate-500 mt-1">Referencial: el contador confirma con el SIRE</p>
            </div>
        </div>

        <p v-if="r.notas_venta > 0" class="text-xs text-slate-500 mb-4">
            Notas de venta (internas) del mes: {{ soles(r.notas_venta) }}. No son comprobantes de pago y no están en el registro de ventas.
        </p>

        <!-- Detalle -->
        <div class="bg-white rounded-xl border border-slate-200">
            <div class="p-4 border-b border-slate-100">
                <SelectButton
                    v-model="vista"
                    :options="[{ value: 'ventas', label: `Ventas (${ventas.length})` }, { value: 'compras', label: `Compras (${compras.length})` }]"
                    optionLabel="label"
                    optionValue="value"
                    :allowEmpty="false"
                />
            </div>

            <!-- Registro de ventas -->
            <DataTable v-if="vista === 'ventas'" :value="ventas" size="small" paginator :rows="25" :rowClass="(v) => (v.valido ? '' : 'opacity-50 line-through')">
                <template #empty>No hay ventas en este periodo.</template>
                <Column header="Fecha"><template #body="{ data }">{{ fecha(data.fecha) }}</template></Column>
                <Column header="Comprobante">
                    <template #body="{ data }">
                        <Link :href="`/comprobantes/${data.id}`" class="font-medium text-emerald-700 hover:underline">{{ data.serie }}-{{ data.numero }}</Link>
                        <p class="text-xs text-slate-500">{{ data.tipo_nombre }}<span v-if="data.referencia"> · modifica {{ data.referencia }}</span></p>
                    </template>
                </Column>
                <Column header="Cliente">
                    <template #body="{ data }">
                        <p>{{ data.cliente }}</p>
                        <p class="text-xs text-slate-500">{{ TIPO_DOC[data.cliente_tipo_doc] ?? 'Doc.' }} {{ data.cliente_doc }}</p>
                    </template>
                </Column>
                <Column header="Base" class="text-right"><template #body="{ data }">{{ soles(data.gravado) }}</template></Column>
                <Column header="Exon./Inaf." class="text-right"><template #body="{ data }">{{ soles(data.exonerado + data.inafecto) }}</template></Column>
                <Column header="IGV" class="text-right"><template #body="{ data }">{{ soles(data.igv) }}</template></Column>
                <Column header="Total" class="text-right"><template #body="{ data }"><span class="font-medium">{{ soles(data.total) }}</span></template></Column>
                <Column header="SUNAT">
                    <template #body="{ data }"><Tag :value="estadoSunat(data.estado).texto" :severity="estadoSunat(data.estado).severidad" /></template>
                </Column>
            </DataTable>

            <!-- Registro de compras -->
            <DataTable v-else :value="compras" size="small" paginator :rows="25" :rowClass="(c) => (c.valida ? '' : 'opacity-50 line-through')">
                <template #empty>No hay compras en este periodo.</template>
                <Column header="Fecha"><template #body="{ data }">{{ fecha(data.fecha) }}</template></Column>
                <Column header="Documento">
                    <template #body="{ data }">
                        <Link :href="`/compras/${data.id}`" class="font-medium text-emerald-700 hover:underline">{{ data.serie }}-{{ data.numero }}</Link>
                        <p class="text-xs text-slate-500">{{ data.tipo_nombre }}</p>
                    </template>
                </Column>
                <Column header="Proveedor">
                    <template #body="{ data }">
                        <p>{{ data.proveedor }}</p>
                        <p class="text-xs text-slate-500">RUC {{ data.proveedor_ruc }}</p>
                    </template>
                </Column>
                <Column header="Base" class="text-right"><template #body="{ data }">{{ soles(data.gravado) }}</template></Column>
                <Column header="Exonerado" class="text-right"><template #body="{ data }">{{ soles(data.exonerado) }}</template></Column>
                <Column header="IGV" class="text-right"><template #body="{ data }">{{ soles(data.igv) }}</template></Column>
                <Column header="Total" class="text-right"><template #body="{ data }"><span class="font-medium">{{ soles(data.total) }}</span></template></Column>
                <Column header="Estado">
                    <template #body="{ data }"><Tag :value="data.valida ? 'Registrada' : 'Anulada'" :severity="data.valida ? 'success' : 'danger'" /></template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>