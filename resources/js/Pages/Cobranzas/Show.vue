<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { soles, fecha } from '@/utils/formato';
import { estadoSunat } from '@/utils/sunat';

const props = defineProps({
    comprobante: Object,
    cuotas: Array,
    pagos: Array,
    notas: Array,
    otras: Array,
    impedimento: String,
    cajaAbierta: Boolean,
    mediosPago: Object,
    sugerido: Number,
});

const c = computed(() => props.comprobante);
const saldo = computed(() => Number(c.value.saldo));
const cobrado = computed(() => props.pagos.reduce((s, p) => s + Number(p.monto), 0));
const acreditado = computed(() => props.notas.filter((n) => n.estado !== 'rechazado').reduce((s, n) => s + Number(n.total), 0));
const hora = (v) => String(v ?? '').substring(11, 16);
const opcionesMedio = Object.entries(props.mediosPago).map(([value, label]) => ({ value, label }));

const ESTADO_CUOTA = {
    pagada: { texto: 'Pagada', severidad: 'success' },
    parcial: { texto: 'Pago parcial', severidad: 'warn' },
    vencida: { texto: 'Vencida', severidad: 'danger' },
    pendiente: { texto: 'Pendiente', severidad: 'secondary' },
};

// ================= COBRO =================
const form = useForm({ monto: props.sugerido, medio: 'efectivo', recibido: null, referencia: '' });
const esEfectivo = computed(() => form.medio === 'efectivo');
const vuelto = computed(() => (esEfectivo.value && form.recibido ? Math.max(0, form.recibido - (form.monto || 0)) : 0));
const puedeCobrar = computed(() => !props.impedimento && saldo.value > 0 && props.cajaAbierta);

const cobrar = () =>
    form.post(`/cobranzas/${c.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('recibido', 'referencia');
            form.monto = props.sugerido;
        },
    });
</script>

<template>
    <Head :title="`Cobranza ${c.numero}`" />
    <AppLayout :titulo="`Cobranza · ${c.numero}`">
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <Link href="/cobranzas"><Button label="Volver" icon="pi pi-arrow-left" text /></Link>
            <Tag v-if="saldo <= 0" value="Cancelada" severity="success" icon="pi pi-check" />
            <Tag v-else-if="cuotas.some((q) => q.estado === 'vencida')" value="Con cuotas vencidas" severity="danger" icon="pi pi-exclamation-triangle" />
            <Tag v-else value="Al día" severity="info" />
            <Link :href="`/comprobantes/${c.id}`" class="ml-auto">
                <Button label="Ver comprobante" icon="pi pi-file" severity="info" outlined />
            </Link>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <section class="xl:col-span-2 space-y-6">
                <!-- Datos y totales -->
                <div class="bg-white rounded-xl border border-slate-200 p-5 grid sm:grid-cols-4 gap-4 text-sm">
                    <div class="sm:col-span-2">
                        <p class="text-xs text-slate-500">Cliente</p>
                        <p class="font-medium">{{ c.cliente.razon_social }}</p>
                        <p class="text-slate-500">
                            {{ c.cliente.numero_documento }}<span v-if="c.cliente.telefono"> · <i class="pi pi-phone text-xs"></i> {{ c.cliente.telefono }}</span>
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Emisión</p>
                        <p class="font-medium">{{ fecha(c.fecha_emision) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Vendedor</p>
                        <p class="font-medium">{{ c.vendedor?.name }}</p>
                    </div>
                    <div class="sm:col-span-4 grid grid-cols-2 sm:grid-cols-4 gap-3 pt-3 border-t border-slate-100">
                        <div>
                            <p class="text-xs text-slate-500">Total</p>
                            <p class="text-lg font-semibold">{{ soles(c.total) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Cobrado</p>
                            <p class="text-lg font-semibold text-emerald-700">{{ soles(cobrado) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Notas de crédito</p>
                            <p class="text-lg font-semibold">{{ soles(acreditado) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Saldo</p>
                            <p class="text-lg font-bold" :class="saldo > 0 ? 'text-red-600' : 'text-emerald-700'">{{ soles(saldo) }}</p>
                        </div>
                    </div>
                </div>

                <!-- Cuotas -->
                <div class="bg-white rounded-xl border border-slate-200">
                    <h2 class="font-semibold p-4 border-b border-slate-100">Cuotas</h2>
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                            <tr>
                                <th class="text-left p-3">N°</th>
                                <th class="text-left p-3">Vence</th>
                                <th class="text-left p-3">Estado</th>
                                <th class="text-right p-3">Monto</th>
                                <th class="text-right p-3">Pagado</th>
                                <th class="text-right p-3">Pendiente</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="q in cuotas" :key="q.id" class="border-t border-slate-100" :class="{ 'bg-red-50/50': q.estado === 'vencida' }">
                                <td class="p-3">{{ q.numero }}</td>
                                <td class="p-3">
                                    {{ fecha(q.fecha_vencimiento) }}
                                    <span v-if="q.pendiente > 0" class="text-xs" :class="q.dias < 0 ? 'text-red-600' : 'text-slate-500'">
                                        · {{ q.dias < 0 ? `${-q.dias} días de atraso` : q.dias === 0 ? 'vence hoy' : `en ${q.dias} días` }}
                                    </span>
                                </td>
                                <td class="p-3"><Tag :value="ESTADO_CUOTA[q.estado].texto" :severity="ESTADO_CUOTA[q.estado].severidad" /></td>
                                <td class="p-3 text-right">{{ soles(q.monto) }}</td>
                                <td class="p-3 text-right text-emerald-700">{{ soles(q.pagado) }}</td>
                                <td class="p-3 text-right font-medium">{{ soles(q.pendiente) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Historial de cobros -->
                <div class="bg-white rounded-xl border border-slate-200">
                    <h2 class="font-semibold p-4 border-b border-slate-100">Historial de cobros</h2>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-if="!pagos.length">
                                <td class="p-6 text-center text-slate-400">Aún no hay cobros registrados.</td>
                            </tr>
                            <tr v-for="p in pagos" :key="p.id" class="border-t border-slate-100">
                                <td class="p-3 whitespace-nowrap text-slate-500">{{ fecha(p.fecha) }} {{ hora(p.fecha) }}</td>
                                <td class="p-3">
                                    {{ p.medio_nombre }}
                                    <span v-if="p.referencia" class="text-slate-500"> · Op. {{ p.referencia }}</span>
                                    <span v-if="p.recibido" class="text-slate-500"> · Recibido {{ soles(p.recibido) }} · Vuelto {{ soles(p.vuelto) }}</span>
                                </td>
                                <td class="p-3 text-slate-500">{{ p.usuario?.name }}</td>
                                <td class="p-3 text-right font-medium text-emerald-700">{{ soles(p.monto) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Notas de crédito que rebajan la deuda -->
                <div v-if="notas.length" class="bg-white rounded-xl border border-slate-200">
                    <h2 class="font-semibold p-4 border-b border-slate-100">Notas de crédito</h2>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="n in notas" :key="n.id" class="border-t border-slate-100" :class="{ 'opacity-50 line-through': n.estado === 'rechazado' }">
                                <td class="p-3 text-slate-500">{{ fecha(n.fecha_emision) }}</td>
                                <td class="p-3">
                                    <Link :href="`/comprobantes/${n.id}`" class="font-medium text-emerald-700 hover:underline">{{ n.serie }}-{{ n.correlativo }}</Link>
                                </td>
                                <td class="p-3"><Tag :value="estadoSunat(n.estado).texto" :severity="estadoSunat(n.estado).severidad" /></td>
                                <td class="p-3 text-right font-medium">− {{ soles(n.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Registrar cobro -->
            <section class="space-y-6">
                <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4 xl:sticky xl:top-4">
                    <h2 class="font-semibold">Registrar cobro</h2>

                    <Message v-if="impedimento" severity="warn" :closable="false">{{ impedimento }}</Message>
                    <Message v-else-if="saldo <= 0" severity="success" :closable="false">Esta venta ya está cancelada.</Message>
                    <Message v-else-if="!cajaAbierta" severity="warn" :closable="false">
                        Abre tu caja para registrar cobros.
                        <Link href="/caja" class="font-semibold underline">Ir a Caja</Link>
                    </Message>

                    <template v-if="!impedimento && saldo > 0">
                        <div>
                            <label class="text-sm font-medium">Monto a cobrar</label>
                            <InputNumber
                                v-model="form.monto"
                                prefix="S/ "
                                locale="en-US"
                                :minFractionDigits="2"
                                :maxFractionDigits="2"
                                :min="0"
                                :max="saldo"
                                fluid
                                class="mt-1"
                                inputClass="text-lg font-semibold"
                                :invalid="!!form.errors.monto"
                            />
                            <small class="text-red-600">{{ form.errors.monto }}</small>
                            <div class="flex gap-2 mt-2">
                                <Button label="Próxima cuota" size="small" severity="secondary" outlined @click="form.monto = sugerido" />
                                <Button label="Todo el saldo" size="small" severity="secondary" outlined @click="form.monto = saldo" />
                            </div>
                        </div>

                        <div>
                            <label class="text-sm font-medium">Medio de pago</label>
                            <Select v-model="form.medio" :options="opcionesMedio" optionLabel="label" optionValue="value" fluid class="mt-1" />
                        </div>

                        <div v-if="esEfectivo">
                            <label class="text-sm font-medium">Efectivo recibido <span class="text-slate-400 font-normal">(opcional, para el vuelto)</span></label>
                            <InputNumber v-model="form.recibido" prefix="S/ " locale="en-US" :minFractionDigits="2" fluid class="mt-1" :invalid="!!form.errors.recibido" />
                            <small class="text-red-600">{{ form.errors.recibido }}</small>
                            <p v-if="vuelto > 0" class="mt-2 text-lg font-semibold text-amber-700">Vuelto: {{ soles(vuelto) }}</p>
                        </div>
                        <div v-else>
                            <label class="text-sm font-medium">N° de operación</label>
                            <InputText v-model="form.referencia" maxlength="50" placeholder="Ej. 01234567" fluid class="mt-1" />
                        </div>

                        <div class="rounded-lg bg-slate-50 p-3 text-sm flex justify-between">
                            <span>Saldo después del cobro</span>
                            <span class="font-semibold">{{ soles(Math.max(0, saldo - (form.monto || 0))) }}</span>
                        </div>

                        <Button
                            label="Registrar cobro"
                            icon="pi pi-check"
                            severity="success"
                            size="large"
                            class="w-full"
                            :disabled="!puedeCobrar || !form.monto"
                            :loading="form.processing"
                            @click="cobrar"
                        />
                        <p class="text-xs text-slate-500">El cobro entra a tu caja abierta, en el medio de pago elegido.</p>
                    </template>
                </div>

                <!-- Otras deudas del cliente -->
                <div v-if="otras.length" class="bg-white rounded-xl border border-slate-200 p-5 text-sm">
                    <h2 class="font-semibold mb-3">Otras deudas de este cliente</h2>
                    <Link
                        v-for="o in otras"
                        :key="o.id"
                        :href="`/cobranzas/${o.id}`"
                        class="flex justify-between py-2 border-b border-slate-100 last:border-0 hover:bg-slate-50"
                    >
                        <span>
                            <span class="font-medium text-emerald-700">{{ o.serie }}-{{ o.correlativo }}</span>
                            <span class="text-slate-500"> · vence {{ fecha(o.fecha_vencimiento) }}</span>
                        </span>
                        <span class="font-medium">{{ soles(o.saldo) }}</span>
                    </Link>
                    <div class="flex justify-between pt-2 font-semibold">
                        <span>Deuda total del cliente</span>
                        <span>{{ soles(saldo + otras.reduce((s, o) => s + Number(o.saldo), 0)) }}</span>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>