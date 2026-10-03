<script setup>
import { computed, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import Checkbox from 'primevue/checkbox';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';
import { fecha, soles } from '@/utils/formato';
import { aFechaISO } from '@/utils/http';

const props = defineProps({
    compra: Object,
    estadosPago: Object,
    diasVencida: Number,
    medios: Object,
    puedePagar: Boolean,
    puedeAnular: Boolean,
    cajaAbierta: Boolean,
});

const confirm = useConfirm();
const c = computed(() => props.compra);
const SEVERIDAD = { pendiente: 'info', vencida: 'danger', pagada: 'success' };
const saldo = computed(() => Number(c.value.saldo));
const pagado = computed(() => Math.round((Number(c.value.total) - saldo.value) * 100) / 100);
const avance = computed(() => (Number(c.value.total) > 0 ? Math.min(100, Math.round((pagado.value / Number(c.value.total)) * 100)) : 0));

// ================= REGISTRAR PAGO =================
const opcionesMedio = Object.entries(props.medios).map(([value, label]) => ({ value, label }));
const conReferencia = ['transferencia', 'deposito', 'cheque'];

const form = useForm({
    fecha: new Date(),
    medio: 'transferencia',
    monto: saldo.value,
    referencia: '',
    observacion: '',
    desde_caja: false,
});
// En efectivo se propone sacarlo de la caja si el usuario la tiene abierta
watch(
    () => form.medio,
    (m) => (form.desde_caja = m === 'efectivo' && props.cajaAbierta),
);

const pagoParcial = computed(() => Number(form.monto) > 0 && Number(form.monto) < saldo.value);
const excede = computed(() => Number(form.monto) > saldo.value + 0.009);

const pagar = () =>
    confirm.require({
        header: 'Registrar pago',
        message:
            `Se registrará un pago de ${soles(form.monto)} a ${c.value.proveedor.razon_social} por la ${c.value.documento}` +
            (form.desde_caja ? ', y el efectivo saldrá de tu caja.' : '.') +
            (pagoParcial.value ? ` Quedará un saldo de ${soles(saldo.value - Number(form.monto))}.` : ' La compra quedará pagada.'),
        icon: 'pi pi-wallet',
        acceptProps: { label: 'Registrar pago', severity: 'success' },
        rejectProps: { label: 'Revisar', severity: 'secondary', outlined: true },
        accept: () =>
            form
                .transform((d) => ({
                    ...d,
                    fecha: aFechaISO(d.fecha),
                    referencia: d.medio === 'efectivo' ? null : d.referencia || null,
                    desde_caja: d.medio === 'efectivo' && d.desde_caja,
                }))
                .post(`/cuentas-por-pagar/${c.value.id}/pagos`, {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset('referencia', 'observacion');
                        form.monto = Number(props.compra.saldo);
                    },
                }),
    });

const anular = (p) =>
    confirm.require({
        header: 'Anular pago',
        message: `Se anulará el pago de ${soles(p.monto)} del ${fecha(p.fecha)} y la deuda volverá a subir. ¿Continuar?`,
        icon: 'pi pi-exclamation-triangle',
        acceptProps: { label: 'Anular pago', severity: 'danger' },
        rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
        accept: () => router.post(`/cuentas-por-pagar/pagos/${p.id}/anular`, {}, { preserveScroll: true }),
    });
</script>

<template>
    <Head :title="`Por pagar ${c.documento}`" />
    <AppLayout :titulo="`Cuenta por pagar · ${c.documento}`">
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <Link href="/cuentas-por-pagar"><Button label="Volver" icon="pi pi-arrow-left" text /></Link>
            <Tag :value="estadosPago[c.estado_pago]" :severity="SEVERIDAD[c.estado_pago]" class="text-sm" />
            <Link :href="`/compras/${c.id}`" class="ml-auto">
                <Button label="Ver compra" icon="pi pi-truck" severity="secondary" outlined />
            </Link>
        </div>

        <Message v-if="c.estado_pago === 'vencida'" severity="error" class="mb-4">
            Esta factura venció el {{ fecha(c.fecha_vencimiento) }}: lleva <b>{{ diasVencida }} día(s) de atraso</b>.
        </Message>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <div class="xl:col-span-2 space-y-6">
                <!-- Resumen de la deuda -->
                <section class="bg-white rounded-xl border border-slate-200 p-5">
                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                        <div class="sm:col-span-2">
                            <p class="text-xs text-slate-500">Proveedor</p>
                            <p class="font-medium">{{ c.proveedor.razon_social }}</p>
                            <p class="text-slate-500">RUC {{ c.proveedor.ruc }}</p>
                        </div>
                        <div><p class="text-xs text-slate-500">Emisión</p><p class="font-medium">{{ fecha(c.fecha_emision) }}</p></div>
                        <div>
                            <p class="text-xs text-slate-500">Fecha de pago</p>
                            <p class="font-medium" :class="c.estado_pago === 'vencida' ? 'text-red-600' : ''">{{ fecha(c.fecha_vencimiento) }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-4 mt-5">
                        <div class="rounded-lg bg-slate-50 p-3">
                            <p class="text-xs text-slate-500">Total de la factura</p>
                            <p class="text-lg font-semibold">{{ soles(c.total) }}</p>
                        </div>
                        <div class="rounded-lg bg-emerald-50 p-3">
                            <p class="text-xs text-emerald-700">Pagado</p>
                            <p class="text-lg font-semibold text-emerald-800">{{ soles(pagado) }}</p>
                        </div>
                        <div class="rounded-lg p-3" :class="saldo > 0 ? 'bg-red-50' : 'bg-emerald-50'">
                            <p class="text-xs" :class="saldo > 0 ? 'text-red-700' : 'text-emerald-700'">Saldo</p>
                            <p class="text-lg font-semibold" :class="saldo > 0 ? 'text-red-800' : 'text-emerald-800'">{{ soles(saldo) }}</p>
                        </div>
                    </div>
                    <div class="rounded-full mt-4 overflow-hidden" style="height: 8px; background-color: #f1f5f9">
                        <div
                            class="transition-all"
                            :style="{ width: avance + '%', height: '100%', backgroundColor: avance >= 100 ? '#059669' : '#10b981' }"
                        ></div>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">{{ avance }}% pagado</p>
                </section>

                <!-- Historial de pagos -->
                <section class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
                    <h2 class="font-semibold p-4 border-b border-slate-100">Pagos registrados</h2>
                    <p v-if="!c.pagos.length" class="p-4 text-sm text-slate-400">Aún no hay pagos.</p>
                    <table v-else class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                            <tr>
                                <th class="text-left p-3">Fecha</th>
                                <th class="text-left p-3">Medio</th>
                                <th class="text-left p-3">Referencia</th>
                                <th class="text-left p-3">Registró</th>
                                <th class="text-right p-3">Monto</th>
                                <th class="w-12"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in c.pagos" :key="p.id" class="border-t border-slate-100" :class="p.estado === 'anulado' ? 'opacity-60' : ''">
                                <td class="p-3">{{ fecha(p.fecha) }}</td>
                                <td class="p-3">
                                    {{ p.medio_nombre }}
                                    <Tag v-if="p.caja_id" value="De caja" severity="secondary" class="ml-1" />
                                </td>
                                <td class="p-3">
                                    {{ p.referencia || '—' }}
                                    <p v-if="p.observacion" class="text-xs text-slate-500">{{ p.observacion }}</p>
                                </td>
                                <td class="p-3">
                                    {{ p.usuario?.name }}
                                    <p v-if="p.estado === 'anulado'" class="text-xs text-red-600">Anulado por {{ p.anulado_por?.name }} el {{ fecha(p.anulado_at) }}</p>
                                </td>
                                <td class="p-3 text-right font-medium" :class="p.estado === 'anulado' ? 'line-through' : ''">{{ soles(p.monto) }}</td>
                                <td class="p-3">
                                    <Button
                                        v-if="puedeAnular && p.estado === 'activo'"
                                        icon="pi pi-ban"
                                        text
                                        rounded
                                        severity="danger"
                                        v-tooltip.left="'Anular pago'"
                                        @click="anular(p)"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>

            <!-- Registrar pago -->
            <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-4 self-start">
                <h2 class="font-semibold">Registrar pago</h2>

                <Message v-if="saldo <= 0" severity="success" size="small">Esta compra ya está pagada por completo.</Message>
                <Message v-else-if="!puedePagar" severity="info" size="small">Los pagos a proveedores los registra el contador o el administrador.</Message>

                <template v-else>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Fecha del pago *</label>
                        <DatePicker v-model="form.fecha" dateFormat="dd/mm/yy" :maxDate="new Date()" showIcon fluid :invalid="!!form.errors.fecha" />
                        <small class="text-red-600">{{ form.errors.fecha }}</small>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Medio de pago *</label>
                        <Select v-model="form.medio" :options="opcionesMedio" optionLabel="label" optionValue="value" fluid />
                    </div>

                    <div class="flex flex-col gap-1">
                        <div class="flex items-center justify-between">
                            <label class="text-sm">Monto *</label>
                            <Button label="Pagar todo" text size="small" severity="help" @click="form.monto = saldo" />
                        </div>
                        <InputNumber
                            v-model="form.monto"
                            prefix="S/ "
                            locale="en-US"
                            :minFractionDigits="2"
                            :maxFractionDigits="2"
                            :min="0"
                            fluid
                            :invalid="!!form.errors.monto || excede"
                        />
                        <small v-if="excede" class="text-red-600">No puede superar el saldo de {{ soles(saldo) }}.</small>
                        <small v-else-if="pagoParcial" class="text-amber-600">Pago parcial: quedará un saldo de {{ soles(saldo - Number(form.monto)) }}.</small>
                        <small class="text-red-600">{{ form.errors.monto }}</small>
                    </div>

                    <div v-if="form.medio !== 'efectivo'" class="flex flex-col gap-1">
                        <label class="text-sm">
                            {{ form.medio === 'cheque' ? 'N° de cheque' : 'N° de operación' }}
                            <span v-if="conReferencia.includes(form.medio)">*</span>
                        </label>
                        <InputText v-model="form.referencia" maxlength="50" fluid :invalid="!!form.errors.referencia" />
                        <small class="text-red-600">{{ form.errors.referencia }}</small>
                    </div>

                    <div v-else class="rounded-lg border border-slate-200 p-3">
                        <label class="flex items-start gap-2 text-sm" :class="!cajaAbierta ? 'opacity-60' : ''">
                            <Checkbox v-model="form.desde_caja" binary :disabled="!cajaAbierta" />
                            <span>Sacar el efectivo de mi caja (se registra como egreso).</span>
                        </label>
                        <small v-if="!cajaAbierta" class="text-slate-500 block mt-1">
                            No tienes caja abierta: el pago se registra igual, pero sin descontarlo de una caja.
                        </small>
                        <small class="text-red-600">{{ form.errors.desde_caja }}</small>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Observación</label>
                        <InputText v-model="form.observacion" maxlength="250" placeholder="Opcional" fluid />
                    </div>

                    <Button
                        label="Registrar pago"
                        icon="pi pi-check"
                        severity="success"
                        class="w-full"
                        size="large"
                        :loading="form.processing"
                        :disabled="!(Number(form.monto) > 0) || excede"
                        @click="pagar"
                    />
                </template>
            </section>
        </div>
    </AppLayout>
</template>