<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AutoComplete from 'primevue/autocomplete';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import Textarea from 'primevue/textarea';
import Message from 'primevue/message';
import { useConfirm } from 'primevue/useconfirm';
import { obtenerJson, aFechaISO } from '@/utils/http';

const props = defineProps({
    datos: Object,
    comprobante: Object, // factura o boleta de origen (opcional)
    motivos: Object,
    modalidades: Object,
});

const confirm = useConfirm();

const form = useForm({
    ...props.datos,
    fecha_traslado: new Date(`${props.datos.fecha_traslado}T00:00:00`),
    items: props.datos.items.map((i) => ({ ...i })),
});

const opcionesMotivo = Object.entries(props.motivos).map(([value, label]) => ({ value, label: `${value} · ${label}` }));
const opcionesDoc = [
    { value: '6', label: 'RUC' },
    { value: '1', label: 'DNI' },
    { value: '4', label: 'Carnet de extranjería' },
    { value: '7', label: 'Pasaporte' },
];
const opcionesDocConductor = opcionesDoc.filter((o) => o.value !== '6');
const UNIDADES = [
    { value: 'BX', label: 'Caja (BX)' },
    { value: 'NIU', label: 'Unidad (NIU)' },
    { value: 'KGM', label: 'Kilogramo (KGM)' },
];
const esPrivado = computed(() => form.modalidad === '02');

// ================= PRODUCTOS =================
const productoBuscado = ref('');
const sugerencias = ref([]);
const buscarProductos = async (e) => {
    sugerencias.value = await obtenerJson('/api/productos/buscar', { q: e.query });
};
const agregarProducto = (e) => {
    const p = e.value;
    form.items.push({
        producto_id: p.id,
        codigo: p.codigo,
        descripcion: p.descripcion,
        unidad: p.unidad_venta === 'CJA' ? 'BX' : 'NIU',
        cantidad: 1,
    });
    productoBuscado.value = '';
};
const quitar = (i) => form.items.splice(i, 1);

const error = (i, campo) => form.errors[`items.${i}.${campo}`];

// ================= EMITIR =================
const emitir = () =>
    confirm.require({
        header: 'Emitir guía de remisión',
        message: `Se emitirá la guía para ${form.destinatario_nombre} con ${form.items.length} producto(s) y se enviará a SUNAT. ¿Continuar?`,
        icon: 'pi pi-send',
        acceptProps: { label: 'Emitir y enviar' },
        rejectProps: { label: 'Revisar', severity: 'secondary', outlined: true },
        accept: () =>
            form
                .transform((d) => ({
                    ...d,
                    fecha_traslado: aFechaISO(d.fecha_traslado),
                    // Solo se envían los datos de la modalidad elegida
                    ...(d.modalidad === '02'
                        ? { transportista_ruc: null, transportista_nombre: null, transportista_mtc: null }
                        : { vehiculo_placa: null, conductor_tipo_doc: null, conductor_num_doc: null, conductor_nombres: null, conductor_apellidos: null, conductor_licencia: null }),
                }))
                .post('/guias', { preserveScroll: true }),
    });
</script>

<template>
    <Head title="Nueva guía de remisión" />
    <AppLayout titulo="Nueva guía de remisión">
        <div class="flex items-center gap-2 mb-4">
            <Link :href="comprobante ? `/comprobantes/${comprobante.id}` : '/guias'"><Button label="Volver" icon="pi pi-arrow-left" text /></Link>
            <Message v-if="comprobante" severity="info" size="small" class="flex-1">
                Guía para la {{ comprobante.tipo_nombre }} <b>{{ comprobante.numero }}</b>: se copiaron el cliente, su dirección y los productos con lote y vencimiento.
            </Message>
        </div>
        <Message v-if="form.errors.serie || form.errors.comprobante_id" severity="error" class="mb-4">{{ form.errors.serie || form.errors.comprobante_id }}</Message>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <!-- ===== IZQUIERDA ===== -->
            <div class="xl:col-span-2 space-y-6">
                <!-- Destinatario -->
                <section class="bg-white rounded-xl border border-slate-200 p-5">
                    <h2 class="font-semibold mb-4"><i class="pi pi-user text-emerald-600 mr-1"></i> Destinatario</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Documento</label>
                            <Select v-model="form.destinatario_tipo_doc" :options="opcionesDoc" optionLabel="label" optionValue="value" fluid />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Número *</label>
                            <InputText v-model="form.destinatario_num_doc" :invalid="!!form.errors.destinatario_num_doc" fluid />
                            <small class="text-red-600">{{ form.errors.destinatario_num_doc }}</small>
                        </div>
                        <div class="sm:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Razón social / nombre *</label>
                            <InputText v-model="form.destinatario_nombre" :invalid="!!form.errors.destinatario_nombre" fluid />
                            <small class="text-red-600">{{ form.errors.destinatario_nombre }}</small>
                        </div>
                    </div>
                </section>

                <!-- Traslado -->
                <section class="bg-white rounded-xl border border-slate-200 p-5">
                    <h2 class="font-semibold mb-4"><i class="pi pi-calendar text-emerald-600 mr-1"></i> Traslado</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                        <div class="sm:col-span-2 flex flex-col gap-1">
                            <label class="text-sm">Motivo *</label>
                            <Select v-model="form.motivo" :options="opcionesMotivo" optionLabel="label" optionValue="value" fluid />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Fecha de traslado *</label>
                            <DatePicker v-model="form.fecha_traslado" dateFormat="dd/mm/yy" :minDate="new Date(new Date().toDateString())" showIcon fluid :invalid="!!form.errors.fecha_traslado" />
                            <small class="text-red-600">{{ form.errors.fecha_traslado }}</small>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Peso total *</label>
                            <div class="flex gap-2">
                                <InputNumber v-model="form.peso_total" :min="0" :maxFractionDigits="3" placeholder="Ej. 12.5" fluid :invalid="!!form.errors.peso_total" />
                                <Select v-model="form.unidad_peso" :options="['KGM', 'TNE']" class="w-24" />
                            </div>
                            <small class="text-red-600">{{ form.errors.peso_total }}</small>
                        </div>
                        <div v-if="form.motivo === '13'" class="sm:col-span-4 flex flex-col gap-1">
                            <label class="text-sm">Describe el motivo *</label>
                            <InputText v-model="form.motivo_descripcion" maxlength="100" :invalid="!!form.errors.motivo_descripcion" fluid />
                            <small class="text-red-600">{{ form.errors.motivo_descripcion }}</small>
                        </div>
                    </div>
                </section>

                <!-- Partida y llegada -->
                <section class="bg-white rounded-xl border border-slate-200 p-5">
                    <h2 class="font-semibold mb-1"><i class="pi pi-map text-emerald-600 mr-1"></i> Partida y llegada</h2>
                    <p class="text-xs text-slate-500 mb-4">
                        El <b>ubigeo</b> es el código de 6 dígitos del distrito (ej. 150101 = Lima). El del cliente sale en su ficha RUC de SUNAT.
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Ubigeo partida *</label>
                            <InputText v-model="form.partida_ubigeo" maxlength="6" :invalid="!!form.errors.partida_ubigeo" fluid />
                            <small class="text-red-600">{{ form.errors.partida_ubigeo }}</small>
                        </div>
                        <div class="sm:col-span-3 flex flex-col gap-1">
                            <label class="text-sm">Dirección de partida (tu almacén) *</label>
                            <InputText v-model="form.partida_direccion" :invalid="!!form.errors.partida_direccion" fluid />
                            <small class="text-red-600">{{ form.errors.partida_direccion }}</small>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Ubigeo llegada *</label>
                            <InputText v-model="form.llegada_ubigeo" maxlength="6" :invalid="!!form.errors.llegada_ubigeo" fluid />
                            <small class="text-red-600">{{ form.errors.llegada_ubigeo }}</small>
                        </div>
                        <div class="sm:col-span-3 flex flex-col gap-1">
                            <label class="text-sm">Dirección de llegada (del cliente) *</label>
                            <InputText v-model="form.llegada_direccion" :invalid="!!form.errors.llegada_direccion" fluid />
                            <small class="text-red-600">{{ form.errors.llegada_direccion }}</small>
                        </div>
                    </div>
                </section>

                <!-- Productos -->
                <section class="bg-white rounded-xl border border-slate-200">
                    <div class="p-4 border-b border-slate-100 flex flex-col gap-1">
                        <h2 class="font-semibold"><i class="pi pi-box text-emerald-600 mr-1"></i> Bienes que se trasladan</h2>
                        <AutoComplete
                            v-model="productoBuscado"
                            :suggestions="sugerencias"
                            optionLabel="descripcion"
                            placeholder="Agregar otro producto"
                            :delay="250"
                            fluid
                            @complete="buscarProductos"
                            @option-select="agregarProducto"
                        />
                        <small v-if="form.errors.items" class="text-red-600">{{ form.errors.items }}</small>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                                <tr>
                                    <th class="text-left p-3">Descripción (con lote y vencimiento)</th>
                                    <th class="text-left p-3 w-40">Unidad</th>
                                    <th class="text-left p-3 w-32">Cantidad</th>
                                    <th class="w-12"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!form.items.length">
                                    <td colspan="4" class="p-6 text-center text-slate-400">Agrega los productos que se trasladan.</td>
                                </tr>
                                <tr v-for="(it, i) in form.items" :key="i" class="border-t border-slate-100 align-top">
                                    <td class="p-2">
                                        <InputText v-model="it.descripcion" fluid size="small" :invalid="!!error(i, 'descripcion')" />
                                        <small class="text-xs text-slate-500">{{ it.codigo }}</small>
                                    </td>
                                    <td class="p-2">
                                        <Select v-model="it.unidad" :options="UNIDADES" optionLabel="label" optionValue="value" fluid size="small" />
                                    </td>
                                    <td class="p-2">
                                        <InputNumber v-model="it.cantidad" :min="0" :maxFractionDigits="2" fluid size="small" :invalid="!!error(i, 'cantidad')" />
                                    </td>
                                    <td class="p-2">
                                        <Button icon="pi pi-trash" text rounded severity="danger" @click="quitar(i)" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <!-- ===== DERECHA: TRANSPORTE ===== -->
            <div class="space-y-6">
                <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
                    <h2 class="font-semibold"><i class="pi pi-truck text-emerald-600 mr-1"></i> Transporte</h2>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            class="rounded-lg border-2 p-3 text-left transition-colors"
                            :class="!esPrivado ? 'border-sky-600 bg-sky-50' : 'border-slate-200 hover:bg-slate-50'"
                            @click="form.modalidad = '01'"
                        >
                            <i class="pi pi-building text-sky-600"></i>
                            <p class="font-semibold text-sky-800 text-sm mt-1">Público</p>
                            <p class="text-xs text-slate-500">Empresa de transportes</p>
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border-2 p-3 text-left transition-colors"
                            :class="esPrivado ? 'border-amber-500 bg-amber-50' : 'border-slate-200 hover:bg-slate-50'"
                            @click="form.modalidad = '02'"
                        >
                            <i class="pi pi-car text-amber-600"></i>
                            <p class="font-semibold text-amber-800 text-sm mt-1">Privado</p>
                            <p class="text-xs text-slate-500">Vehículo propio</p>
                        </button>
                    </div>

                    <!-- Transporte público -->
                    <template v-if="!esPrivado">
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">RUC del transportista *</label>
                            <InputText v-model="form.transportista_ruc" maxlength="11" :invalid="!!form.errors.transportista_ruc" fluid />
                            <small class="text-red-600">{{ form.errors.transportista_ruc }}</small>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Razón social *</label>
                            <InputText v-model="form.transportista_nombre" :invalid="!!form.errors.transportista_nombre" fluid />
                            <small class="text-red-600">{{ form.errors.transportista_nombre }}</small>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">N° de registro MTC</label>
                            <InputText v-model="form.transportista_mtc" maxlength="20" fluid />
                        </div>
                    </template>

                    <!-- Transporte privado -->
                    <template v-else>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Placa del vehículo *</label>
                            <InputText v-model="form.vehiculo_placa" maxlength="10" placeholder="Ej. ABC123" :invalid="!!form.errors.vehiculo_placa" fluid />
                            <small class="text-red-600">{{ form.errors.vehiculo_placa }}</small>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div class="flex flex-col gap-1">
                                <label class="text-sm">Doc.</label>
                                <Select v-model="form.conductor_tipo_doc" :options="opcionesDocConductor" optionLabel="label" optionValue="value" fluid />
                            </div>
                            <div class="col-span-2 flex flex-col gap-1">
                                <label class="text-sm">N° documento del conductor *</label>
                                <InputText v-model="form.conductor_num_doc" :invalid="!!form.errors.conductor_num_doc" fluid />
                            </div>
                        </div>
                        <small class="text-red-600">{{ form.errors.conductor_num_doc }}</small>
                        <div class="grid grid-cols-2 gap-2">
                            <div class="flex flex-col gap-1">
                                <label class="text-sm">Nombres *</label>
                                <InputText v-model="form.conductor_nombres" :invalid="!!form.errors.conductor_nombres" fluid />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm">Apellidos *</label>
                                <InputText v-model="form.conductor_apellidos" :invalid="!!form.errors.conductor_apellidos" fluid />
                            </div>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">Licencia de conducir *</label>
                            <InputText v-model="form.conductor_licencia" maxlength="20" :invalid="!!form.errors.conductor_licencia" fluid />
                            <small class="text-red-600">{{ form.errors.conductor_licencia }}</small>
                        </div>
                    </template>
                </section>

                <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Observaciones</label>
                        <Textarea v-model="form.observaciones" rows="2" autoResize maxlength="250" fluid placeholder="Ej. Productos de cadena de frío: transportar a 2-8 °C" />
                    </div>
                    <Button
                        label="Emitir guía y enviar a SUNAT"
                        icon="pi pi-send"
                        class="w-full"
                        size="large"
                        :loading="form.processing"
                        :disabled="!form.items.length || !form.peso_total"
                        @click="emitir"
                    />
                    <p v-if="form.processing" class="text-xs text-center text-slate-500">Enviando a SUNAT y esperando su respuesta…</p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>