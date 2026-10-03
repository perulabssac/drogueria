<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AutoComplete from 'primevue/autocomplete';
import Button from 'primevue/button';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import Checkbox from 'primevue/checkbox';
import Textarea from 'primevue/textarea';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { fecha, precio, soles, stock as textoStock } from '@/utils/formato';
import { obtenerJson, enviarJson, aFechaISO } from '@/utils/http';

const props = defineProps({
    cotizacion: Object, // null = nueva
    base: Object, // cotización de la que se copió (renovar/duplicar)
    inicial: Object,
    clienteVarios: Object,
    vendedores: Array,
});

const TASA_IGV = 0.18;
const editando = computed(() => !!props.cotizacion);

const form = useForm({
    vendedor_id: props.inicial.vendedor_id,
    validez_dias: props.inicial.validez_dias,
    forma_pago: props.inicial.forma_pago,
    condiciones: props.inicial.condiciones,
    observaciones: props.inicial.observaciones,
    items: props.inicial.items,
});

// ================= CLIENTE =================
const cliente = ref(props.inicial.cliente);
const sugerenciasCliente = ref([]);
const textoCliente = ref('');
const buscarClientes = async (e) => {
    textoCliente.value = e.query.trim();
    sugerenciasCliente.value = await obtenerJson('/api/clientes/buscar', { q: e.query });
};
// Si no está registrado: se busca en SUNAT (RUC) o RENIEC (DNI) y queda guardado
const numeroConsultable = computed(() => /^(\d{8}|\d{11})$/.test(textoCliente.value));
const consultando = ref(false);
const errorCliente = ref('');
const consultarCliente = async () => {
    consultando.value = true;
    errorCliente.value = '';
    try {
        const r = await enviarJson('/api/clientes/consultar', { numero: textoCliente.value });
        cliente.value = r.cliente;
    } catch (e) {
        errorCliente.value = e.errores ? Object.values(e.errores).flat()[0] : e.message;
    } finally {
        consultando.value = false;
    }
};
const usarClientesVarios = () => (cliente.value = props.clienteVarios);

// ================= PRODUCTOS =================
const productoBuscado = ref('');
const sugerenciasProducto = ref([]);
const buscarProductos = async (e) => {
    sugerenciasProducto.value = await obtenerJson('/api/productos/buscar', { q: e.query });
};
const agregarProducto = (e) => {
    const p = e.value;
    form.items.push({ producto: p, cantidad: 1, por_fraccion: false, precio_unitario: p.precio_venta, bonificacion: false });
    productoBuscado.value = '';
};
const quitar = (i) => form.items.splice(i, 1);

const opcionesUnidad = (p) => [
    { value: false, label: p.unidad_venta },
    { value: true, label: p.unidad_fraccion },
];
const cambiarUnidad = (l) => {
    l.precio_unitario = l.por_fraccion ? l.producto.precio_fraccion : l.producto.precio_venta;
};

// La cotización no separa stock: solo se avisa si hoy no alcanzaría
const unidadesPedidas = (l) => (Number(l.cantidad) || 0) * (l.por_fraccion || !l.producto.fraccionable ? 1 : l.producto.unidades_por_presentacion);
const pedidoPorProducto = computed(() => {
    const total = {};
    for (const l of form.items) total[l.producto.id] = (total[l.producto.id] ?? 0) + unidadesPedidas(l);
    return total;
});
const sinStock = (l) => pedidoPorProducto.value[l.producto.id] > l.producto.stock;
const haySinStock = computed(() => form.items.some(sinStock));

const importe = (l) => (l.bonificacion ? 0 : Math.round((Number(l.cantidad) || 0) * (Number(l.precio_unitario) || 0) * 100) / 100);

// ================= TOTALES (el servidor recalcula) =================
const totales = computed(() => {
    const t = { gravadas: 0, exoneradas: 0, igv: 0, total: 0 };
    for (const l of form.items) {
        if (l.bonificacion) continue;
        const bruto = importe(l);
        const gravado = l.producto.tipo_afectacion_igv === '10';
        const valor = gravado ? Math.round((bruto / (1 + TASA_IGV)) * 100) / 100 : bruto;
        if (gravado) t.gravadas += valor;
        else t.exoneradas += valor;
        t.igv += bruto - valor;
        t.total += bruto;
    }
    return t;
});

// ================= VALIDEZ =================
const venceEl = computed(() => {
    const d = props.cotizacion ? new Date(String(props.cotizacion.fecha).substring(0, 10) + 'T00:00:00') : new Date();
    d.setDate(d.getDate() + (Number(form.validez_dias) || 0));
    return d;
});
const opcionesPago = [
    { value: 'contado', label: 'Contado' },
    { value: 'credito', label: 'Crédito' },
];

// ================= GUARDAR =================
const error = (i, campo) => form.errors[`items.${i}.${campo}`];
const puedeGuardar = computed(() => cliente.value && form.items.length > 0 && totales.value.total > 0);

const guardar = () => {
    form.transform((d) => ({
        ...d,
        cliente_id: cliente.value?.id,
        items: d.items.map((l) => ({
            producto_id: l.producto.id,
            cantidad: l.cantidad,
            por_fraccion: l.por_fraccion,
            precio_unitario: l.bonificacion ? null : l.precio_unitario,
            bonificacion: l.bonificacion,
        })),
    }));
    if (editando.value) form.put(`/cotizaciones/${props.cotizacion.id}`, { preserveScroll: true });
    else form.post('/cotizaciones', { preserveScroll: true });
};

const titulo = computed(() => (editando.value ? `Editar cotización ${props.cotizacion.numero}` : 'Nueva cotización'));
</script>

<template>
    <Head :title="titulo" />
    <AppLayout :titulo="titulo">
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <Link :href="editando ? `/cotizaciones/${cotizacion.id}` : '/cotizaciones'">
                <Button label="Volver" icon="pi pi-arrow-left" text />
            </Link>
            <Message v-if="base" severity="info" size="small" class="flex-1">
                Copia de la cotización <b>{{ base.numero }}</b> con los <b>precios de hoy</b>. Revisa y guarda para crear una nueva.
            </Message>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <!-- ===== IZQUIERDA: PRODUCTOS ===== -->
            <section class="xl:col-span-2 bg-white rounded-xl border border-slate-200 self-start">
                <div class="p-4 border-b border-slate-100 flex flex-col gap-1">
                    <label class="text-sm font-medium">Agregar producto</label>
                    <AutoComplete
                        v-model="productoBuscado"
                        :suggestions="sugerenciasProducto"
                        optionLabel="descripcion"
                        placeholder="Busca por nombre, principio activo, código o código de barras"
                        :delay="250"
                        fluid
                        @complete="buscarProductos"
                        @option-select="agregarProducto"
                    >
                        <template #option="{ option }">
                            <div class="flex items-center gap-3 w-full">
                                <div class="flex-1 min-w-0">
                                    <p class="truncate">{{ option.descripcion }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ option.codigo }} · {{ option.laboratorio }} · {{ precio(option.precio_venta) }} / {{ option.unidad_venta }}
                                    </p>
                                </div>
                                <Tag :value="textoStock(option.stock, option)" :severity="option.stock > 0 ? 'success' : 'danger'" />
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
                                <th class="text-left p-3 w-32">Unidad</th>
                                <th class="text-center p-3 w-40">Cantidad</th>
                                <th class="text-left p-3 w-36">Precio unit.</th>
                                <th class="text-center p-3 w-16">Bonif.</th>
                                <th class="text-right p-3 w-28">Importe</th>
                                <th class="w-12"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!form.items.length">
                                <td colspan="7" class="p-8 text-center text-slate-400">
                                    <i class="pi pi-file-edit text-2xl block mb-2"></i>
                                    Busca y agrega los productos que vas a cotizar.
                                </td>
                            </tr>
                            <tr v-for="(l, i) in form.items" :key="i" class="border-t border-slate-100 align-top">
                                <td class="p-3">
                                    <p class="font-medium">{{ l.producto.descripcion }}</p>
                                    <p class="text-xs text-slate-500 flex flex-wrap items-center gap-1 mt-0.5">
                                        <span>Stock hoy: {{ textoStock(l.producto.stock, l.producto) }}</span>
                                        <Tag v-if="l.producto.condicion_venta !== 'sin_receta'" value="Receta" severity="warn" />
                                        <Tag v-if="l.producto.cadena_frio" value="Frío" severity="info" />
                                        <Tag v-if="l.producto.tipo_afectacion_igv !== '10'" value="Exonerado" severity="secondary" />
                                    </p>
                                    <small v-if="sinStock(l)" class="text-amber-600">Hoy no alcanza el stock (la cotización no lo separa).</small>
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
                                        @change="cambiarUnidad(l)"
                                    />
                                    <span v-else class="text-slate-600">{{ l.producto.unidad_venta }}</span>
                                </td>
                                <td class="p-3">
                                    <InputNumber
                                        v-model="l.cantidad"
                                        showButtons
                                        buttonLayout="horizontal"
                                        :step="1"
                                        :min="1"
                                        :maxFractionDigits="2"
                                        locale="en-US"
                                        incrementButtonIcon="pi pi-plus"
                                        decrementButtonIcon="pi pi-minus"
                                        inputClass="text-center w-14"
                                        :invalid="!!error(i, 'cantidad')"
                                    />
                                </td>
                                <td class="p-3">
                                    <InputNumber
                                        v-model="l.precio_unitario"
                                        prefix="S/ "
                                        locale="en-US"
                                        :minFractionDigits="3"
                                        :maxFractionDigits="3"
                                        :disabled="l.bonificacion"
                                        fluid
                                        :invalid="!!error(i, 'precio_unitario')"
                                    />
                                </td>
                                <td class="p-3 text-center">
                                    <Checkbox v-model="l.bonificacion" binary v-tooltip.top="'Se entrega gratis (bonificación)'" />
                                </td>
                                <td class="p-3 text-right font-medium whitespace-nowrap">
                                    <Tag v-if="l.bonificacion" value="Gratis" severity="success" />
                                    <span v-else>{{ soles(importe(l)) }}</span>
                                </td>
                                <td class="p-3">
                                    <Button icon="pi pi-trash" text rounded severity="danger" @click="quitar(i)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ===== DERECHA: CLIENTE, CONDICIONES Y TOTALES ===== -->
            <div class="space-y-6">
                <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center justify-between">
                            <label class="text-sm">Cliente *</label>
                            <Button label="Clientes varios" icon="pi pi-users" text size="small" severity="secondary" @click="usarClientesVarios" />
                        </div>
                        <AutoComplete
                            v-model="cliente"
                            :suggestions="sugerenciasCliente"
                            optionLabel="razon_social"
                            placeholder="RUC, DNI o nombre"
                            :delay="250"
                            forceSelection
                            fluid
                            :invalid="!!form.errors.cliente_id"
                            @complete="buscarClientes"
                        >
                            <template #option="{ option }">
                                <div>
                                    <p>{{ option.razon_social }}</p>
                                    <p class="text-xs text-slate-500">{{ option.tipo_documento === '6' ? 'RUC' : 'DOC' }} {{ option.numero_documento }}</p>
                                </div>
                            </template>
                            <template #empty>
                                <div class="p-2 text-sm">
                                    <p class="text-slate-500 mb-2">No está registrado.</p>
                                    <Button
                                        v-if="numeroConsultable"
                                        :label="textoCliente.length === 11 ? `Buscar ${textoCliente} en SUNAT` : `Buscar ${textoCliente} en RENIEC`"
                                        icon="pi pi-search"
                                        severity="help"
                                        size="small"
                                        :loading="consultando"
                                        @click="consultarCliente"
                                    />
                                    <p v-else class="text-xs text-slate-400">Escribe el RUC (11 dígitos) o DNI (8) para buscarlo.</p>
                                </div>
                            </template>
                        </AutoComplete>
                        <small v-if="cliente" class="text-slate-500">
                            {{ cliente.tipo_documento === '6' ? 'RUC' : 'Doc.' }} {{ cliente.numero_documento }}
                            <span v-if="cliente.direccion"> · {{ cliente.direccion }}</span>
                        </small>
                        <small class="text-red-600">{{ form.errors.cliente_id || errorCliente }}</small>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Validez (días) *</label>
                            <InputNumber v-model="form.validez_dias" :min="1" :max="90" showButtons fluid :invalid="!!form.errors.validez_dias" />
                            <small class="text-slate-500">Vence el {{ fecha(aFechaISO(venceEl)) }}</small>
                            <small class="text-red-600">{{ form.errors.validez_dias }}</small>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Forma de pago</label>
                            <SelectButton v-model="form.forma_pago" :options="opcionesPago" optionLabel="label" optionValue="value" :allowEmpty="false" />
                        </div>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Vendedor</label>
                        <Select v-model="form.vendedor_id" :options="vendedores" optionLabel="name" optionValue="id" fluid />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Condiciones (salen impresas)</label>
                        <Textarea v-model="form.condiciones" rows="3" autoResize maxlength="500" placeholder="Ej. Entrega en 48 horas. Precios incluyen IGV." />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Observaciones</label>
                        <Textarea v-model="form.observaciones" rows="2" autoResize maxlength="500" />
                    </div>
                </section>

                <!-- Totales -->
                <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-slate-500">Op. gravadas</span><span>{{ soles(totales.gravadas) }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">Op. exoneradas</span><span>{{ soles(totales.exoneradas) }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-500">IGV (18%)</span><span>{{ soles(totales.igv) }}</span></div>
                    <div class="flex justify-between border-t border-slate-200 pt-2 text-lg font-semibold">
                        <span>Total</span><span>{{ soles(totales.total) }}</span>
                    </div>

                    <Message v-if="form.errors.cotizacion" severity="error" size="small">{{ form.errors.cotizacion }}</Message>
                    <Message v-if="haySinStock" severity="warn" size="small">
                        Algunos productos no tienen stock suficiente hoy. Puedes cotizarlos igual, pero avísale al cliente el plazo de entrega.
                    </Message>

                    <Button
                        :label="editando ? 'Guardar cambios' : 'Guardar cotización'"
                        icon="pi pi-check"
                        class="w-full mt-2"
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