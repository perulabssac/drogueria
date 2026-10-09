<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { soles, fecha } from '@/utils/formato';

const props = defineProps({
    vista: Object, // null si no hay archivo pendiente
    actualizar: Boolean,
    historial: Array,
    maxFilas: Number,
});

const errores = computed(() => usePage().props.errors ?? {});

// ===== Paso 3: subir el archivo =====
const entrada = ref(null);
const formArchivo = useForm({ archivo: null });
const elegirArchivo = (e) => (formArchivo.archivo = e.target.files[0] ?? null);
const subir = () =>
    formArchivo.post('/importar', {
        preserveScroll: true,
        onFinish: () => {
            formArchivo.reset();
            if (entrada.value) entrada.value.value = '';
        },
    });

// ===== Vista previa =====
const resumen = computed(() => props.vista?.resumen);
const hayErrores = computed(() => (resumen.value?.con_error ?? 0) > 0);

const filtro = ref('todas');
const FILTROS = [
    { value: 'todas', label: 'Todas', activo: 'bg-slate-700 border-slate-700 text-white', inactivo: 'bg-slate-50 border-slate-300 text-slate-700 hover:bg-slate-100' },
    { value: 'error', label: 'Con error', activo: 'bg-red-600 border-red-600 text-white', inactivo: 'bg-red-50 border-red-200 text-red-800 hover:bg-red-100' },
    { value: 'aviso', label: 'Con aviso', activo: 'bg-amber-500 border-amber-500 text-white', inactivo: 'bg-amber-50 border-amber-200 text-amber-800 hover:bg-amber-100' },
];
const filas = computed(() => {
    const todas = props.vista?.filas ?? [];
    if (filtro.value === 'error') return todas.filter((f) => f.errores.length);
    if (filtro.value === 'aviso') return todas.filter((f) => f.avisos.length);
    return todas;
});

// Cambiar la opción vuelve a analizar el archivo (cambia qué se valida de los productos existentes)
const cambiarActualizar = (valor) => router.get('/importar', valor ? { actualizar: 1 } : {}, { preserveScroll: true, replace: true });

const importando = ref(false);
const confirmar = () =>
    router.post('/importar/confirmar', { actualizar: props.actualizar }, {
        preserveScroll: true,
        onStart: () => (importando.value = true),
        onFinish: () => (importando.value = false),
    });
const cancelar = () => router.post('/importar/cancelar', {}, { preserveScroll: true });
</script>

<template>
    <Head title="Importar productos" />
    <AppLayout titulo="Importar productos y stock">
        <!-- Pasos 1, 2 y 3 -->
        <section class="grid md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <p class="text-xs font-semibold text-violet-700 uppercase">Paso 1</p>
                <p class="font-semibold mt-1">Descarga la plantilla</p>
                <p class="text-sm text-slate-500 mt-1 mb-3">Excel con las columnas, listas desplegables y una hoja de instrucciones con ejemplos.</p>
                <a href="/importar/plantilla"><Button label="Descargar plantilla" icon="pi pi-file-excel" severity="help" /></a>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <p class="text-xs font-semibold text-sky-700 uppercase">Paso 2</p>
                <p class="font-semibold mt-1">Llénala</p>
                <ul class="text-sm text-slate-500 mt-1 list-disc pl-5 space-y-0.5">
                    <li>Una fila por cada <b>lote</b>.</li>
                    <li>Cantidad en presentaciones; las sueltas, aparte.</li>
                    <li>Costo por presentación, sin IGV.</li>
                    <li><b>Margen %</b> (el precio se calcula solo) o el precio de venta.</li>
                    <li>Máximo {{ maxFilas }} filas por archivo.</li>
                </ul>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <p class="text-xs font-semibold text-emerald-700 uppercase">Paso 3</p>
                <p class="font-semibold mt-1">Súbela y revisa</p>
                <p class="text-sm text-slate-500 mt-1 mb-3">Nada se guarda hasta que confirmes la importación.</p>
                <input ref="entrada" type="file" accept=".xlsx" class="block w-full text-sm mb-2 file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-slate-100 file:text-slate-700" @change="elegirArchivo" />
                <small v-if="errores.archivo && !vista" class="text-red-600 block mb-2">{{ errores.archivo }}</small>
                <Button label="Subir y revisar" icon="pi pi-upload" severity="info" :loading="formArchivo.processing" :disabled="!formArchivo.archivo" @click="subir" />
            </div>
        </section>

        <!-- Vista previa -->
        <template v-if="vista">
            <section class="bg-white rounded-xl border border-slate-200 p-5 mb-6">
                <div class="flex flex-wrap items-center gap-2 mb-4">
                    <i class="pi pi-file-excel text-emerald-700 text-xl"></i>
                    <h2 class="font-semibold">Vista previa: {{ vista.archivo }}</h2>
                </div>

                <!-- Resumen -->
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3 mb-4">
                    <div class="rounded-lg border border-slate-200 p-3">
                        <p class="text-xs text-slate-500">Filas</p>
                        <p class="text-xl font-semibold">{{ resumen.filas }}</p>
                    </div>
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                        <p class="text-xs text-emerald-700">Productos nuevos</p>
                        <p class="text-xl font-semibold text-emerald-800">{{ resumen.productos_nuevos }}</p>
                    </div>
                    <div class="rounded-lg border border-sky-200 bg-sky-50 p-3">
                        <p class="text-xs text-sky-700">Ya registrados</p>
                        <p class="text-xl font-semibold text-sky-800">{{ resumen.productos_existentes }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3">
                        <p class="text-xs text-slate-500">Lotes a ingresar</p>
                        <p class="text-xl font-semibold">{{ resumen.lotes }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 p-3">
                        <p class="text-xs text-slate-500">Valor del stock (costo)</p>
                        <p class="text-xl font-semibold">{{ soles(resumen.valor) }}</p>
                    </div>
                    <div class="rounded-lg border p-3" :class="hayErrores ? 'border-red-200 bg-red-50' : 'border-emerald-200 bg-emerald-50'">
                        <p class="text-xs" :class="hayErrores ? 'text-red-700' : 'text-emerald-700'">Filas con error</p>
                        <p class="text-xl font-semibold" :class="hayErrores ? 'text-red-700' : 'text-emerald-800'">{{ resumen.con_error }}</p>
                    </div>
                </div>

                <Message v-if="hayErrores" severity="error" class="mb-4">
                    Hay <b>{{ resumen.con_error }}</b> fila(s) con error. Corrígelas en el Excel y vuelve a subirlo: no se importa nada hasta que el archivo esté limpio.
                </Message>
                <Message v-else severity="success" class="mb-4">
                    El archivo está listo para importar.<span v-if="resumen.con_aviso"> Revisa los {{ resumen.con_aviso }} aviso(s): no impiden importar.</span>
                </Message>
                <Message v-if="errores.archivo" severity="error" class="mb-4">{{ errores.archivo }}</Message>

                <!-- Opción: actualizar productos existentes -->
                <label v-if="resumen.productos_existentes" class="flex items-start gap-2 text-sm mb-4">
                    <Checkbox :modelValue="actualizar" binary @update:modelValue="cambiarActualizar" class="mt-0.5" />
                    <span>
                        <b>Actualizar los datos de los {{ resumen.productos_existentes }} producto(s) que ya existen</b> (nombre, precios, costo, laboratorio...).
                        <span class="text-slate-500">Si no lo marcas, a esos productos solo se les agrega el stock.</span>
                    </span>
                </label>

                <!-- Filtro -->
                <div class="flex flex-wrap gap-2 mb-3">
                    <button
                        v-for="f in FILTROS"
                        :key="f.value"
                        type="button"
                        class="px-3 py-1.5 rounded-lg border text-sm font-medium transition"
                        :class="filtro === f.value ? f.activo : f.inactivo"
                        @click="filtro = f.value"
                    >
                        {{ f.label }}
                    </button>
                </div>

                <DataTable :value="filas" size="small" paginator :rows="25" dataKey="fila">
                    <template #empty>No hay filas para este filtro.</template>
                    <Column header="Fila" class="w-14"><template #body="{ data }">{{ data.fila }}</template></Column>
                    <Column header="Código"><template #body="{ data }">{{ data.codigo }}</template></Column>
                    <Column header="Producto">
                        <template #body="{ data }">
                            {{ data.nombre }}
                            <Tag :value="data.estado === 'nuevo' ? 'Nuevo' : 'Ya existe'" :severity="data.estado === 'nuevo' ? 'success' : 'info'" class="ml-1" />
                        </template>
                    </Column>
                    <Column header="Precio venta" class="text-right">
                        <template #body="{ data }">
                            <template v-if="data.precio">
                                <span class="font-medium">{{ soles(data.precio) }}</span>
                                <p class="text-xs" :class="data.precio_calculado ? 'text-violet-700' : 'text-slate-500'">
                                    <template v-if="data.margen !== null">margen {{ data.margen }} %</template>
                                    <template v-else>sin margen</template>
                                    <span v-if="data.precio_calculado"> · calculado</span>
                                </p>
                            </template>
                            <span v-else>—</span>
                        </template>
                    </Column>
                    <Column header="Lote"><template #body="{ data }">{{ data.lote ?? '—' }}</template></Column>
                    <Column header="Vence"><template #body="{ data }">{{ data.vencimiento ? fecha(data.vencimiento) : '—' }}</template></Column>
                    <Column header="Cantidad"><template #body="{ data }">{{ data.cantidad ?? '—' }}</template></Column>
                    <Column header="Valor" class="text-right"><template #body="{ data }">{{ data.valor ? soles(data.valor) : '—' }}</template></Column>
                    <Column header="Revisión">
                        <template #body="{ data }">
                            <p v-for="(e, i) in data.errores" :key="'e' + i" class="text-red-700 text-xs"><i class="pi pi-times-circle mr-1"></i>{{ e }}</p>
                            <p v-for="(a, i) in data.avisos" :key="'a' + i" class="text-amber-700 text-xs"><i class="pi pi-exclamation-triangle mr-1"></i>{{ a }}</p>
                            <span v-if="!data.errores.length && !data.avisos.length" class="text-emerald-700 text-xs"><i class="pi pi-check mr-1"></i>Correcto</span>
                        </template>
                    </Column>
                </DataTable>

                <div class="flex flex-wrap justify-end gap-2 mt-4">
                    <Button label="Cancelar" icon="pi pi-times" severity="secondary" outlined @click="cancelar" />
                    <Button
                        :label="`Importar ${resumen.filas} fila(s)`"
                        icon="pi pi-check"
                        :loading="importando"
                        :disabled="hayErrores"
                        @click="confirmar"
                    />
                </div>
            </section>
        </template>

        <!-- Historial -->
        <section class="bg-white rounded-xl border border-slate-200">
            <h2 class="font-semibold p-4 border-b border-slate-100">Importaciones anteriores</h2>
            <DataTable :value="historial" size="small">
                <template #empty>Aún no se ha importado ningún archivo.</template>
                <Column header="N°"><template #body="{ data }">{{ data.numero }}</template></Column>
                <Column header="Fecha"><template #body="{ data }">{{ fecha(data.created_at) }} {{ String(data.created_at).substring(11, 16) }}</template></Column>
                <Column header="Archivo"><template #body="{ data }">{{ data.archivo }}</template></Column>
                <Column header="Nuevos" class="text-right"><template #body="{ data }">{{ data.productos_nuevos }}</template></Column>
                <Column header="Actualizados" class="text-right"><template #body="{ data }">{{ data.productos_actualizados }}</template></Column>
                <Column header="Lotes" class="text-right"><template #body="{ data }">{{ data.lotes }}</template></Column>
                <Column header="Valor" class="text-right"><template #body="{ data }">{{ soles(data.valor) }}</template></Column>
                <Column header="Usuario"><template #body="{ data }">{{ data.usuario?.name }}</template></Column>
            </DataTable>
        </section>
    </AppLayout>
</template>