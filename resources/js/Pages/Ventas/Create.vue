<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AutoComplete from 'primevue/autocomplete';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import DatePicker from 'primevue/datepicker';
import Checkbox from 'primevue/checkbox';
import Textarea from 'primevue/textarea';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';
import { precio, soles, stock as textoStock } from '@/utils/formato';
import { obtenerJson, enviarJson, aFechaISO } from '@/utils/http';

const props = defineProps({
    series: Array,
    clienteVarios: Object,
    vendedores: Array,
    montoIdentificarBoleta: Number,
    mediosPago: Object,
    cajaAbierta: Boolean,
    cotizacion: { type: Object, default: null }, // venta que nace de una cotización
});

const TASA_IGV = 0.18;
const confirm = useConfirm();
const usuario = usePage().props.auth.user;

// ================= COMPROBANTE =================
const opcionesTipo = [
    { value: '01', label: 'Factura' },
    { value: '03', label: 'Boleta' },
    { value: 'NV', label: 'Nota de venta' },
];
const NOMBRES = { '01': 'factura', '03': 'boleta', NV: 'nota de venta' };
// Desde una cotización: factura si el cliente tiene RUC; si no, boleta
const cot = props.cotizacion;
const tipo = ref(cot && cot.cliente.tipo_documento !== '6' ? '03' : '01');
const seriesDelTipo = computed(() => props.series.filter((s) => s.tipo_comprobante === tipo.value));
const esFactura = computed(() => tipo.value === '01');
const esBoleta = computed(() => tipo.value === '03');
const esInterno = computed(() => tipo.value === 'NV'); // nota de venta: no va a SUNAT
const nombreTipo = computed(() => NOMBRES[tipo.value]);

const form = useForm({
    serie_id: seriesDelTipo.value[0]?.id ?? null,
    cliente_id: null,
    cotizacion_id: cot?.id ?? null,
    vendedor_id: cot?.vendedor_id ?? usuario.id,
    forma_pago: cot && tipo.value === '01' ? cot.forma_pago : 'contado',
    cuotas: [],
    pagos: [{ medio: 'efectivo', monto: 0, recibido: null, referencia: '' }],
    guia_remision: '',
    orden_compra: '',
    observaciones: cot?.observaciones ?? '',
    receta_verificada: false,
    items: cot?.items ?? [],
});

// ================= CLIENTE =================
const cliente = ref(cot?.cliente ?? null);
const sugerenciasCliente = ref([]);
const textoCliente = ref(''); // lo que se escribió en el buscador
const errorBusquedaCliente = ref('');
const buscarClientes = async (e) => {
    textoCliente.value = e.query.trim();
    errorBusquedaCliente.value = '';
    sugerenciasCliente.value = await obtenerJson('/api/clientes/buscar', { q: textoCliente.value, ...(esFactura.value ? { tipo: '6' } : {}) });
};
// Si no está registrado y es un DNI/RUC válido, se ofrece buscarlo en SUNAT/RENIEC
const numeroConsultable = computed(() =>
    esFactura.value ? /^(10|15|17|20)\d{9}$/.test(textoCliente.value) : /^(\d{8}|(10|15|17|20)\d{9})$/.test(textoCliente.value),
);

// Al cambiar el tipo: serie, cliente y forma de pago por defecto
watch(tipo, (t) => {
    form.serie_id = seriesDelTipo.value[0]?.id ?? null;
    if (t === '03' || t === 'NV') {
        cliente.value = cliente.value ?? props.clienteVarios;
        if (t === '03') form.forma_pago = 'contado';
    } else if (cliente.value?.tipo_documento !== '6') {
        cliente.value = null;
    }
}, { immediate: true });

// Aviso si el RUC elegido figura en SUNAT como BAJA o NO HABIDO
const avisoCliente = computed(() => {
    const c = cliente.value;
    if (!c || typeof c !== 'object' || c.tipo_documento !== '6') return null;
    if (c.estado_sunat && c.estado_sunat !== 'ACTIVO') return `El RUC figura en SUNAT como ${c.estado_sunat}.`;
    if (c.condicion_sunat && c.condicion_sunat !== 'HABIDO') return `El RUC figura en SUNAT como ${c.condicion_sunat}.`;
    return null;
});

// Registro rápido de cliente sin salir de la venta
const nuevoCliente = ref(null);
const erroresCliente = ref({});
const guardandoCliente = ref(false);
const consultandoCliente = ref(false);
const abrirNuevoCliente = () => {
    erroresCliente.value = {};
    nuevoCliente.value = { tipo_documento: esFactura.value ? '6' : '1', numero_documento: '', razon_social: '', direccion: '' };
};
const guardarCliente = async () => {
    guardandoCliente.value = true;
    try {
        cliente.value = await enviarJson('/api/clientes', nuevoCliente.value);
        nuevoCliente.value = null;
    } catch (e) {
        erroresCliente.value = e.errores ?? { numero_documento: [e.message] };
    } finally {
        guardandoCliente.value = false;
    }
};
// Buscar en SUNAT/RENIEC: si ya está registrado no gasta consulta; si no, lo trae y lo guarda
const clienteConsultable = computed(() => ['1', '6'].includes(nuevoCliente.value?.tipo_documento));
const traerCliente = async (numero) => {
    consultandoCliente.value = true;
    try {
        const r = await enviarJson('/api/clientes/consultar', { numero });
        if (esFactura.value && r.cliente.tipo_documento !== '6') {
            return 'Para factura se necesita un RUC (11 dígitos).';
        }
        cliente.value = r.cliente;
        return null;
    } catch (e) {
        return e.errores?.numero?.[0] ?? Object.values(e.errores ?? {})[0]?.[0] ?? e.message;
    } finally {
        consultandoCliente.value = false;
    }
};
// Desde el buscador principal (cliente no registrado)
const consultarDesdeBuscador = async () => {
    errorBusquedaCliente.value = (await traerCliente(textoCliente.value)) ?? '';
};
// Desde el panel "Nuevo cliente"
const consultarCliente = async () => {
    if (!clienteConsultable.value || !nuevoCliente.value.numero_documento.trim()) return;
    erroresCliente.value = {};
    const fallo = await traerCliente(nuevoCliente.value.numero_documento.trim());
    if (fallo) erroresCliente.value = { numero_documento: [fallo] };
    else nuevoCliente.value = null;
};
const opcionesDocCliente = computed(() =>
    esFactura.value
        ? [{ value: '6', label: 'RUC' }]
        : [
              { value: '1', label: 'DNI' },
              { value: '6', label: 'RUC' },
              { value: '4', label: 'Carnet de extranjería' },
              { value: '7', label: 'Pasaporte' },
          ],
);

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
// Al cambiar caja <-> unidad suelta, se carga el precio de lista correspondiente
const cambiarUnidad = (l) => {
    l.precio_unitario = l.por_fraccion ? l.producto.precio_fraccion : l.producto.precio_venta;
};

// Unidades mínimas que pide la línea y si alcanza el stock
const unidadesPedidas = (l) => (Number(l.cantidad) || 0) * (l.por_fraccion || !l.producto.fraccionable ? 1 : l.producto.unidades_por_presentacion);
const pedidoPorProducto = computed(() => {
    const total = {};
    for (const l of form.items) total[l.producto.id] = (total[l.producto.id] ?? 0) + unidadesPedidas(l);
    return total;
});
const sinStock = (l) => pedidoPorProducto.value[l.producto.id] > l.producto.stock;

const importe = (l) => (l.bonificacion ? 0 : Math.round((Number(l.cantidad) || 0) * (Number(l.precio_unitario) || 0) * 100) / 100);

// ================= TOTALES (el servidor recalcula; esto es referencial) =================
const totales = computed(() => {
    const t = { gravadas: 0, exoneradas: 0, gratuitas: 0, igv: 0, total: 0 };
    for (const l of form.items) {
        const bruto = Math.round((Number(l.cantidad) || 0) * (Number(l.precio_unitario) || 0) * 100) / 100;
        const gravado = l.producto.tipo_afectacion_igv === '10';
        const valor = gravado ? Math.round((bruto / (1 + TASA_IGV)) * 100) / 100 : bruto;
        if (l.bonificacion) {
            t.gratuitas += valor;
            continue;
        }
        if (gravado) t.gravadas += valor;
        else t.exoneradas += valor;
        t.igv += bruto - valor;
        t.total += bruto;
    }
    return t;
});

const requiereReceta = computed(() => form.items.some((l) => l.producto.condicion_venta !== 'sin_receta'));
const boletaSinIdentificar = computed(
    () => esBoleta.value && totales.value.total > props.montoIdentificarBoleta && cliente.value?.numero_documento === '00000000',
);

// ================= CRÉDITO Y CUOTAS =================
const opcionesPago = [
    { value: 'contado', label: 'Contado' },
    { value: 'credito', label: 'Crédito' },
];
const sumarDias = (dias) => {
    const d = new Date();
    d.setDate(d.getDate() + dias);
    return d;
};
// Por defecto, una cuota por el total a los días de crédito del cliente (o 30)
watch(
    () => form.forma_pago,
    (fp) => {
        if (fp === 'credito' && !form.cuotas.length) {
            form.cuotas = [{ monto: totales.value.total, fecha: sumarDias(cliente.value?.dias_credito || 30) }];
        }
    },
    { immediate: true },
);
const agregarCuota = () => form.cuotas.push({ monto: 0, fecha: sumarDias(30 * (form.cuotas.length + 1)) });
const quitarCuota = (i) => form.cuotas.splice(i, 1);
const repartirCuotas = () => {
    const n = form.cuotas.length;
    if (!n) return;
    const base = Math.floor((totales.value.total / n) * 100) / 100;
    form.cuotas.forEach((c, i) => (c.monto = i === n - 1 ? Math.round((totales.value.total - base * (n - 1)) * 100) / 100 : base));
};
const creditoSinCliente = computed(() => form.forma_pago === 'credito' && cliente.value?.numero_documento === '00000000');
const sumaCuotas = computed(() => Math.round(form.cuotas.reduce((s, c) => s + (Number(c.monto) || 0), 0) * 100) / 100);
const cuotasCuadran = computed(() => Math.abs(sumaCuotas.value - Math.round(totales.value.total * 100) / 100) < 0.01);

// ================= MEDIOS DE PAGO (venta al contado) =================
const opcionesMedio = Object.entries(props.mediosPago).map(([value, label]) => ({ value, label }));
const redondear = (n) => Math.round(n * 100) / 100;
const sumaPagos = computed(() => redondear(form.pagos.reduce((s, p) => s + (Number(p.monto) || 0), 0)));
const faltaPagar = computed(() => redondear(totales.value.total - sumaPagos.value));
const pagosCuadran = computed(() => Math.abs(faltaPagar.value) < 0.01);
const vuelto = (p) => (p.medio === 'efectivo' && p.recibido ? redondear(p.recibido - (Number(p.monto) || 0)) : 0);
const efectivoInsuficiente = computed(() => form.pagos.some((p) => p.medio === 'efectivo' && p.recibido && vuelto(p) < 0));

// Con un solo medio de pago, el monto sigue al total automáticamente
watch(
    () => totales.value.total,
    (t) => {
        if (form.pagos.length === 1) form.pagos[0].monto = redondear(t);
    },
    { immediate: true },
);
// Pago mixto: el nuevo medio propone lo que falta
const agregarPago = () => form.pagos.push({ medio: 'yape', monto: Math.max(faltaPagar.value, 0), recibido: null, referencia: '' });
const quitarPago = (i) => {
    form.pagos.splice(i, 1);
    if (form.pagos.length === 1) form.pagos[0].monto = redondear(totales.value.total);
};

// ================= EMITIR =================
const serieElegida = computed(() => props.series.find((s) => s.id === form.serie_id));
const puedeEmitir = computed(
    () =>
        form.items.length > 0 &&
        cliente.value &&
        form.serie_id &&
        (!requiereReceta.value || form.receta_verificada) &&
        (form.forma_pago === 'contado'
            ? props.cajaAbierta && pagosCuadran.value && !efectivoInsuficiente.value
            : cuotasCuadran.value && !creditoSinCliente.value) &&
        !boletaSinIdentificar.value &&
        !(esFactura.value && avisoCliente.value),
);

const error = (i, campo) => form.errors[`items.${i}.${campo}`];

const emitir = () => {
    confirm.require({
        header: `Emitir ${nombreTipo.value}`,
        message:
            `Se emitirá la ${nombreTipo.value} serie ${serieElegida.value?.serie} por ${soles(totales.value.total)} a ${cliente.value.razon_social}` +
            (esInterno.value ? '. Es un documento interno: no se envía a SUNAT. ¿Continuar?' : ' y se enviará a SUNAT. ¿Continuar?'),
        icon: esInterno.value ? 'pi pi-file' : 'pi pi-send',
        acceptProps: { label: esInterno.value ? 'Registrar' : 'Emitir y enviar' },
        rejectProps: { label: 'Revisar', severity: 'secondary', outlined: true },
        accept: () =>
            form
                .transform((d) => ({
                    ...d,
                    cliente_id: cliente.value?.id,
                    cuotas: d.forma_pago === 'credito' ? d.cuotas.map((c) => ({ monto: c.monto, fecha_vencimiento: aFechaISO(c.fecha) })) : [],
                    pagos:
                        d.forma_pago === 'contado'
                            ? d.pagos.map((p) => ({
                                  medio: p.medio,
                                  monto: p.monto,
                                  recibido: p.medio === 'efectivo' ? p.recibido || null : null,
                                  referencia: p.medio === 'efectivo' ? null : p.referencia || null,
                              }))
                            : [],
                    items: d.items.map((l) => ({
                        producto_id: l.producto.id,
                        cantidad: l.cantidad,
                        por_fraccion: l.por_fraccion,
                        precio_unitario: l.bonificacion ? null : l.precio_unitario,
                        bonificacion: l.bonificacion,
                    })),
                }))
                .post('/ventas', { preserveScroll: true }),
    });
};
</script>

<template>
    <Head title="Nueva venta" />
    <AppLayout titulo="Nueva venta">
        <Message v-if="cot" severity="info" class="mb-4">
            Venta desde la cotización
            <Link :href="`/cotizaciones/${cot.id}`" class="font-semibold underline">{{ cot.numero }}</Link>
            con sus precios cotizados. Revisa el stock y el cobro: al emitir, la cotización quedará como <b>vendida</b>.
        </Message>
        <Message v-if="form.errors.cotizacion_id" severity="error" class="mb-4">{{ form.errors.cotizacion_id }}</Message>
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
                        autofocus
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
                                    <i class="pi pi-shopping-cart text-2xl block mb-2"></i>
                                    Busca y agrega los productos. El sistema tomará primero los lotes que vencen antes (FEFO).
                                </td>
                            </tr>
                            <tr v-for="(l, i) in form.items" :key="i" class="border-t border-slate-100 align-top">
                                <td class="p-3">
                                    <p class="font-medium">{{ l.producto.descripcion }}</p>
                                    <p class="text-xs text-slate-500 flex flex-wrap items-center gap-1 mt-0.5">
                                        <span>Stock: {{ textoStock(l.producto.stock, l.producto) }}</span>
                                        <Tag v-if="l.producto.condicion_venta !== 'sin_receta'" value="Receta" severity="warn" />
                                        <Tag v-if="l.producto.cadena_frio" value="Frío" severity="info" />
                                        <Tag v-if="l.producto.tipo_afectacion_igv !== '10'" value="Exonerado" severity="secondary" />
                                    </p>
                                    <small v-if="sinStock(l)" class="text-red-600">No hay stock suficiente.</small>
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
                                        :invalid="!!error(i, 'cantidad') || sinStock(l)"
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

            <!-- ===== DERECHA: COMPROBANTE, CLIENTE, PAGO Y TOTALES ===== -->
            <div class="space-y-6">
                <!-- Comprobante y cliente -->
                <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
                    <div class="flex items-center gap-3">
                        <SelectButton v-model="tipo" :options="opcionesTipo" optionLabel="label" optionValue="value" :allowEmpty="false" />
                        <Select
                            v-if="seriesDelTipo.length > 1"
                            v-model="form.serie_id"
                            :options="seriesDelTipo"
                            optionLabel="serie"
                            optionValue="id"
                            class="w-28"
                        />
                        <Tag v-else-if="seriesDelTipo.length" :value="seriesDelTipo[0].serie" severity="secondary" />
                    </div>
                    <Message v-if="!seriesDelTipo.length" severity="error" size="small">No hay serie activa para este tipo en tu sucursal. Créala en Configuración → Series.</Message>
                    <Message v-if="esInterno" severity="info" size="small">
                        Documento de uso interno: descuenta stock pero <b>no se envía a SUNAT</b> y no es comprobante de pago.
                    </Message>

                    <div class="flex flex-col gap-1">
                        <div class="flex items-center justify-between">
                            <label class="text-sm">Cliente *</label>
                            <Button v-if="!nuevoCliente" label="Nuevo cliente" icon="pi pi-plus" text size="small" @click="abrirNuevoCliente" />
                        </div>
                        <AutoComplete
                            v-model="cliente"
                            :suggestions="sugerenciasCliente"
                            optionLabel="razon_social"
                            :placeholder="esFactura ? 'RUC o razón social' : 'DNI, RUC o nombre'"
                            :delay="250"
                            forceSelection
                            fluid
                            :invalid="!!form.errors.cliente_id || !!errorBusquedaCliente"
                            @complete="buscarClientes"
                            @keydown.enter="numeroConsultable && !sugerenciasCliente.length && consultarDesdeBuscador()"
                        >
                            <template #option="{ option }">
                                <div>
                                    <p>{{ option.razon_social }}</p>
                                    <p class="text-xs text-slate-500">{{ option.tipo_documento === '6' ? 'RUC' : 'DOC' }} {{ option.numero_documento }}</p>
                                </div>
                            </template>
                            <template #empty>
                                <div class="p-2">
                                    <Button
                                        v-if="numeroConsultable"
                                        :label="`Buscar ${textoCliente} en ${textoCliente.length === 11 ? 'SUNAT' : 'RENIEC'}`"
                                        icon="pi pi-search"
                                        severity="help"
                                        size="small"
                                        :loading="consultandoCliente"
                                        @click="consultarDesdeBuscador"
                                    />
                                    <span v-else class="text-sm text-slate-500">
                                        No está registrado. Escribe el {{ esFactura ? 'RUC' : 'DNI o RUC' }} completo para buscarlo, o usa "Nuevo cliente".
                                    </span>
                                </div>
                            </template>
                        </AutoComplete>
                        <small v-if="errorBusquedaCliente" class="text-red-600">{{ errorBusquedaCliente }}</small>
                        <small v-if="cliente" class="text-slate-500">
                            {{ cliente.tipo_documento === '6' ? 'RUC' : 'Doc.' }} {{ cliente.numero_documento }}
                            <span v-if="cliente.direccion"> · {{ cliente.direccion }}</span>
                        </small>
                        <Message v-if="avisoCliente" :severity="esFactura ? 'error' : 'warn'" size="small">
                            {{ avisoCliente }} <span v-if="esFactura">No se le puede emitir factura.</span>
                        </Message>
                        <small class="text-red-600">{{ form.errors.cliente_id }}</small>
                    </div>

                    <!-- Registro rápido de cliente (en la misma página) -->
                    <div v-if="nuevoCliente" class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-3 space-y-3">
                        <p class="text-sm font-medium">Nuevo cliente</p>
                        <div class="grid grid-cols-3 gap-2">
                            <Select v-model="nuevoCliente.tipo_documento" :options="opcionesDocCliente" optionLabel="label" optionValue="value" fluid />
                            <div class="col-span-2 flex gap-2">
                                <InputText
                                    v-model="nuevoCliente.numero_documento"
                                    placeholder="Número"
                                    fluid
                                    :invalid="!!erroresCliente.numero_documento"
                                    @keydown.enter.prevent="consultarCliente"
                                />
                                <Button
                                    v-if="clienteConsultable"
                                    icon="pi pi-search"
                                    severity="help"
                                    :loading="consultandoCliente"
                                    :disabled="!nuevoCliente.numero_documento"
                                    v-tooltip.top="nuevoCliente.tipo_documento === '6' ? 'Buscar en SUNAT' : 'Buscar en RENIEC'"
                                    @click="consultarCliente"
                                />
                            </div>
                        </div>
                        <small v-if="erroresCliente.numero_documento" class="text-red-600 block">{{ erroresCliente.numero_documento[0] }}</small>
                        <small v-if="clienteConsultable" class="text-slate-500 block">Escribe el número y pulsa 🔍 (o Enter): los datos se llenan solos.</small>
                        <InputText v-model="nuevoCliente.razon_social" placeholder="Razón social o nombre completo" fluid :invalid="!!erroresCliente.razon_social" />
                        <InputText v-model="nuevoCliente.direccion" placeholder="Dirección (opcional)" fluid />
                        <div class="flex justify-end gap-2">
                            <Button label="Cancelar" text severity="secondary" size="small" @click="nuevoCliente = null" />
                            <Button label="Guardar a mano" icon="pi pi-check" size="small" :loading="guardandoCliente" @click="guardarCliente" />
                        </div>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Vendedor</label>
                        <Select v-model="form.vendedor_id" :options="vendedores" optionLabel="name" optionValue="id" fluid />
                    </div>
                </section>

                <!-- Forma de pago -->
                <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">Condición de pago</h2>
                        <SelectButton v-model="form.forma_pago" :options="opcionesPago" optionLabel="label" optionValue="value" :allowEmpty="false" :disabled="esBoleta" />
                    </div>
                    <small v-if="esBoleta" class="text-slate-500 block">Las boletas son solo al contado. Para crédito usa factura o nota de venta.</small>
                    <Message v-if="creditoSinCliente" severity="warn" size="small">Para vender al crédito elige un cliente identificado (no "Clientes varios").</Message>
                    <Message v-if="form.errors.forma_pago" severity="error" size="small">{{ form.errors.forma_pago }}</Message>

                    <!-- Sin caja abierta no se puede cobrar al contado -->
                    <Message v-if="form.forma_pago === 'contado' && !cajaAbierta" severity="warn" size="small">
                        No tienes tu caja abierta. <Link href="/caja" class="font-semibold underline">Abre tu caja</Link> para cobrar al contado.
                    </Message>

                    <!-- Contado: con qué se pagó (uno o varios medios) -->
                    <template v-if="form.forma_pago === 'contado'">
                        <div v-for="(p, i) in form.pagos" :key="i" class="rounded-lg border border-slate-200 p-3 space-y-2">
                            <div class="flex items-center gap-2">
                                <Select v-model="p.medio" :options="opcionesMedio" optionLabel="label" optionValue="value" class="flex-1" />
                                <InputNumber
                                    v-model="p.monto"
                                    prefix="S/ "
                                    locale="en-US"
                                    :minFractionDigits="2"
                                    :maxFractionDigits="2"
                                    :disabled="form.pagos.length === 1"
                                    inputClass="w-28 text-right"
                                />
                                <Button v-if="form.pagos.length > 1" icon="pi pi-times" text rounded severity="secondary" @click="quitarPago(i)" />
                            </div>
                            <div v-if="p.medio === 'efectivo'" class="flex items-center gap-2 text-sm">
                                <span class="text-slate-500 w-20">Recibido</span>
                                <InputNumber v-model="p.recibido" prefix="S/ " locale="en-US" :minFractionDigits="2" :maxFractionDigits="2" placeholder="Opcional" inputClass="w-28 text-right" />
                                <span v-if="p.recibido" class="ml-auto" :class="vuelto(p) < 0 ? 'text-red-600' : 'font-semibold'">
                                    {{ vuelto(p) < 0 ? 'Falta ' + soles(-vuelto(p)) : 'Vuelto ' + soles(vuelto(p)) }}
                                </span>
                            </div>
                            <InputText v-else v-model="p.referencia" placeholder="N° de operación (opcional)" maxlength="50" fluid size="small" />
                        </div>
                        <div class="flex items-center justify-between">
                            <Button label="Agregar otro medio" icon="pi pi-plus" text size="small" @click="agregarPago" />
                            <small v-if="!pagosCuadran" class="text-red-600">
                                {{ faltaPagar > 0 ? 'Falta asignar ' + soles(faltaPagar) : 'Excede el total en ' + soles(-faltaPagar) }}
                            </small>
                        </div>
                        <small class="text-red-600 block">{{ form.errors.pagos }}</small>
                    </template>

                    <template v-if="form.forma_pago === 'credito'">
                        <div v-for="(c, i) in form.cuotas" :key="i" class="flex items-center gap-2">
                            <span class="text-sm text-slate-500 w-14">Cuota {{ i + 1 }}</span>
                            <InputNumber v-model="c.monto" prefix="S/ " :minFractionDigits="2" class="flex-1" fluid />
                            <DatePicker v-model="c.fecha" dateFormat="dd/mm/yy" :minDate="sumarDias(1)" class="w-36" fluid />
                            <Button icon="pi pi-times" text rounded severity="secondary" :disabled="form.cuotas.length === 1" @click="quitarCuota(i)" />
                        </div>
                        <div class="flex gap-2">
                            <Button label="Agregar cuota" icon="pi pi-plus" text size="small" @click="agregarCuota" />
                            <Button label="Repartir el total" icon="pi pi-equals" text size="small" @click="repartirCuotas" />
                        </div>
                        <Message v-if="!cuotasCuadran" severity="warn" size="small">
                            Las cuotas suman {{ soles(sumaCuotas) }} y el total es {{ soles(totales.total) }}. Deben ser iguales.
                        </Message>
                        <small class="text-red-600 block">{{ form.errors.cuotas }}</small>
                    </template>
                </section>

                <!-- Datos adicionales -->
                <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
                    <h2 class="font-semibold">Datos adicionales</h2>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-1">
                            <InputText v-model="form.guia_remision" placeholder="Guía (ej. T001-123)" :invalid="!!form.errors.guia_remision" @blur="form.guia_remision = form.guia_remision.toUpperCase().trim()" />
                            <small v-if="form.errors.guia_remision" class="text-red-600">{{ form.errors.guia_remision }}</small>
                        </div>
                        <InputText v-model="form.orden_compra" placeholder="Orden de compra" />
                    </div>
                    <Textarea v-model="form.observaciones" rows="2" autoResize placeholder="Observaciones" fluid />
                </section>

                <!-- Totales y emitir -->
                <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-2 text-sm">
                    <div class="flex justify-between"><span>Op. gravadas</span><span>{{ soles(totales.gravadas) }}</span></div>
                    <div class="flex justify-between"><span>Op. exoneradas</span><span>{{ soles(totales.exoneradas) }}</span></div>
                    <div v-if="totales.gratuitas" class="flex justify-between text-slate-500"><span>Op. gratuitas (bonificación)</span><span>{{ soles(totales.gratuitas) }}</span></div>
                    <div class="flex justify-between"><span>IGV (18%)</span><span>{{ soles(totales.igv) }}</span></div>
                    <div class="flex justify-between text-xl font-semibold border-t pt-2"><span>Total</span><span>{{ soles(totales.total) }}</span></div>

                    <label v-if="requiereReceta" class="flex items-start gap-2 pt-2">
                        <Checkbox v-model="form.receta_verificada" binary />
                        <span>Verifiqué la receta médica de los productos que la requieren.</span>
                    </label>
                    <small class="text-red-600 block">{{ form.errors.receta_verificada }}</small>

                    <Message v-if="boletaSinIdentificar" severity="warn" size="small">
                        Las boletas de más de {{ soles(montoIdentificarBoleta) }} deben identificar al cliente con su DNI.
                    </Message>

                    <Button
                        :label="`Emitir ${nombreTipo}`"
                        :icon="esInterno ? 'pi pi-file' : 'pi pi-send'"
                        class="w-full mt-2"
                        size="large"
                        :loading="form.processing"
                        :disabled="!puedeEmitir"
                        @click="emitir"
                    />
                    <p v-if="form.processing" class="text-xs text-center text-slate-500">{{ esInterno ? 'Registrando...' : 'Registrando y enviando a SUNAT...' }}</p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>