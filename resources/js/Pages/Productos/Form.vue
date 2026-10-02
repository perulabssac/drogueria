<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import ToggleSwitch from 'primevue/toggleswitch';
import Message from 'primevue/message';
import { precio } from '@/utils/formato';

const props = defineProps({
    producto: Object, // null al crear
    tieneLotes: Boolean,
    laboratorios: Array,
    categorias: Array,
    afectaciones: Object,
    condiciones: Object,
    unidadesVenta: Object,
    unidadesFraccion: Object,
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
              unidades_por_presentacion: p.fraccionable ? p.unidades_por_presentacion : null,
              unidad_fraccion: p.unidad_fraccion ?? 'TAB',
              laboratorio_nuevo: '',
          }
        : { ...vacio },
);

const guardar = () => {
    editando.value ? form.put(`/productos/${p.id}`) : form.post('/productos');
};

// Precio sugerido por unidad suelta (precio de la presentación / unidades)
const precioFraccionSugerido = computed(() => {
    if (!form.precio_venta || !form.unidades_por_presentacion) return null;
    return form.precio_venta / form.unidades_por_presentacion;
});

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

                <!-- INVENTARIO -->
                <section class="bg-white rounded-xl border border-slate-200 p-5">
                    <h2 class="font-semibold mb-4">Inventario</h2>
                    <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                        <div class="md:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Costo por {{ form.unidad_venta }} (sin IGV)</label>
                            <InputNumber v-model="form.costo" prefix="S/ " :minFractionDigits="2" :maxFractionDigits="4" fluid />
                            <small class="text-slate-500">Se actualiza solo con cada compra.</small>
                        </div>
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
                            <InputNumber v-model="form.precio_venta" prefix="S/ " :minFractionDigits="3" :maxFractionDigits="3" :invalid="!!form.errors.precio_venta" fluid />
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
                            <InputNumber v-model="form.precio_fraccion" prefix="S/ " :minFractionDigits="3" :maxFractionDigits="3" :invalid="!!form.errors.precio_fraccion" fluid />
                            <small v-if="form.errors.precio_fraccion" class="text-red-600">{{ form.errors.precio_fraccion }}</small>
                            <small v-else-if="precioFraccionSugerido" class="text-slate-500">Sin recargo sería {{ precio(precioFraccionSugerido) }}</small>
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