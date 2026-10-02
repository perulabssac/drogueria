<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import AutoComplete from 'primevue/autocomplete';
import SelectButton from 'primevue/selectbutton';
import DatePicker from 'primevue/datepicker';
import ToggleSwitch from 'primevue/toggleswitch';
import Dialog from 'primevue/dialog';
import Tag from 'primevue/tag';
import Message from 'primevue/message';
import ProgressBar from 'primevue/progressbar';
import { useConfirm } from 'primevue/useconfirm';
import { soles, fecha, stock as textoStock } from '@/utils/formato';
import { obtenerJson, aFechaISO } from '@/utils/http';

const props = defineProps({
    toma: Object,
    items: Array,
    puedeContar: Boolean,
    puedeAprobar: Boolean,
});

const confirm = useConfirm();
const enConteo = computed(() => props.toma.estado === 'en_conteo');

const ESTADOS = {
    en_conteo: { texto: 'En conteo', severidad: 'warn' },
    aprobada: { texto: 'Aprobada', severidad: 'success' },
    anulada: { texto: 'Anulada', severidad: 'secondary' },
};

// Mientras se cuenta, el stock del sistema se oculta para no influir en el conteo
const mostrarSistema = ref(!enConteo.value);

// ================= CONTEO (cajas + unidades sueltas) =================
const factor = (p) => (p.fraccionable ? Number(p.unidades_por_presentacion) : 1);
const conteo = reactive({});
// Al recargar la página (ej. tras agregar un lote) no se pierde lo que aún no se guardó
const cargar = () => {
    for (const it of props.items) {
        if (conteo[it.id]) continue;
        const f = factor(it.producto);
        conteo[it.id] =
            it.contado === null
                ? { enteras: null, sueltas: null }
                : { enteras: Math.floor(it.contado / f), sueltas: Math.round((it.contado - Math.floor(it.contado / f) * f) * 100) / 100 };
    }
};
cargar();
watch(() => props.items, cargar);

const contado = (it) => {
    const c = conteo[it.id];
    if (!c || (c.enteras === null && c.sueltas === null)) return null;
    return (Number(c.enteras) || 0) * factor(it.producto) + (Number(c.sueltas) || 0);
};
const cambiado = (it) => contado(it) !== it.contado;
const pendientesDeGuardar = computed(() => props.items.filter(cambiado).length);

const diferencia = (it) => (contado(it) === null ? null : Math.round((contado(it) - it.stock_sistema) * 100) / 100);
const valorDiferencia = (it) => (diferencia(it) === null ? 0 : Math.round((diferencia(it) / factor(it.producto)) * it.costo_unitario * 100) / 100);

// ================= RESUMEN Y FILTROS =================
const contados = computed(() => props.items.filter((it) => contado(it) !== null).length);
const avance = computed(() => (props.items.length ? Math.round((contados.value / props.items.length) * 100) : 0));
const faltantes = computed(() => props.items.filter((it) => diferencia(it) < 0));
const sobrantes = computed(() => props.items.filter((it) => diferencia(it) > 0));
const valorFaltantes = computed(() => faltantes.value.reduce((s, it) => s + valorDiferencia(it), 0));
const valorSobrantes = computed(() => sobrantes.value.reduce((s, it) => s + valorDiferencia(it), 0));

const buscar = ref('');
const filtro = ref('todos');
const FILTROS = computed(() => [
    { value: 'todos', label: `Todos (${props.items.length})`, activo: 'bg-slate-700 border-slate-700 text-white', inactivo: 'bg-slate-50 border-slate-300 text-slate-700 hover:bg-slate-100' },
    { value: 'sin_contar', label: `Sin contar (${props.items.length - contados.value})`, activo: 'bg-amber-500 border-amber-500 text-white', inactivo: 'bg-amber-50 border-amber-200 text-amber-800 hover:bg-amber-100' },
    ...(mostrarSistema.value
        ? [
              { value: 'faltantes', label: `Faltan (${faltantes.value.length})`, activo: 'bg-red-600 border-red-600 text-white', inactivo: 'bg-red-50 border-red-200 text-red-800 hover:bg-red-100' },
              { value: 'sobrantes', label: `Sobran (${sobrantes.value.length})`, activo: 'bg-emerald-600 border-emerald-600 text-white', inactivo: 'bg-emerald-50 border-emerald-200 text-emerald-800 hover:bg-emerald-100' },
          ]
        : []),
]);
watch(mostrarSistema, (v) => {
    if (!v && ['faltantes', 'sobrantes'].includes(filtro.value)) filtro.value = 'todos';
});

const visibles = computed(() => {
    const q = buscar.value.trim().toLowerCase();
    return props.items.filter((it) => {
        if (q && !`${it.producto.descripcion} ${it.producto.codigo} ${it.numero_lote}`.toLowerCase().includes(q)) return false;
        if (filtro.value === 'sin_contar') return contado(it) === null;
        if (filtro.value === 'faltantes') return diferencia(it) < 0;
        if (filtro.value === 'sobrantes') return diferencia(it) > 0;
        return true;
    });
});

// ================= ACCIONES =================
const guardando = ref(false);
const guardar = (despues = null) =>
    router.put(
        `/tomas/${props.toma.id}/conteo`,
        { conteos: props.items.map((it) => ({ id: it.id, contado: contado(it) })) },
        {
            preserveScroll: true,
            onStart: () => (guardando.value = true),
            onFinish: () => (guardando.value = false),
            onSuccess: () => despues?.(),
        },
    );

const aprobar = () =>
    confirm.require({
        header: `Aprobar la toma ${props.toma.numero}`,
        message:
            `Faltantes: ${faltantes.value.length} lote(s) por ${soles(-valorFaltantes.value)}. ` +
            `Sobrantes: ${sobrantes.value.length} lote(s) por ${soles(valorSobrantes.value)}. ` +
            'Se crearán los ajustes y el stock quedará igual a lo contado. Esto no se puede deshacer. ¿Aprobar?',
        icon: 'pi pi-check-circle',
        acceptProps: { label: 'Aprobar y ajustar stock', severity: 'success' },
        rejectProps: { label: 'Revisar', severity: 'secondary', outlined: true },
        accept: () => router.post(`/tomas/${props.toma.id}/aprobar`, {}, { preserveScroll: true, onSuccess: () => (mostrarSistema.value = true) }),
    });

const anular = () =>
    confirm.require({
        header: `Anular la toma ${props.toma.numero}`,
        message: 'Se descarta el conteo y el stock no cambia. ¿Anular?',
        icon: 'pi pi-times-circle',
        acceptProps: { label: 'Anular toma', severity: 'danger' },
        rejectProps: { label: 'Volver', severity: 'secondary', outlined: true },
        accept: () => router.post(`/tomas/${props.toma.id}/anular`),
    });

// ================= LOTE ENCONTRADO (no figuraba en el sistema) =================
const dialogoLote = ref(false);
const sugerencias = ref([]);
const buscarProductos = async (e) => {
    sugerencias.value = await obtenerJson('/api/productos/buscar', { q: e.query });
};
const formLote = useForm({ producto: null, numero_lote: '', fecha_vencimiento: null, cantidad: 1, por_fraccion: false, costo_unitario: null });
const abrirLote = () => {
    formLote.reset();
    formLote.clearErrors();
    dialogoLote.value = true;
};
const elegirProducto = (e) => (formLote.costo_unitario = e.value.costo);
const agregarLote = () =>
    formLote
        .transform((d) => ({
            producto_id: d.producto?.id,
            numero_lote: d.numero_lote,
            fecha_vencimiento: aFechaISO(d.fecha_vencimiento),
            contado: (Number(d.cantidad) || 0) * (d.por_fraccion || !d.producto ? 1 : factor(d.producto)),
            costo_unitario: d.costo_unitario,
        }))
        .post(`/tomas/${props.toma.id}/lotes`, { preserveScroll: true, onSuccess: () => (dialogoLote.value = false) });
</script>

<template>
    <Head :title="`Toma ${toma.numero}`" />
    <AppLayout :titulo="`Toma de inventario ${toma.numero}`">
        <!-- Cabecera -->
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <Link href="/tomas"><Button label="Volver" icon="pi pi-arrow-left" text /></Link>
            <Tag :value="ESTADOS[toma.estado].texto" :severity="ESTADOS[toma.estado].severidad" />
            <span class="text-sm text-slate-600">{{ toma.alcance_texto }} · abierta el {{ fecha(toma.created_at) }} por {{ toma.usuario }}</span>
            <div class="ml-auto flex flex-wrap gap-2">
                <a :href="`/tomas/${toma.id}/hoja`" target="_blank">
                    <Button label="Hoja para contar" icon="pi pi-print" severity="help" outlined />
                </a>
                <template v-if="puedeContar">
                    <Button label="Lote encontrado" icon="pi pi-plus" severity="warn" outlined @click="abrirLote" />
                    <Button label="Anular" icon="pi pi-times" severity="danger" outlined @click="anular" />
                    <Button
                        :label="pendientesDeGuardar ? `Guardar conteo (${pendientesDeGuardar})` : 'Conteo guardado'"
                        icon="pi pi-save"
                        :loading="guardando"
                        :disabled="!pendientesDeGuardar"
                        @click="guardar()"
                    />
                </template>
                <Button
                    v-if="puedeAprobar"
                    label="Aprobar toma"
                    icon="pi pi-check-circle"
                    severity="success"
                    :disabled="contados < items.length || pendientesDeGuardar > 0"
                    v-tooltip.bottom="pendientesDeGuardar ? 'Guarda el conteo primero' : contados < items.length ? 'Faltan lotes por contar' : null"
                    @click="aprobar"
                />
            </div>
        </div>

        <!-- Errores al aprobar o guardar (ej. "Faltan contar 3 lotes") -->
        <Message v-if="$page.props.errors.toma || $page.props.errors.items" severity="error" class="mb-4">
            {{ $page.props.errors.toma || $page.props.errors.items }}
        </Message>

        <!-- Resultado de una toma aprobada -->
        <Message v-if="toma.estado === 'aprobada'" severity="success" class="mb-4">
            Aprobada el {{ fecha(toma.aprobada_at) }} por {{ toma.aprobador }}. El stock quedó igual al conteo.
            <Link v-if="toma.ajuste_salida" :href="`/ajustes/${toma.ajuste_salida.id}`" class="font-semibold underline ml-1">
                Acta de faltantes {{ toma.ajuste_salida.numero }}
            </Link>
            <Link v-if="toma.ajuste_entrada" :href="`/ajustes/${toma.ajuste_entrada.id}`" class="font-semibold underline ml-2">
                Acta de sobrantes {{ toma.ajuste_entrada.numero }}
            </Link>
            <span v-if="!toma.ajuste_salida && !toma.ajuste_entrada" class="ml-1">No hubo diferencias.</span>
        </Message>
        <Message v-else-if="toma.estado === 'anulada'" severity="secondary" class="mb-4">Esta toma fue anulada: no cambió el stock.</Message>

        <!-- Resumen -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-xs text-slate-500">Avance del conteo</p>
                <p class="text-2xl font-semibold">{{ contados }} <span class="text-base text-slate-400">de {{ items.length }} lotes</span></p>
                <ProgressBar :value="avance" :showValue="false" style="height: 6px" class="mt-2" />
            </div>
            <template v-if="mostrarSistema">
                <div class="bg-white rounded-xl border border-slate-200 p-4">
                    <p class="text-xs text-red-700">Faltantes ({{ faltantes.length }} lotes)</p>
                    <p class="text-2xl font-semibold" :class="faltantes.length ? 'text-red-600' : 'text-slate-400'">{{ soles(-valorFaltantes) }}</p>
                </div>
                <div class="bg-white rounded-xl border border-slate-200 p-4">
                    <p class="text-xs text-emerald-700">Sobrantes ({{ sobrantes.length }} lotes)</p>
                    <p class="text-2xl font-semibold" :class="sobrantes.length ? 'text-emerald-700' : 'text-slate-400'">{{ soles(valorSobrantes) }}</p>
                </div>
            </template>
            <div v-else class="sm:col-span-2 bg-amber-50 rounded-xl border border-amber-200 p-4 text-sm text-amber-900">
                <i class="pi pi-eye-slash mr-1"></i>
                El stock del sistema está <b>oculto</b> para que el conteo sea a ciegas. Cuenta lo que hay en el estante,
                y guarda. Al terminar, el administrador activa "Mostrar stock del sistema" para ver las diferencias y aprobar.
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200">
            <div class="p-4 flex flex-wrap items-center gap-3 border-b border-slate-100">
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="f in FILTROS"
                        :key="f.value"
                        type="button"
                        class="px-3 py-1.5 rounded-full border text-sm font-medium transition-colors"
                        :class="filtro === f.value ? f.activo : f.inactivo"
                        @click="filtro = f.value"
                    >
                        {{ f.label }}
                    </button>
                </div>
                <IconField class="w-full sm:w-64">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="buscar" placeholder="Producto, código o lote" fluid />
                </IconField>
                <!-- Durante el conteo, solo el administrador puede ver el stock del sistema -->
                <label v-if="puedeAprobar || !enConteo" class="flex items-center gap-2 text-sm sm:ml-auto">
                    <ToggleSwitch v-model="mostrarSistema" />
                    Mostrar stock del sistema
                </label>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                        <tr>
                            <th class="text-left p-3">Producto</th>
                            <th class="text-left p-3">Lote</th>
                            <th v-if="mostrarSistema" class="text-right p-3">Sistema</th>
                            <th class="text-left p-3">Contado</th>
                            <th v-if="mostrarSistema" class="text-right p-3">Diferencia</th>
                            <th v-if="mostrarSistema" class="text-right p-3">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!visibles.length">
                            <td colspan="6" class="p-8 text-center text-slate-400">No hay lotes con ese filtro.</td>
                        </tr>
                        <tr
                            v-for="it in visibles"
                            :key="it.id"
                            class="border-t border-slate-100 align-top"
                            :class="cambiado(it) ? 'bg-amber-50/60' : ''"
                        >
                            <td class="p-3">
                                <p class="font-medium">{{ it.producto.descripcion }}</p>
                                <p class="text-xs text-slate-500">{{ it.producto.codigo }}<span v-if="it.producto.laboratorio"> · {{ it.producto.laboratorio }}</span></p>
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                <span class="font-medium">{{ it.numero_lote }}</span>
                                <Tag v-if="it.nuevo" value="Encontrado" severity="warn" class="ml-1" />
                                <p class="text-xs text-slate-500">Vence {{ fecha(it.fecha_vencimiento) }}</p>
                            </td>
                            <td v-if="mostrarSistema" class="p-3 text-right whitespace-nowrap text-slate-600">{{ textoStock(it.stock_sistema, it.producto) }}</td>
                            <td class="p-3">
                                <div class="flex items-center gap-2">
                                    <InputNumber
                                        v-model="conteo[it.id].enteras"
                                        :min="0"
                                        :disabled="!puedeContar"
                                        :suffix="` ${it.producto.unidad_venta}`"
                                        :placeholder="it.producto.unidad_venta"
                                        inputClass="w-28 text-right"
                                    />
                                    <InputNumber
                                        v-if="it.producto.fraccionable"
                                        v-model="conteo[it.id].sueltas"
                                        :min="0"
                                        :max="factor(it.producto) - 1"
                                        :disabled="!puedeContar"
                                        :suffix="` ${it.producto.unidad_fraccion}`"
                                        :placeholder="it.producto.unidad_fraccion"
                                        inputClass="w-28 text-right"
                                    />
                                </div>
                            </td>
                            <td v-if="mostrarSistema" class="p-3 text-right whitespace-nowrap font-semibold">
                                <span v-if="diferencia(it) === null" class="text-slate-300">—</span>
                                <span v-else-if="diferencia(it) === 0" class="text-emerald-600"><i class="pi pi-check"></i></span>
                                <span v-else :class="diferencia(it) < 0 ? 'text-red-600' : 'text-emerald-700'">
                                    {{ diferencia(it) > 0 ? '+' : '−' }} {{ textoStock(Math.abs(diferencia(it)), it.producto) }}
                                </span>
                            </td>
                            <td v-if="mostrarSistema" class="p-3 text-right whitespace-nowrap" :class="valorDiferencia(it) < 0 ? 'text-red-600' : valorDiferencia(it) > 0 ? 'text-emerald-700' : 'text-slate-300'">
                                {{ valorDiferencia(it) ? soles(valorDiferencia(it)) : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Lote encontrado -->
        <Dialog v-model:visible="dialogoLote" modal header="Agregar lote encontrado" :style="{ width: '34rem' }">
            <p class="text-sm text-slate-600 mb-4">Para un lote que está en el almacén pero no aparece en la lista (no figuraba en el sistema).</p>
            <div class="space-y-3">
                <div class="flex flex-col gap-1">
                    <label class="text-sm">Producto *</label>
                    <AutoComplete
                        v-model="formLote.producto"
                        :suggestions="sugerencias"
                        optionLabel="descripcion"
                        forceSelection
                        fluid
                        placeholder="Busca el producto"
                        @complete="buscarProductos"
                        @option-select="elegirProducto"
                    />
                    <small class="text-red-600">{{ formLote.errors.producto_id }}</small>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Lote *</label>
                        <InputText v-model="formLote.numero_lote" :invalid="!!formLote.errors.numero_lote" @blur="formLote.numero_lote = formLote.numero_lote.toUpperCase().trim()" />
                        <small class="text-red-600">{{ formLote.errors.numero_lote }}</small>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Vence *</label>
                        <DatePicker v-model="formLote.fecha_vencimiento" dateFormat="dd/mm/yy" fluid :invalid="!!formLote.errors.fecha_vencimiento" />
                        <small class="text-red-600">{{ formLote.errors.fecha_vencimiento }}</small>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Cantidad contada *</label>
                        <div class="flex gap-2">
                            <InputNumber v-model="formLote.cantidad" :min="0" :maxFractionDigits="2" inputClass="w-20" />
                            <SelectButton
                                v-if="formLote.producto?.fraccionable"
                                v-model="formLote.por_fraccion"
                                :options="[
                                    { value: false, label: formLote.producto.unidad_venta },
                                    { value: true, label: formLote.producto.unidad_fraccion },
                                ]"
                                optionLabel="label"
                                optionValue="value"
                                :allowEmpty="false"
                                size="small"
                            />
                            <span v-else-if="formLote.producto" class="self-center text-sm text-slate-600">{{ formLote.producto.unidad_venta }}</span>
                        </div>
                        <small class="text-red-600">{{ formLote.errors.contado }}</small>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Costo x presentación (sin IGV)</label>
                        <InputNumber v-model="formLote.costo_unitario" prefix="S/ " locale="en-US" :minFractionDigits="2" :maxFractionDigits="4" :min="0" fluid />
                    </div>
                </div>
            </div>
            <template #footer>
                <Button label="Cancelar" severity="secondary" text @click="dialogoLote = false" />
                <Button
                    label="Agregar a la toma"
                    icon="pi pi-plus"
                    severity="warn"
                    :loading="formLote.processing"
                    :disabled="!formLote.producto || !formLote.numero_lote || !formLote.fecha_vencimiento || !(formLote.cantidad > 0)"
                    @click="agregarLote"
                />
            </template>
        </Dialog>
    </AppLayout>
</template>