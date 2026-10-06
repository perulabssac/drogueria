<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import ToggleSwitch from 'primevue/toggleswitch';
import Message from 'primevue/message';
import { precio, soles } from '@/utils/formato';
import { precioSugerido, analizarPrecio } from '@/utils/precios';

const props = defineProps({
    producto: Object, // null al crear
    tieneLotes: Boolean,
    laboratorios: Array,
    categorias: Array,
    afectaciones: Object,
    condiciones: Object,
    unidadesVenta: Object,
    unidadesFraccion: Object,
    margenes: Array, // 10, 15, 20 ... 100
    redondeo: Number, // redondeo del precio calculado (ej. 0.10)
});

const editando = computed(() => !!props.producto);

// ---------- Opciones de los selects ----------
const aOpciones = (obj, conCodigo = false) =>
    Object.entries(obj).map(([value, label]) => ({ value, label: conCodigo ? `${value} - ${label}` : label }));
const opcionesAfectacion = aOpciones(props.afectaciones);
const opcionesCondicion = aOpciones(props.condiciones);
const opcionesUnidadVenta = aOpciones(props.unidadesVenta, true);
const opcionesUnidadFraccion = aOpciones(props.unidadesFraccion, true);

// ---------- Formulario ----------
const vacio = {
    codigo: '',
    codigo_barras: '',
    nombre: '',
    principio_activo: '',
    concentracion: '',
    forma_farmaceutica: '',
    presentacion: '',
    categoria: '',
    laboratorio_id: null,
    laboratorio_nuevo: '',
    registro_sanitario: '',
    condicion_venta: 'sin_receta',
    controlado: false,
    cadena_frio: false,
    tipo_afectacion_igv: '10',
    unidad_venta: 'CJA',
    precio_venta: null,
    fraccionable: false,
    unidades_por_presentacion: null,
    unidad_fraccion: 'TAB',
    precio_fraccion: null,
    costo: null,
    margen: null,
    stock_minimo: 0,
    activo: true,
};

const numeroONulo = (v) => (v === null || v === undefined ? null : Number(v));
const p = props.producto;

// Al editar, se llena con los datos del producto; al crear, con los valores vacíos
const form = useForm(
    p
        ? {
              ...vacio,
              ...Object.fromEntries(Object.keys(vacio).map((k) => [k, p[k] ?? vacio[k]])),
              precio_venta: numeroONulo(p.precio_venta),
              precio_fraccion: numeroONulo(p.precio_fraccion),
              costo: numeroONulo(p.costo),
              margen: numeroONulo(p.margen),
              unidades_por_presentacion: p.fraccionable ? p.unidades_por_presentacion : null,
              unidad_fraccion: p.unidad_fraccion ?? 'TAB',
              laboratorio_nuevo: '',
          }
        : { ...vacio },
);

const guardar = () => {
    editando.value ? form.put(`/productos/${p.id}`) : form.post('/productos');
};

// ---------- Margen de ganancia (sobre el costo) ----------
// Lista del 10 % al 100 % y "Otro…" para escribir un margen exacto
const opcionesMargen = [
    ...props.margenes.map((m) => ({ value: m, label: `${m} %` })),
    { value: 'otro', label: 'Otro…' },
];
const margenSeleccion = ref(form.margen === null ? null : props.margenes.includes(form.margen) ? form.margen : 'otro');
watch(margenSeleccion, (valor) => {
    if (valor !== 'otro') form.margen = valor;
});

const gravado = computed(() => form.tipo_afectacion_igv === '10');
const factor = computed(() => (form.fraccionable && form.unidades_por_presentacion > 1 ? form.unidades_por_presentacion : null));
const costoFraccion = computed(() => (factor.value && form.costo ? form.costo / factor.value : null));

// Precios calculados con el costo y el margen
const precioCalculado = computed(() => precioSugerido(form.costo, form.margen, gravado.value, props.redondeo));
// La unidad suelta se redondea al céntimo (con 0.05 o 0.10 el margen se dispararía en productos baratos)
const fraccionCalculada = computed(() => precioSugerido(costoFraccion.value, form.margen, gravado.value, 0.01));

// Al cambiar el costo, el margen, el IGV o el fraccionamiento, el precio se recalcula solo.
// Después se puede corregir a mano: se respeta hasta el siguiente cambio de costo o margen.
let conservarPrecio = false; // true al guardar el margen real de un precio puesto a mano
watch(
    () => [form.costo, form.margen, form.tipo_afectacion_igv, form.fraccionable, form.unidades_por_presentacion],
    () => {
        if (conservarPrecio) return;
        if (precioCalculado.value) form.precio_venta = precioCalculado.value;
        if (fraccionCalculada.value) form.precio_fraccion = fraccionCalculada.value;
    },
);

const aplicarCalculado = () => {
    form.precio_venta = precioCalculado.value;
    if (fraccionCalculada.value) form.precio_fraccion = fraccionCalculada.value;
};
const precioEditado = computed(() => precioCalculado.value && form.precio_venta && Math.abs(form.precio_venta - precioCalculado.value) > 0.001);

// Margen real del precio escrito a mano (se trunca a 2 decimales para que, al recalcular, salga el mismo precio)
const margenReal = computed(() => {
    if (!form.costo || !form.precio_venta) return null;
    const sinIgv = gravado.value ? form.precio_venta / 1.18 : form.precio_venta;
    return Math.floor(Number(((sinIgv / form.costo - 1) * 10000).toFixed(6))) / 100;
});
// Se ofrece guardarlo si el precio se puso a mano (sin margen o distinto al calculado) y deja ganancia
const ofrecerMargenReal = computed(
    () =>
        margenReal.value !== null &&
        margenReal.value > 0 &&
        (form.margen === null || precioEditado.value) &&
        Math.abs(margenReal.value - (form.margen ?? -1)) >= 0.01,
);
// Guarda como margen del producto la ganancia del precio escrito a mano, sin tocar ese precio
const guardarMargenReal = () => {
    const m = margenReal.value;
    conservarPrecio = true;
    form.margen = m;
    margenSeleccion.value = props.margenes.includes(m) ? m : 'otro';
    nextTick(() => (conservarPrecio = false));
};

// Utilidad real con el precio que quedó (calculado o escrito a mano)
const utilidad = computed(() => analizarPrecio(form.precio_venta, form.costo, gravado.value));
const utilidadFraccion = computed(() => (factor.value ? analizarPrecio(form.precio_fraccion, costoFraccion.value, gravado.value) : null));

// Verde: buen margen · ámbar: bajo · rojo: pérdida
const colorMargen = (m) =>
    m === null || m === undefined ? 'text-slate-500' : m < 0 ? 'text-red-600' : m < 10 ? 'text-amber-600' : 'text-emerald-700';
const pct = (v) => (v === null || v === undefined ? '—' : `${v.toFixed(1)} %`);

const titulo = computed(() => (editando.value ? `Editar: ${p.nombre} ${p.concentracion ?? ''}` : 'Nuevo producto'));
</script>

<template>
    <Head :title="titulo" />
    <AppLayout :titulo="titulo">
        <!-- En pantallas grandes: 2 columnas (datos del producto | venta) -->
        <form class="grid grid-cols-1 xl:grid-cols-3 gap-6" @submit.prevent="guardar">
            <!-- ===== COLUMNA IZQUIERDA ===== -->
            <div class="xl:col-span-2 space-y-6">
                <!-- IDENTIFICACIÓN -->
                <section class="bg-white rounded-xl border border-slate-200 p-5">
                    <h2 class="font-semibold mb-4">Identificación</h2>
                    <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                        <div class="md:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Código *</label>
                            <InputText v-model="form.codigo" :invalid="!!form.errors.codigo" autofocus />
                            <small class="text-red-600">{{ form.errors.codigo }}</small>
                        </div>
                        <div class="md:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Código de barras</label>
                            <InputText v-model="form.codigo_barras" />
                        </div>
                        <div class="md:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Registro sanitario</label>
                            <InputText v-model="form.registro_sanitario" placeholder="EN-01234" />
                        </div>

                        <div class="md:col-span-3 flex flex-col gap-1">
                            <label class="text-sm">Nombre comercial *</label>
                            <InputText v-model="form.nombre" :invalid="!!form.errors.nombre" />
                            <small class="text-red-600">{{ form.errors.nombre }}</small>
                        </div>
                        <div class="md:col-span-3 flex flex-col gap-1">
                            <label class="text-sm">Principio activo (DCI)</label>
                            <InputText v-model="form.principio_activo" />
                        </div>

                        <div class="md:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Concentración</label>
                            <InputText v-model="form.concentracion" placeholder="500 mg" />
                        </div>
                        <div class="md:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Forma farmacéutica</label>
                            <InputText v-model="form.forma_farmaceutica" placeholder="Tableta" />
                        </div>
                        <div class="md:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Presentación</label>
                            <InputText v-model="form.presentacion" placeholder="CJA X 100 TAB" />
                        </div>

                        <div class="md:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Laboratorio</label>
                            <Select v-model="form.laboratorio_id" :options="laboratorios" optionLabel="nombre" optionValue="id" filter showClear placeholder="Seleccionar" :disabled="!!form.laboratorio_nuevo" fluid />
                        </div>
                        <div class="md:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">¿No está? Escríbelo</label>
                            <InputText v-model="form.laboratorio_nuevo" placeholder="Nuevo laboratorio" />
                        </div>
                        <div class="md:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Categoría</label>
                            <!-- datalist: sugiere las categorías existentes pero permite escribir una nueva -->
                            <InputText v-model="form.categoria" list="lista-categorias" placeholder="ANTIBIÓTICOS" />
                            <datalist id="lista-categorias">
                                <option v-for="c in categorias" :key="c" :value="c" />
                            </datalist>
                        </div>
                    </div>
                </section>

                <!-- COSTO, MARGEN Y UTILIDAD -->
                <section class="bg-white rounded-xl border border-slate-200 p-5">
                    <h2 class="font-semibold mb-4">Costo y margen de ganancia</h2>
                    <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                        <div class="md:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Costo por {{ form.unidad_venta }} (sin IGV)</label>
                            <InputNumber v-model="form.costo" prefix="S/ " :minFractionDigits="2" :maxFractionDigits="4" fluid />
                            <small class="text-slate-500">Se actualiza solo con cada compra.</small>
                        </div>
                        <div class="md:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Margen de ganancia (sobre el costo)</label>
                            <Select v-model="margenSeleccion" :options="opcionesMargen" optionLabel="label" optionValue="value" placeholder="Elegir margen" showClear fluid />
                            <small v-if="form.errors.margen" class="text-red-600">{{ form.errors.margen }}</small>
                            <small v-else-if="form.margen === null" class="text-amber-600">Sin margen: el precio no se calcula solo.</small>
                        </div>
                        <div class="md:col-span-2 flex flex-col gap-1">
                            <template v-if="margenSeleccion === 'otro'">
                                <label class="text-sm">Margen exacto</label>
                                <InputNumber v-model="form.margen" suffix=" %" :min="0" :max="1000" :minFractionDigits="0" :maxFractionDigits="2" fluid />
                            </template>
                            <template v-else-if="precioCalculado">
                                <label class="text-sm">Precio calculado (con IGV)</label>
                                <p class="text-lg font-semibold text-emerald-700 py-1.5">{{ soles(precioCalculado) }}</p>
                            </template>
                        </div>
                    </div>

                    <!-- Utilidad con el precio actual -->
                    <div v-if="utilidad" class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                            <p class="font-medium">Utilidad con el precio de venta</p>
                            <Button
                                v-if="precioEditado"
                                type="button"
                                size="small"
                                severity="secondary"
                                outlined
                                icon="pi pi-refresh"
                                :label="`Usar precio calculado (${soles(precioCalculado)})`"
                                @click="aplicarCalculado"
                            />
                        </div>
                        <table class="w-full">
                            <thead class="text-xs text-slate-500">
                                <tr>
                                    <th class="text-left font-normal pb-1"></th>
                                    <th class="text-right font-normal pb-1">Costo c/IGV</th>
                                    <th class="text-right font-normal pb-1">Precio s/IGV</th>
                                    <th class="text-right font-normal pb-1">Ganancia</th>
                                    <th class="text-right font-normal pb-1">Margen s/costo</th>
                                    <th class="text-right font-normal pb-1">Margen s/venta</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="border-t border-slate-200">
                                    <td class="py-1.5">Por {{ form.unidad_venta }}</td>
                                    <td class="text-right">{{ soles(utilidad.costoConIgv) }}</td>
                                    <td class="text-right">{{ soles(utilidad.precioSinIgv) }}</td>
                                    <td class="text-right font-semibold" :class="colorMargen(utilidad.margenCosto)">{{ soles(utilidad.ganancia) }}</td>
                                    <td class="text-right font-semibold" :class="colorMargen(utilidad.margenCosto)">{{ pct(utilidad.margenCosto) }}</td>
                                    <td class="text-right" :class="colorMargen(utilidad.margenVenta)">{{ pct(utilidad.margenVenta) }}</td>
                                </tr>
                                <tr v-if="utilidadFraccion" class="border-t border-slate-200">
                                    <td class="py-1.5">Por {{ form.unidad_fraccion }}</td>
                                    <td class="text-right">{{ precio(utilidadFraccion.costoConIgv) }}</td>
                                    <td class="text-right">{{ precio(utilidadFraccion.precioSinIgv) }}</td>
                                    <td class="text-right font-semibold" :class="colorMargen(utilidadFraccion.margenCosto)">{{ precio(utilidadFraccion.ganancia) }}</td>
                                    <td class="text-right font-semibold" :class="colorMargen(utilidadFraccion.margenCosto)">{{ pct(utilidadFraccion.margenCosto) }}</td>
                                    <td class="text-right" :class="colorMargen(utilidadFraccion.margenVenta)">{{ pct(utilidadFraccion.margenVenta) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="utilidad.ganancia < 0" class="text-red-600 font-medium mt-2"><i class="pi pi-exclamation-triangle mr-1"></i>Con este precio se vende a pérdida.</p>
                        <div v-else-if="ofrecerMargenReal" class="mt-3 flex flex-wrap items-center gap-2 rounded-lg border border-violet-200 bg-violet-50 p-2">
                            <span class="text-violet-900">
                                {{ form.margen === null ? 'Precio puesto a mano' : 'El precio fue cambiado a mano' }}: ganas
                                <b>{{ margenReal }} %</b> sobre el costo. Guárdalo como margen para que las próximas compras sugieran el precio.
                            </span>
                            <Button
                                type="button"
                                size="small"
                                severity="help"
                                icon="pi pi-percentage"
                                class="ml-auto"
                                :label="`Guardar ${margenReal} % como margen`"
                                @click="guardarMargenReal"
                            />
                        </div>
                    </div>
                    <p v-else class="mt-3 text-sm text-slate-500">Ingresa el costo y el precio de venta para ver la ganancia.</p>
                </section>

                <!-- INVENTARIO -->
                <section class="bg-white rounded-xl border border-slate-200 p-5">
                    <h2 class="font-semibold mb-4">Inventario</h2>
                    <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                        <div class="md:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Stock mínimo ({{ form.unidad_venta }})</label>
                            <InputNumber v-model="form.stock_minimo" :min="0" fluid />
                        </div>
                        <div class="md:col-span-2 flex items-center pt-6">
                            <label class="flex items-center gap-2 text-sm"><ToggleSwitch v-model="form.activo" /> Activo</label>
                        </div>
                    </div>
                </section>
            </div>

            <!-- ===== COLUMNA DERECHA ===== -->
            <div class="space-y-6">
                <!-- VENTA Y SUNAT -->
                <section class="bg-white rounded-xl border border-slate-200 p-5">
                    <h2 class="font-semibold mb-4">Venta y SUNAT</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Unidad de venta *</label>
                            <Select v-model="form.unidad_venta" :options="opcionesUnidadVenta" optionLabel="label" optionValue="value" fluid />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Precio por {{ form.unidad_venta }} (con IGV) *</label>
                            <InputNumber v-model="form.precio_venta" prefix="S/ " :minFractionDigits="2" :maxFractionDigits="2" :invalid="!!form.errors.precio_venta" fluid />
                            <small class="text-red-600">{{ form.errors.precio_venta }}</small>
                        </div>
                        <div class="sm:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Afectación IGV *</label>
                            <Select v-model="form.tipo_afectacion_igv" :options="opcionesAfectacion" optionLabel="label" optionValue="value" fluid />
                        </div>
                        <div class="sm:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Condición de venta *</label>
                            <Select v-model="form.condicion_venta" :options="opcionesCondicion" optionLabel="label" optionValue="value" fluid />
                        </div>
                        <div class="sm:col-span-2 flex flex-col gap-3">
                            <label class="flex items-center gap-2 text-sm"><ToggleSwitch v-model="form.controlado" /> Controlado (DIGEMID)</label>
                            <label class="flex items-center gap-2 text-sm"><ToggleSwitch v-model="form.cadena_frio" /> Cadena de frío (2 a 8 °C)</label>
                        </div>
                    </div>
                </section>

                <!-- FRACCIONAMIENTO -->
                <section class="bg-white rounded-xl border border-slate-200 p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="font-semibold">Venta por unidad suelta</h2>
                        <ToggleSwitch v-model="form.fraccionable" :disabled="tieneLotes" />
                    </div>
                    <p class="text-sm text-slate-500 mt-1">{{ form.fraccionable ? 'Sí, también se vende fraccionado' : 'No, solo por ' + form.unidad_venta }}</p>
                    <Message v-if="tieneLotes" severity="secondary" size="small" class="mt-3">
                        Este producto ya tiene lotes: el fraccionamiento no se puede cambiar para no descuadrar el stock.
                    </Message>
                    <Message v-if="form.errors.fraccionable" severity="error" size="small" class="mt-3">{{ form.errors.fraccionable }}</Message>

                    <div v-if="form.fraccionable" class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Unidades por {{ form.unidad_venta }} *</label>
                            <InputNumber v-model="form.unidades_por_presentacion" :min="2" :disabled="tieneLotes" :invalid="!!form.errors.unidades_por_presentacion" fluid />
                            <small class="text-red-600">{{ form.errors.unidades_por_presentacion }}</small>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Unidad suelta *</label>
                            <Select v-model="form.unidad_fraccion" :options="opcionesUnidadFraccion" optionLabel="label" optionValue="value" fluid />
                        </div>
                        <div class="sm:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Precio por {{ form.unidad_fraccion }} (con IGV) *</label>
                            <InputNumber v-model="form.precio_fraccion" prefix="S/ " :minFractionDigits="2" :maxFractionDigits="3" :invalid="!!form.errors.precio_fraccion" fluid />
                            <small v-if="form.errors.precio_fraccion" class="text-red-600">{{ form.errors.precio_fraccion }}</small>
                            <small v-else-if="fraccionCalculada" class="text-slate-500">Calculado con el margen: {{ soles(fraccionCalculada) }}</small>
                        </div>
                    </div>
                </section>
            </div>

            <!-- ACCIONES -->
            <div class="xl:col-span-3 flex justify-end gap-2">
                <Link href="/productos"><Button label="Cancelar" severity="secondary" text /></Link>
                <Button type="submit" :label="editando ? 'Guardar cambios' : 'Registrar producto'" icon="pi pi-check" :loading="form.processing" />
            </div>
        </form>
    </AppLayout>
</template>