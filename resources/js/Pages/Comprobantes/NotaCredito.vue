<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Checkbox from 'primevue/checkbox';
import Select from 'primevue/select';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';
import { soles, precio, fecha, cantidad } from '@/utils/formato';

const props = defineProps({
    comprobante: Object,
    pendientes: Object, // id de la línea => cantidad que aún se puede devolver
    motivos: Object,
    motivosTotales: Array,
    serie: String, // FC01 / BC01, o null si no hay serie
    cajaAbierta: Boolean,
    mediosPago: Object,
});

const confirm = useConfirm();
const c = computed(() => props.comprobante);

// Ayuda que se muestra debajo de cada motivo
const AYUDAS = {
    '01': 'La venta no debió hacerse: se acredita el comprobante completo.',
    '02': 'Se emitió con un RUC equivocado: se acredita completo y luego emites una factura nueva al RUC correcto.',
    '06': 'El cliente devuelve toda la mercadería.',
    '07': 'El cliente devuelve solo algunos productos o una parte de las cantidades.',
};

// Si ya hubo devoluciones parciales, solo queda la devolución por ítem
const huboDevoluciones = computed(() => c.value.items.some((i) => Number(props.pendientes[i.id]) < Number(i.cantidad)));
const opcionesMotivo = computed(() =>
    Object.entries(props.motivos).map(([codigo, texto]) => ({
        codigo,
        texto,
        bloqueado: huboDevoluciones.value && props.motivosTotales.includes(codigo),
    })),
);

const form = useForm({
    motivo_codigo: huboDevoluciones.value ? '07' : '01',
    motivo_descripcion: '',
    reingresar_stock: true,
    devolver_dinero: props.comprobante.forma_pago === 'contado',
    medio_devolucion: 'efectivo',
    observaciones: '',
    items: [],
});

const esTotal = computed(() => props.motivosTotales.includes(form.motivo_codigo));

// Cantidad a devolver por línea (solo editable en "Devolución por ítem")
const aDevolver = ref(Object.fromEntries(c.value.items.map((i) => [i.id, 0])));
const cantidadLinea = (i) => (esTotal.value ? Number(props.pendientes[i.id]) : Number(aDevolver.value[i.id]) || 0);
const devolverTodo = (i) => (aDevolver.value[i.id] = Number(props.pendientes[i.id]));

// La descripción sugerida cambia con el motivo (se puede editar)
watch(
    () => form.motivo_codigo,
    (codigo, anterior) => {
        if (!form.motivo_descripcion || form.motivo_descripcion === props.motivos[anterior]?.toUpperCase()) {
            form.motivo_descripcion = props.motivos[codigo].toUpperCase();
        }
    },
    { immediate: true },
);

// Totales referenciales (el servidor recalcula con la misma fórmula)
const totales = computed(() => {
    let total = 0;
    let unidades = 0;
    for (const i of c.value.items) {
        const q = cantidadLinea(i);
        unidades += q;
        if (!i.bonificacion) total += Math.round(q * Number(i.precio_unitario) * 100) / 100;
    }
    return { total: Math.round(total * 100) / 100, unidades };
});

const opcionesMedio = Object.entries(props.mediosPago).map(([value, label]) => ({ value, label }));
const faltaCaja = computed(() => form.devolver_dinero && !props.cajaAbierta);
const puedeEmitir = computed(() => props.serie && totales.value.unidades > 0 && form.motivo_descripcion.trim() && !faltaCaja.value);

const emitir = () =>
    confirm.require({
        header: 'Emitir nota de crédito',
        message:
            `Se emitirá una nota de crédito ${props.serie} por ${soles(totales.value.total)} que modifica ${c.value.tipo_nombre} ${c.value.numero}` +
            (form.reingresar_stock ? ' y la mercadería volverá a sus lotes.' : '. La mercadería NO volverá al stock.') +
            (form.devolver_dinero ? ` Se registrará la devolución de ${soles(totales.value.total)} desde tu caja.` : '') +
            ' Se enviará a SUNAT y no se puede deshacer. ¿Continuar?',
        icon: 'pi pi-exclamation-triangle',
        acceptProps: { label: 'Emitir nota de crédito', severity: 'warn' },
        rejectProps: { label: 'Revisar', severity: 'secondary', outlined: true },
        accept: () =>
            form
                .transform((d) => ({
                    ...d,
                    items: esTotal.value
                        ? []
                        : c.value.items.map((i) => ({ item_id: i.id, cantidad: Number(aDevolver.value[i.id]) || 0 })).filter((l) => l.cantidad > 0),
                }))
                .post(`/comprobantes/${c.value.id}/nota-credito`, { preserveScroll: true }),
    });
</script>

<template>
    <Head :title="`Nota de crédito · ${c.numero}`" />
    <AppLayout :titulo="`Nota de crédito de ${c.tipo_nombre} ${c.numero}`">
        <div class="flex items-center gap-2 mb-4">
            <Link :href="`/comprobantes/${c.id}`"><Button label="Volver al comprobante" icon="pi pi-arrow-left" text /></Link>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <!-- ===== IZQUIERDA: QUÉ SE DEVUELVE ===== -->
            <section class="xl:col-span-2 space-y-6">
                <!-- Comprobante que se modifica -->
                <div class="bg-white rounded-xl border border-slate-200 p-5 grid sm:grid-cols-4 gap-4 text-sm">
                    <div class="sm:col-span-2">
                        <p class="text-xs text-slate-500">Cliente</p>
                        <p class="font-medium">{{ c.cliente.razon_social }}</p>
                        <p class="text-slate-500">{{ c.cliente.tipo_documento === '6' ? 'RUC' : 'Doc.' }} {{ c.cliente.numero_documento }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Comprobante</p>
                        <p class="font-medium">{{ c.tipo_nombre }} {{ c.numero }}</p>
                        <p class="text-slate-500">{{ fecha(c.fecha_emision) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Total</p>
                        <p class="font-semibold text-base">{{ soles(c.total) }}</p>
                    </div>
                    <div v-if="c.notas?.length" class="sm:col-span-4">
                        <p class="text-xs text-slate-500 mb-1">Notas de crédito anteriores</p>
                        <div class="flex flex-wrap gap-2">
                            <Link v-for="n in c.notas" :key="n.id" :href="`/comprobantes/${n.id}`">
                                <Tag :value="`${n.numero} · ${soles(n.total)}`" severity="warn" />
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Líneas -->
                <div class="bg-white rounded-xl border border-slate-200">
                    <div class="p-4 border-b border-slate-100">
                        <h2 class="font-semibold">Productos</h2>
                        <p class="text-sm text-slate-500">
                            {{ esTotal ? 'Se acredita todo lo pendiente de cada línea.' : 'Indica cuánto devuelve el cliente de cada lote.' }}
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                                <tr>
                                    <th class="text-left p-3">Producto</th>
                                    <th class="text-left p-3">Lote / venc.</th>
                                    <th class="text-right p-3">Vendido</th>
                                    <th class="text-right p-3">Pendiente</th>
                                    <th class="text-center p-3 w-48">A devolver</th>
                                    <th class="text-right p-3">Importe</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="i in c.items" :key="i.id" class="border-t border-slate-100 align-middle" :class="{ 'opacity-50': Number(pendientes[i.id]) <= 0 }">
                                    <td class="p-3">
                                        <p class="font-medium">{{ i.descripcion }}</p>
                                        <p class="text-xs text-slate-500">
                                            {{ i.codigo }} · {{ precio(i.precio_unitario) }} / {{ i.unidad }}
                                            <Tag v-if="i.bonificacion" value="Bonificación" severity="success" class="ml-1" />
                                        </p>
                                    </td>
                                    <td class="p-3 whitespace-nowrap">{{ i.numero_lote }} · {{ fecha(i.fecha_vencimiento).substring(3) }}</td>
                                    <td class="p-3 text-right">{{ cantidad(i.cantidad) }} {{ i.unidad }}</td>
                                    <td class="p-3 text-right">{{ cantidad(pendientes[i.id]) }}</td>
                                    <td class="p-3">
                                        <div v-if="esTotal" class="text-center font-semibold">{{ cantidad(pendientes[i.id]) }}</div>
                                        <div v-else class="flex items-center gap-1 justify-center">
                                            <InputNumber
                                                v-model="aDevolver[i.id]"
                                                :min="0"
                                                :max="Number(pendientes[i.id])"
                                                :maxFractionDigits="2"
                                                locale="en-US"
                                                showButtons
                                                buttonLayout="horizontal"
                                                incrementButtonIcon="pi pi-plus"
                                                decrementButtonIcon="pi pi-minus"
                                                inputClass="w-14 text-center"
                                                :disabled="Number(pendientes[i.id]) <= 0"
                                            />
                                            <Button
                                                icon="pi pi-angle-double-right"
                                                text
                                                rounded
                                                size="small"
                                                v-tooltip.top="'Devolver todo lo pendiente'"
                                                :disabled="Number(pendientes[i.id]) <= 0"
                                                @click="devolverTodo(i)"
                                            />
                                        </div>
                                    </td>
                                    <td class="p-3 text-right whitespace-nowrap">
                                        <span v-if="i.bonificacion" class="text-slate-400">Gratis</span>
                                        <span v-else>{{ soles(cantidadLinea(i) * Number(i.precio_unitario)) }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <small v-if="form.errors.items" class="text-red-600 block p-4 pt-0">{{ form.errors.items }}</small>
                </div>
            </section>

            <!-- ===== DERECHA: MOTIVO Y EMISIÓN ===== -->
            <section class="space-y-6">
                <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">Motivo</h2>
                        <Tag v-if="serie" :value="serie" severity="secondary" />
                    </div>
                    <Message v-if="!serie" severity="error" size="small">
                        No hay serie de nota de crédito para este comprobante (ej. {{ c.serie[0] }}C01). Créala en Configuración → Series.
                    </Message>

                    <div class="space-y-2">
                        <label
                            v-for="m in opcionesMotivo"
                            :key="m.codigo"
                            class="flex gap-3 p-3 rounded-lg border cursor-pointer"
                            :class="[
                                form.motivo_codigo === m.codigo ? 'border-amber-400 bg-amber-50' : 'border-slate-200',
                                m.bloqueado ? 'opacity-40 cursor-not-allowed' : '',
                            ]"
                        >
                            <input v-model="form.motivo_codigo" type="radio" :value="m.codigo" :disabled="m.bloqueado" class="mt-1 accent-amber-500" />
                            <span>
                                <span class="font-medium">{{ m.codigo }} · {{ m.texto }}</span>
                                <span class="block text-xs text-slate-500">{{ AYUDAS[m.codigo] }}</span>
                            </span>
                        </label>
                        <small v-if="huboDevoluciones" class="text-slate-500 block">
                            Ya hubo devoluciones de este comprobante: solo queda la devolución por ítem.
                        </small>
                        <small class="text-red-600 block">{{ form.errors.motivo_codigo }}</small>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Descripción (sale en la nota) *</label>
                        <InputText v-model="form.motivo_descripcion" maxlength="250" fluid :invalid="!!form.errors.motivo_descripcion" />
                        <small class="text-red-600">{{ form.errors.motivo_descripcion }}</small>
                    </div>

                    <label class="flex items-start gap-2 text-sm">
                        <Checkbox v-model="form.reingresar_stock" binary />
                        <span>
                            Devolver la mercadería al stock (a su mismo lote)
                            <span class="block text-xs text-slate-500">Desmárcalo si llegó dañada, vencida o no se puede volver a vender.</span>
                        </span>
                    </label>

                                        <!-- Devolución del dinero: sale de la caja de quien emite la nota -->
                    <div class="space-y-2">
                        <label class="flex items-start gap-2 text-sm">
                            <Checkbox v-model="form.devolver_dinero" binary />
                            <span>
                                Devolver el dinero al cliente
                                <span class="block text-xs text-slate-500">Se registra como egreso en tu caja. Desmárcalo si quedará como saldo a favor o si la venta fue al crédito.</span>
                            </span>
                        </label>
                        <Select v-if="form.devolver_dinero" v-model="form.medio_devolucion" :options="opcionesMedio" optionLabel="label" optionValue="value" fluid />
                        <Message v-if="faltaCaja" severity="warn" size="small">
                            Para devolver dinero necesitas tu caja abierta. <Link href="/caja" class="font-semibold underline">Abrir caja</Link>
                        </Message>
                        <small class="text-red-600 block">{{ form.errors.devolver_dinero }}</small>
                    </div>

                    <Textarea v-model="form.observaciones" rows="2" autoResize placeholder="Observaciones internas (opcional)" fluid />
                </div>

                <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-3 text-sm">
                    <div class="flex justify-between text-xl font-semibold"><span>Total a acreditar</span><span>{{ soles(totales.total) }}</span></div>
                    <p class="text-xs text-slate-500">Monto referencial; el sistema lo recalcula al emitir.</p>
                    <Button
                        label="Emitir nota de crédito"
                        icon="pi pi-file-edit"
                        severity="warn"
                        size="large"
                        class="w-full"
                        :loading="form.processing"
                        :disabled="!puedeEmitir"
                        @click="emitir"
                    />
                    <p v-if="form.processing" class="text-xs text-center text-slate-500">Registrando y enviando a SUNAT...</p>
                </div>
            </section>
        </div>
    </AppLayout>
</template>