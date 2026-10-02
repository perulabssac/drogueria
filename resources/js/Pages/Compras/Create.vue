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
import Checkbox from 'primevue/checkbox';
import Textarea from 'primevue/textarea';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { soles } from '@/utils/formato';
import { obtenerJson, aFechaISO, finDeMes } from '@/utils/http';

const props = defineProps({
    tiposDocumento: Object,
});

const TASA_IGV = 0.18;
const opcionesTipo = Object.entries(props.tiposDocumento).map(([value, label]) => ({ value, label }));
const opcionesPago = [
    { value: 'contado', label: 'Contado' },
    { value: 'credito', label: 'Crédito' },
];

// ---------- Cabecera del documento ----------
const proveedor = ref(null);
const sugerenciasProveedor = ref([]);
const buscarProveedores = async (e) => {
    sugerenciasProveedor.value = await obtenerJson('/api/proveedores/buscar', { q: e.query });
};

const form = useForm({
    proveedor_id: null,
    tipo_documento: '01',
    serie: '',
    numero: '',
    fecha_emision: new Date(),
    forma_pago: 'contado',
    fecha_vencimiento: null,
    observaciones: '',
    items: [],
});

// ---------- Líneas (una por producto + lote, igual que en la factura) ----------
const productoBuscado = ref('');
const sugerenciasProducto = ref([]);
const buscarProductos = async (e) => {
    sugerenciasProducto.value = await obtenerJson('/api/productos/buscar', { q: e.query });
};

const agregarProducto = (e) => {
    const p = e.value;
    const gravado = p.tipo_afectacion_igv === '10';
    form.items.push({
        producto: p,
        cantidad: 1,
        numero_lote: '',
        vence: null, // mes/año, como viene impreso en la factura
        // Sugerimos el último costo (se guarda sin IGV; en la factura viene con IGV)
        precio_unitario: p.costo ? Number((p.costo * (gravado ? 1 + TASA_IGV : 1)).toFixed(3)) : null,
        bonificacion: false,
    });
    productoBuscado.value = '';
};

const quitar = (i) => form.items.splice(i, 1);

const importe = (l) => (l.bonificacion ? 0 : (Number(l.cantidad) || 0) * (Number(l.precio_unitario) || 0));

// ---------- Totales (mismo cálculo que hace Laravel al guardar) ----------
const totales = computed(() => {
    let gravadas = 0;
    let exoneradas = 0;
    let total = 0;
    for (const l of form.items) {
        const imp = Math.round(importe(l) * 100) / 100;
        total += imp;
        if (l.producto.tipo_afectacion_igv === '10') gravadas += Math.round((imp / (1 + TASA_IGV)) * 100) / 100;
        else exoneradas += imp;
    }
    return { gravadas, exoneradas, igv: total - gravadas - exoneradas, total };
});

// Para comparar con el total impreso en la factura del proveedor
const totalDocumento = ref(null);
const diferencia = computed(() =>
    totalDocumento.value === null ? null : Math.round((totalDocumento.value - totales.value.total) * 100) / 100,
);

const error = (i, campo) => form.errors[`items.${i}.${campo}`];

const guardar = () => {
    form
        .transform((d) => ({
            ...d,
            proveedor_id: proveedor.value?.id ?? null,
            fecha_emision: aFechaISO(d.fecha_emision),
            fecha_vencimiento: d.forma_pago === 'credito' ? aFechaISO(d.fecha_vencimiento) : null,
            items: d.items.map((l) => ({
                producto_id: l.producto.id,
                cantidad: l.cantidad,
                numero_lote: l.numero_lote,
                fecha_vencimiento: aFechaISO(finDeMes(l.vence)),
                precio_unitario: l.bonificacion ? 0 : l.precio_unitario,
                bonificacion: l.bonificacion,
            })),
        }))
        .post('/compras', { preserveScroll: true });
};
</script>

<template>
    <Head title="Registrar compra" />
    <AppLayout titulo="Registrar compra">
        <form class="space-y-6" @submit.prevent="guardar">
            <!-- CABECERA -->
            <section class="bg-white rounded-xl border border-slate-200 p-4 grid grid-cols-1 md:grid-cols-6 gap-4">
                <div class="md:col-span-3 flex flex-col gap-1">
                    <label class="text-sm">Proveedor *</label>
                    <AutoComplete
                        v-model="proveedor"
                        :suggestions="sugerenciasProveedor"
                        optionLabel="razon_social"
                        placeholder="Escribe la razón social o el RUC"
                        :delay="300"
                        forceSelection
                        fluid
                        :invalid="!!form.errors.proveedor_id"
                        @complete="buscarProveedores"
                    >
                        <template #option="{ option }">
                            <div>
                                <p>{{ option.razon_social }}</p>
                                <p class="text-xs text-slate-500">RUC {{ option.ruc }}</p>
                            </div>
                        </template>
                    </AutoComplete>
                    <small v-if="form.errors.proveedor_id" class="text-red-600">Selecciona un proveedor.</small>
                    <small v-else class="text-slate-500">
                        ¿No aparece? Regístralo en <Link href="/proveedores" class="text-emerald-600 underline">Proveedores</Link>.
                    </small>
                </div>
                <div class="md:col-span-1 flex flex-col gap-1">
                    <label class="text-sm">Documento *</label>
                    <Select v-model="form.tipo_documento" :options="opcionesTipo" optionLabel="label" optionValue="value" fluid />
                </div>
                <div class="md:col-span-1 flex flex-col gap-1">
                    <label class="text-sm">Serie *</label>
                    <InputText v-model="form.serie" placeholder="F001" maxlength="4" :invalid="!!form.errors.serie" />
                </div>
                <div class="md:col-span-1 flex flex-col gap-1">
                    <label class="text-sm">Número *</label>
                    <InputText v-model="form.numero" placeholder="111535" :invalid="!!form.errors.numero" />
                    <small class="text-red-600">{{ form.errors.numero }}</small>
                </div>

                <div class="md:col-span-2 flex flex-col gap-1">
                    <label class="text-sm">Fecha de emisión *</label>
                    <DatePicker v-model="form.fecha_emision" dateFormat="dd/mm/yy" :maxDate="new Date()" showIcon fluid :invalid="!!form.errors.fecha_emision" />
                    <small class="text-red-600">{{ form.errors.fecha_emision }}</small>
                </div>
                <div class="md:col-span-2 flex flex-col gap-1">
                    <label class="text-sm">Condición de pago</label>
                    <SelectButton v-model="form.forma_pago" :options="opcionesPago" optionLabel="label" optionValue="value" :allowEmpty="false" />
                </div>
                <div v-if="form.forma_pago === 'credito'" class="md:col-span-2 flex flex-col gap-1">
                    <label class="text-sm">Fecha de pago (vencimiento) *</label>
                    <DatePicker v-model="form.fecha_vencimiento" dateFormat="dd/mm/yy" :minDate="form.fecha_emision" showIcon fluid :invalid="!!form.errors.fecha_vencimiento" />
                    <small class="text-red-600">{{ form.errors.fecha_vencimiento }}</small>
                </div>
            </section>

            <!-- DETALLE -->
            <section class="bg-white rounded-xl border border-slate-200">
                <div class="p-4 border-b border-slate-100 flex flex-col gap-1">
                    <label class="text-sm font-medium">Agregar producto</label>
                    <AutoComplete
                        v-model="productoBuscado"
                        :suggestions="sugerenciasProducto"
                        optionLabel="descripcion"
                        placeholder="Busca por nombre, principio activo o código y presiona Enter"
                        :delay="300"
                        fluid
                        @complete="buscarProductos"
                        @option-select="agregarProducto"
                    >
                        <template #option="{ option }">
                            <div class="flex items-center gap-3 w-full">
                                <div class="flex-1">
                                    <p>{{ option.descripcion }}</p>
                                    <p class="text-xs text-slate-500">{{ option.codigo }} · {{ option.laboratorio }}</p>
                                </div>
                                <Tag :value="option.unidad_venta" severity="secondary" />
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
                                <th class="text-left p-3 w-28">Cantidad</th>
                                <th class="text-left p-3 w-36">N° lote</th>
                                <th class="text-left p-3 w-36">Vence (mes/año)</th>
                                <th class="text-left p-3 w-36">Precio unit. (con IGV)</th>
                                <th class="text-center p-3 w-20">Bonif.</th>
                                <th class="text-right p-3 w-28">Importe</th>
                                <th class="w-12"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!form.items.length">
                                <td colspan="8" class="p-6 text-center text-slate-400">Agrega los productos tal como vienen en la factura del proveedor.</td>
                            </tr>
                            <tr v-for="(l, i) in form.items" :key="i" class="border-t border-slate-100 align-top">
                                <td class="p-3">
                                    <p class="font-medium">{{ l.producto.descripcion }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ l.producto.codigo }}
                                        <Tag v-if="l.producto.tipo_afectacion_igv !== '10'" value="Exonerado" severity="secondary" class="ml-1" />
                                        <Tag v-if="l.producto.cadena_frio" value="Frío" severity="info" class="ml-1" />
                                    </p>
                                </td>
                                <td class="p-3">
                                    <InputNumber v-model="l.cantidad" :min="0" :maxFractionDigits="2" :suffix="' ' + l.producto.unidad_venta" fluid :invalid="!!error(i, 'cantidad')" />
                                </td>
                                <td class="p-3">
                                    <InputText v-model="l.numero_lote" fluid :invalid="!!error(i, 'numero_lote')" />
                                    <small class="text-red-600">{{ error(i, 'numero_lote') }}</small>
                                </td>
                                <td class="p-3">
                                    <DatePicker v-model="l.vence" view="month" dateFormat="mm/yy" :minDate="new Date()" fluid :invalid="!!error(i, 'fecha_vencimiento')" />
                                    <small class="text-red-600">{{ error(i, 'fecha_vencimiento') }}</small>
                                </td>
                                <td class="p-3">
                                    <InputNumber v-model="l.precio_unitario" :minFractionDigits="3" :maxFractionDigits="3" prefix="S/ " :disabled="l.bonificacion" fluid :invalid="!!error(i, 'precio_unitario')" />
                                    <small class="text-red-600">{{ error(i, 'precio_unitario') }}</small>
                                </td>
                                <td class="p-3 text-center">
                                    <Checkbox v-model="l.bonificacion" binary v-tooltip.top="'Llegó gratis (ej. 1+1)'" />
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

            <!-- TOTALES -->
            <section class="grid md:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl border border-slate-200 p-4 flex flex-col gap-1">
                    <label class="text-sm">Observaciones</label>
                    <Textarea v-model="form.observaciones" rows="3" autoResize />
                </div>
                <div class="bg-white rounded-xl border border-slate-200 p-4 space-y-2 text-sm">
                    <div class="flex justify-between"><span>Op. gravadas</span><span>{{ soles(totales.gravadas) }}</span></div>
                    <div class="flex justify-between"><span>Op. exoneradas</span><span>{{ soles(totales.exoneradas) }}</span></div>
                    <div class="flex justify-between"><span>IGV (18%)</span><span>{{ soles(totales.igv) }}</span></div>
                    <div class="flex justify-between text-lg font-semibold border-t pt-2"><span>Total</span><span>{{ soles(totales.total) }}</span></div>

                    <div class="flex items-center justify-between gap-3 pt-2">
                        <span class="text-slate-500">Total impreso en la factura</span>
                        <div class="w-44">
    <InputNumber v-model="totalDocumento" prefix="S/ " :minFractionDigits="2" fluid inputClass="text-right" />
</div>
                    </div>
                    <Message v-if="diferencia !== null && diferencia !== 0" severity="warn" size="small">
                        No cuadra: hay {{ soles(Math.abs(diferencia)) }} de diferencia. Revisa cantidades y precios.
                    </Message>
                    <Message v-else-if="diferencia === 0" severity="success" size="small">El total coincide con la factura.</Message>
                </div>
            </section>

            <div class="flex justify-end gap-2">
                <Link href="/compras"><Button label="Cancelar" severity="secondary" text /></Link>
                <Button type="submit" label="Registrar compra e ingresar al almacén" icon="pi pi-check" :loading="form.processing" :disabled="!form.items.length" />
            </div>
        </form>
    </AppLayout>
</template>