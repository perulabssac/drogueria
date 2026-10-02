<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AutoComplete from 'primevue/autocomplete';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import DatePicker from 'primevue/datepicker';
import Textarea from 'primevue/textarea';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';
import { soles, precio, fecha, stock as textoStock } from '@/utils/formato';
import { obtenerJson, aFechaISO } from '@/utils/http';

const props = defineProps({ motivos: Object });
const confirm = useConfirm();

const form = useForm({ tipo: 'salida', motivo: null, observacion: '', items: [] });

const esSalida = computed(() => form.tipo === 'salida');
const opcionesMotivo = computed(() => Object.entries(props.motivos[form.tipo]).map(([value, label]) => ({ value, label })));

// Al cambiar entre salida y entrada, los lotes se eligen distinto: se empieza de nuevo
const cambiarTipo = (t) => {
    if (form.tipo === t) return;
    form.tipo = t;
    form.motivo = null;
    form.items = [];
    form.clearErrors();
};

// ================= PRODUCTOS =================
const productoBuscado = ref('');
const sugerencias = ref([]);
const buscarProductos = async (e) => {
    sugerencias.value = await obtenerJson('/api/productos/buscar', { q: e.query });
};

const agregarProducto = async (e) => {
    const p = e.value;
    productoBuscado.value = '';
    const lotes = await obtenerJson('/api/ajustes/lotes', { producto: p.id });
    form.items.push({
        producto: p,
        lotes,
        por_fraccion: false,
        cantidad: 1,
        // Salida: lote del que se saca (por defecto, el que vence primero)
        lote_id: lotes[0]?.id ?? null,
        // Entrada: "nuevo" o el id de un lote existente
        lote_sel: 'nuevo',
        numero_lote: '',
        fecha_vencimiento: null,
        costo_unitario: p.costo,
    });
};
const quitar = (i) => form.items.splice(i, 1);

const factor = (p) => (p.fraccionable ? Number(p.unidades_por_presentacion) : 1);
const opcionesUnidad = (p) => [
    { value: false, label: p.unidad_venta },
    { value: true, label: p.unidad_fraccion },
];
const unidades = (l) => (Number(l.cantidad) || 0) * (l.por_fraccion ? 1 : factor(l.producto));

const opcionesLoteSalida = (l) =>
    l.lotes.map((x) => ({
        value: x.id,
        label: `${x.numero_lote} · vence ${fecha(x.fecha_vencimiento)} · ${textoStock(x.cantidad, l.producto)}${x.vencido ? ' · VENCIDO' : ''}`,
    }));
const opcionesLoteEntrada = (l) => [
    { value: 'nuevo', label: '+ Lote nuevo' },
    ...l.lotes.map((x) => ({ value: x.id, label: `${x.numero_lote} · vence ${fecha(x.fecha_vencimiento)} · ${textoStock(x.cantidad, l.producto)}` })),
];

const loteSalida = (l) => l.lotes.find((x) => x.id === l.lote_id);
const loteEntrada = (l) => (l.lote_sel === 'nuevo' ? null : l.lotes.find((x) => x.id === l.lote_sel));
const excede = (l) => esSalida.value && loteSalida(l) && unidades(l) > loteSalida(l).cantidad + 0.0001;

const costo = (l) => {
    if (esSalida.value) return loteSalida(l)?.costo_unitario ?? 0;
    return loteEntrada(l)?.costo_unitario ?? (Number(l.costo_unitario) || 0);
};
const valor = (l) => Math.round((unidades(l) / factor(l.producto)) * costo(l) * 100) / 100;
const total = computed(() => Math.round(form.items.reduce((s, l) => s + valor(l), 0) * 100) / 100);

const lineaLista = (l) => {
    if (!(Number(l.cantidad) > 0)) return false;
    if (esSalida.value) return !!l.lote_id && !excede(l);
    return l.lote_sel !== 'nuevo' || (l.numero_lote.trim() && l.fecha_vencimiento);
};
const puedeGuardar = computed(
    () => form.motivo && form.observacion.trim().length >= 5 && form.items.length > 0 && form.items.every(lineaLista),
);

const error = (i, campo) => form.errors[`items.${i}.${campo}`];

// ================= GUARDAR =================
const guardar = () => {
    const motivo = opcionesMotivo.value.find((m) => m.value === form.motivo)?.label;
    confirm.require({
        header: esSalida.value ? 'Registrar salida de inventario' : 'Registrar entrada de inventario',
        message:
            `Se registrará una ${esSalida.value ? 'SALIDA' : 'ENTRADA'} de ${form.items.length} producto(s) por ${soles(total.value)} (${motivo}). ` +
            'El stock cambia de inmediato y el ajuste no se puede anular: un error se corrige con otro ajuste. ¿Continuar?',
        icon: esSalida.value ? 'pi pi-minus-circle' : 'pi pi-plus-circle',
        acceptProps: { label: 'Registrar ajuste', severity: esSalida.value ? 'danger' : 'success' },
        rejectProps: { label: 'Revisar', severity: 'secondary', outlined: true },
        accept: () =>
            form
                .transform((d) => ({
                    tipo: d.tipo,
                    motivo: d.motivo,
                    observacion: d.observacion,
                    items: d.items.map((l) => {
                        const existente = loteEntrada(l);
                        return {
                            producto_id: l.producto.id,
                            cantidad: l.cantidad,
                            por_fraccion: l.por_fraccion,
                            lote_id: esSalida.value ? l.lote_id : null,
                            numero_lote: esSalida.value ? null : existente ? existente.numero_lote : l.numero_lote,
                            fecha_vencimiento: esSalida.value ? null : existente ? existente.fecha_vencimiento : aFechaISO(l.fecha_vencimiento),
                            costo_unitario: !esSalida.value && !existente ? l.costo_unitario : null,
                        };
                    }),
                }))
                .post('/ajustes', { preserveScroll: true }),
    });
};
</script>

<template>
    <Head title="Nuevo ajuste" />
    <AppLayout titulo="Nuevo ajuste de inventario">
        <div class="mb-4">
            <Link href="/ajustes"><Button label="Volver" icon="pi pi-arrow-left" text /></Link>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <!-- ===== IZQUIERDA: PRODUCTOS ===== -->
            <section class="xl:col-span-2 bg-white rounded-xl border border-slate-200 self-start">
                <div class="p-4 border-b border-slate-100 flex flex-col gap-1">
                    <label class="text-sm font-medium">Agregar producto</label>
                    <AutoComplete
                        v-model="productoBuscado"
                        :suggestions="sugerencias"
                        optionLabel="descripcion"
                        placeholder="Busca por nombre, principio activo, código o código de barras"
                        :delay="250"
                        fluid
                        @complete="buscarProductos"
                        @option-select="agregarProducto"
                    >
                        <template #option="{ option }">
                            <div>
                                <p>{{ option.descripcion }}</p>
                                <p class="text-xs text-slate-500">{{ option.codigo }} · {{ option.laboratorio }}</p>
                            </div>
                        </template>
                    </AutoComplete>
                    <small v-if="form.errors.items" class="text-red-600">{{ form.errors.items }}</small>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                            <tr>
                                <th class="text-left p-3">Producto</th>
                                <th class="text-left p-3 w-72">Lote</th>
                                <th class="text-left p-3 w-28">Unidad</th>
                                <th class="text-center p-3 w-32">Cantidad</th>
                                <th class="text-right p-3 w-28">Valor</th>
                                <th class="w-12"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!form.items.length">
                                <td colspan="6" class="p-8 text-center text-slate-400">
                                    <i class="pi pi-sliders-h text-2xl block mb-2"></i>
                                    Busca y agrega los productos a {{ esSalida ? 'sacar del' : 'ingresar al' }} inventario.
                                </td>
                            </tr>
                            <tr v-for="(l, i) in form.items" :key="i" class="border-t border-slate-100 align-top">
                                <td class="p-3">
                                    <p class="font-medium">{{ l.producto.descripcion }}</p>
                                    <p class="text-xs text-slate-500">{{ l.producto.codigo }} · {{ l.producto.laboratorio }}</p>
                                </td>

                                <!-- Lote -->
                                <td class="p-3 space-y-2">
                                    <template v-if="esSalida">
                                        <Select
                                            v-model="l.lote_id"
                                            :options="opcionesLoteSalida(l)"
                                            optionLabel="label"
                                            optionValue="value"
                                            placeholder="Elige el lote"
                                            fluid
                                            :invalid="!!error(i, 'lote_id') || !l.lote_id"
                                        />
                                        <small v-if="!l.lotes.length" class="text-red-600 block">Este producto no tiene stock.</small>
                                        <Tag v-else-if="loteSalida(l)?.vencido" value="Lote vencido" severity="danger" />
                                    </template>
                                    <template v-else>
                                        <Select v-model="l.lote_sel" :options="opcionesLoteEntrada(l)" optionLabel="label" optionValue="value" fluid />
                                        <div v-if="l.lote_sel === 'nuevo'" class="grid grid-cols-2 gap-2">
                                            <InputText
                                                v-model="l.numero_lote"
                                                placeholder="N° de lote"
                                                :invalid="!!error(i, 'numero_lote') || !l.numero_lote.trim()"
                                                @blur="l.numero_lote = l.numero_lote.toUpperCase().trim()"
                                            />
                                            <DatePicker
                                                v-model="l.fecha_vencimiento"
                                                placeholder="Vence"
                                                dateFormat="dd/mm/yy"
                                                :invalid="!!error(i, 'fecha_vencimiento') || !l.fecha_vencimiento"
                                                fluid
                                            />
                                            <div class="col-span-2 flex items-center gap-2">
                                                <span class="text-xs text-slate-500 whitespace-nowrap">Costo x {{ l.producto.unidad_venta }} (sin IGV)</span>
                                                <InputNumber v-model="l.costo_unitario" prefix="S/ " locale="en-US" :minFractionDigits="2" :maxFractionDigits="4" :min="0" fluid />
                                            </div>
                                        </div>
                                    </template>
                                    <small v-if="error(i, 'lote_id') || error(i, 'numero_lote') || error(i, 'fecha_vencimiento')" class="text-red-600 block">
                                        {{ error(i, 'lote_id') || error(i, 'numero_lote') || error(i, 'fecha_vencimiento') }}
                                    </small>
                                </td>

                                <td class="p-3">
                                    <SelectButton
                                        v-if="l.producto.fraccionable"
                                        v-model="l.por_fraccion"
                                        :options="opcionesUnidad(l.producto)"
                                        optionLabel="label"
                                        optionValue="value"
                                        :allowEmpty="false"
                                        size="small"
                                    />
                                    <span v-else class="text-slate-600">{{ l.producto.unidad_venta }}</span>
                                </td>
                                <td class="p-3">
                                    <InputNumber
                                        v-model="l.cantidad"
                                        :min="0"
                                        :maxFractionDigits="2"
                                        locale="en-US"
                                        inputClass="text-center w-24"
                                        :invalid="!!error(i, 'cantidad') || excede(l)"
                                    />
                                    <small v-if="excede(l)" class="text-red-600 block">Solo hay {{ textoStock(loteSalida(l).cantidad, l.producto) }}.</small>
                                </td>
                                <td class="p-3 text-right whitespace-nowrap">
                                    {{ soles(valor(l)) }}
                                    <span class="block text-xs text-slate-500">{{ precio(costo(l)) }} c/u</span>
                                </td>
                                <td class="p-3">
                                    <Button icon="pi pi-trash" text rounded severity="danger" @click="quitar(i)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ===== DERECHA: TIPO, MOTIVO Y GUARDAR ===== -->
            <div class="space-y-6">
                <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            class="rounded-lg border-2 p-3 text-left transition-colors"
                            :class="esSalida ? 'border-red-600 bg-red-50' : 'border-slate-200 hover:bg-slate-50'"
                            @click="cambiarTipo('salida')"
                        >
                            <i class="pi pi-minus-circle text-red-600"></i>
                            <p class="font-semibold text-red-700 mt-1">Salida</p>
                            <p class="text-xs text-slate-500">Baja el stock: rotura, vencido, faltante…</p>
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border-2 p-3 text-left transition-colors"
                            :class="!esSalida ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200 hover:bg-slate-50'"
                            @click="cambiarTipo('entrada')"
                        >
                            <i class="pi pi-plus-circle text-emerald-600"></i>
                            <p class="font-semibold text-emerald-700 mt-1">Entrada</p>
                            <p class="text-xs text-slate-500">Sube el stock: sobrante, inventario inicial…</p>
                        </button>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Motivo *</label>
                        <Select
                            v-model="form.motivo"
                            :options="opcionesMotivo"
                            optionLabel="label"
                            optionValue="value"
                            placeholder="Elige el motivo"
                            fluid
                            :invalid="!!form.errors.motivo"
                        />
                        <small class="text-red-600">{{ form.errors.motivo }}</small>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm">¿Qué pasó? *</label>
                        <Textarea
                            v-model="form.observacion"
                            rows="3"
                            autoResize
                            maxlength="500"
                            fluid
                            :invalid="!!form.errors.observacion"
                            :placeholder="esSalida ? 'Ej. Se cayó la caja al descargar el pedido; 2 frascos rotos.' : 'Ej. En el conteo del 02/10 aparecieron 3 cajas que no figuraban.'"
                        />
                        <small class="text-red-600">{{ form.errors.observacion }}</small>
                    </div>

                    <Message v-if="form.motivo === 'vencimiento'" severity="info" size="small">
                        Imprime el acta del ajuste y guárdala firmada: DIGEMID la pide como sustento de la baja de productos vencidos.
                    </Message>
                </section>

                <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-3 text-sm">
                    <div class="flex justify-between"><span>Productos</span><span>{{ form.items.length }}</span></div>
                    <div class="flex justify-between text-xl font-semibold border-t pt-2">
                        <span>Valor {{ esSalida ? 'que sale' : 'que entra' }}</span>
                        <span :class="esSalida ? 'text-red-600' : 'text-emerald-700'">{{ soles(total) }}</span>
                    </div>
                    <p class="text-xs text-slate-500">A costo, sin IGV.</p>

                    <Button
                        :label="esSalida ? 'Registrar salida' : 'Registrar entrada'"
                        :icon="esSalida ? 'pi pi-minus-circle' : 'pi pi-plus-circle'"
                        :severity="esSalida ? 'danger' : 'success'"
                        class="w-full"
                        size="large"
                        :loading="form.processing"
                        :disabled="!puedeGuardar"
                        @click="guardar"
                    />
                </section>
            </div>
        </div>
    </AppLayout>
</template>