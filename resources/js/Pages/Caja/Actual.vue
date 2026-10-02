<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import Textarea from 'primevue/textarea';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';
import { soles, fecha } from '@/utils/formato';

const props = defineProps({
    caja: Object, // null si no hay caja abierta
    resumen: Object,
    movimientos: Array,
    ultimaCerrada: Object,
    mediosPago: Object,
    denominaciones: Array,
    esAdmin: Boolean,
});

const confirm = useConfirm();
const hora = (v) => String(v ?? '').substring(11, 16);
const opcionesMedio = Object.entries(props.mediosPago).map(([value, label]) => ({ value, label }));

// ================= APERTURA =================
const apertura = useForm({ monto_inicial: 0 });
const abrir = () => apertura.post('/caja/abrir');

// ================= INGRESOS / EGRESOS =================
const movimiento = useForm({ tipo: 'egreso', monto: null, concepto: '', medio: 'efectivo' });

const registrarMovimiento = () =>
    movimiento.post('/caja/movimientos', {
        preserveScroll: true,
        onSuccess: () => movimiento.reset('monto', 'concepto'),
    });

// ================= CIERRE (arqueo con conteo de billetes y monedas) =================
const conteo = ref(Object.fromEntries(props.denominaciones.map((d) => [String(d), null])));
const sumaConteo = computed(() =>
    Math.round(props.denominaciones.reduce((s, d) => s + d * (Number(conteo.value[String(d)]) || 0), 0) * 100) / 100,
);
const usarConteo = ref(true); // false = escribir el total directamente
const totalDirecto = ref(null);
const efectivoContado = computed(() => (usarConteo.value ? sumaConteo.value : Number(totalDirecto.value) || 0));

const cierre = useForm({ efectivo_contado: 0, conteo: {}, observaciones: '' });
const cerrar = () =>
    confirm.require({
        header: 'Cerrar caja',
        message: `Vas a cerrar tu caja con ${soles(efectivoContado.value)} en efectivo contado. Después de cerrarla verás si cuadró. ¿Continuar?`,
        icon: 'pi pi-lock',
        acceptProps: { label: 'Cerrar caja' },
        rejectProps: { label: 'Volver a contar', severity: 'secondary', outlined: true },
        accept: () =>
            cierre
                .transform((d) => ({
                    ...d,
                    efectivo_contado: efectivoContado.value,
                    conteo: usarConteo.value ? conteo.value : {},
                }))
                .post('/caja/cerrar'),
    });

// Solo el admin ve el efectivo esperado antes del cierre
const diferenciaPrevia = computed(() =>
    props.resumen?.efectivo_esperado == null ? null : Math.round((efectivoContado.value - props.resumen.efectivo_esperado) * 100) / 100,
);
const etiquetaDenominacion = (d) => (d >= 10 ? `Billete S/ ${d}` : d >= 1 ? `Moneda S/ ${d}` : `Moneda S/ ${d.toFixed(2)}`);
</script>

<template>
    <Head title="Caja" />
    <AppLayout titulo="Caja">
        <div v-if="esAdmin" class="flex justify-end mb-4">
            <Link href="/cajas"><Button label="Historial de cajas" icon="pi pi-history" severity="secondary" outlined /></Link>
        </div>

        <!-- ================= SIN CAJA: APERTURA ================= -->
        <section v-if="!caja" class="max-w-md mx-auto bg-white rounded-xl border border-slate-200 p-6 space-y-4 mt-6">
            <div class="text-center">
                <i class="pi pi-wallet text-4xl text-emerald-600"></i>
                <h2 class="text-lg font-semibold mt-2">Abrir caja</h2>
                <p class="text-sm text-slate-500">Cuenta el sencillo con el que empiezas tu turno. Para vender al contado necesitas tu caja abierta.</p>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm">Monto inicial en efectivo</label>
                <InputNumber v-model="apertura.monto_inicial" prefix="S/ " locale="en-US" :minFractionDigits="2" :min="0" fluid autofocus :invalid="!!apertura.errors.monto_inicial" />
                <small class="text-red-600">{{ apertura.errors.monto_inicial }}</small>
            </div>
            <Button label="Abrir caja" icon="pi pi-lock-open" class="w-full" size="large" :loading="apertura.processing" @click="abrir" />
            <p v-if="ultimaCerrada" class="text-center text-sm text-slate-500">
                Tu última caja se cerró el {{ fecha(ultimaCerrada.cerrada_at) }} {{ hora(ultimaCerrada.cerrada_at) }}.
                <Link :href="`/cajas/${ultimaCerrada.id}`" class="text-emerald-700 underline">Ver reporte</Link>
            </p>
        </section>

        <!-- ================= CAJA ABIERTA ================= -->
        <div v-else class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <section class="xl:col-span-2 space-y-6">
                <!-- Estado -->
                <div class="bg-white rounded-xl border border-slate-200 p-5 flex flex-wrap items-center gap-4">
                    <Tag value="Caja abierta" severity="success" icon="pi pi-lock-open" />
                    <span class="text-sm text-slate-600">Desde {{ fecha(caja.abierta_at) }} {{ hora(caja.abierta_at) }} · Inicial {{ soles(caja.monto_inicial) }}</span>
                    <span class="ml-auto text-sm">  <b>{{ resumen.cantidad_ventas }}</b> ventas · <b>{{ resumen.cantidad_cobranzas }}</b> cobranzas</span>
                </div>

                <!-- Totales por medio de pago -->
                <div class="bg-white rounded-xl border border-slate-200">
                    <div class="p-4 border-b border-slate-100">
                        <h2 class="font-semibold">Movimiento por medio de pago</h2>
                        <p v-if="!esAdmin" class="text-xs text-slate-500">El efectivo se muestra al cerrar la caja (cierre ciego).</p>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                            <tr>
                                <th class="text-left p-3">Medio</th>
                                <th class="text-right p-3">Ventas</th>
                                <th class="text-right p-3">Cobranzas</th>
                                <th class="text-right p-3">Ingresos</th>
                                <th class="text-right p-3">Egresos</th>
                                <th class="text-right p-3">Neto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in resumen.medios" :key="m.medio" class="border-t border-slate-100">
                                <td class="p-3">{{ m.nombre }}</td>
                                <td class="p-3 text-right">{{ m.ventas == null ? '••••' : soles(m.ventas) }}</td>
                                <td class="p-3 text-right">{{ m.cobranzas == null ? '••••' : soles(m.cobranzas) }}</td>
                                <td class="p-3 text-right">{{ soles(m.ingresos) }}</td>
                                <td class="p-3 text-right">{{ soles(m.egresos) }}</td>
                                <td class="p-3 text-right font-medium">{{ m.neto == null ? '••••' : soles(m.neto) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Ingresos y egresos -->
                <div class="bg-white rounded-xl border border-slate-200">
                    <div class="p-4 border-b border-slate-100 space-y-3">
                        <h2 class="font-semibold">Registrar ingreso o egreso</h2>
                        <div class="grid sm:grid-cols-12 gap-2 items-start">
<div class="sm:col-span-4 grid grid-cols-2 gap-2">
    <Button
        label="Egreso"
        icon="pi pi-arrow-up-right"
        severity="danger"
        :outlined="movimiento.tipo !== 'egreso'"
        :class="{ 'opacity-60': movimiento.tipo !== 'egreso' }"
        v-tooltip.top="'Sale dinero de la caja'"
        @click="movimiento.tipo = 'egreso'"
    />
    <Button
        label="Ingreso"
        icon="pi pi-arrow-down-left"
        severity="success"
        :outlined="movimiento.tipo !== 'ingreso'"
        :class="{ 'opacity-60': movimiento.tipo !== 'ingreso' }"
        v-tooltip.top="'Entra dinero a la caja'"
        @click="movimiento.tipo = 'ingreso'"
    />
</div>
                            <div class="sm:col-span-3">
                                <InputNumber v-model="movimiento.monto" prefix="S/ " locale="en-US" :minFractionDigits="2" placeholder="Monto" fluid :invalid="!!movimiento.errors.monto" />
                            </div>
                            <div class="sm:col-span-5">
                                <Select v-model="movimiento.medio" :options="opcionesMedio" optionLabel="label" optionValue="value" fluid />
                            </div>
                            <div class="sm:col-span-9">
                                <InputText v-model="movimiento.concepto" placeholder="Motivo: pago delivery, compra de útiles, sencillo del dueño..." maxlength="150" fluid :invalid="!!movimiento.errors.concepto" />
                            </div>
                            <div class="sm:col-span-3">
                                <Button
    :label="movimiento.tipo === 'egreso' ? 'Registrar egreso' : 'Registrar ingreso'"
    icon="pi pi-check"
    :severity="movimiento.tipo === 'egreso' ? 'danger' : 'success'"
    class="w-full"
    :loading="movimiento.processing"
    @click="registrarMovimiento"
/>
                            </div>
                        </div>
                        <small class="text-red-600 block">{{ movimiento.errors.monto || movimiento.errors.concepto }}</small>
                    </div>
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-if="!movimientos.length">
                                <td class="p-6 text-center text-slate-400">Aún no hay ingresos ni egresos en esta caja.</td>
                            </tr>
                            <tr v-for="m in movimientos" :key="m.id" class="border-t border-slate-100">
                                <td class="p-3 w-16 text-slate-500">{{ hora(m.created_at) }}</td>
                                <td class="p-3">
                                    <Tag :value="m.tipo === 'ingreso' ? 'Ingreso' : 'Egreso'" :severity="m.tipo === 'ingreso' ? 'success' : 'danger'" class="mr-2" />
                                    {{ m.concepto }}
                                </td>
                                <td class="p-3 text-slate-500">{{ m.medio_nombre }}</td>
                                <td class="p-3 text-right font-medium" :class="m.tipo === 'ingreso' ? 'text-emerald-700' : 'text-red-600'">
                                    {{ m.tipo === 'ingreso' ? '+' : '−' }} {{ soles(m.monto) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ================= CIERRE ================= -->
            <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-4 self-start">
                <div>
                    <h2 class="font-semibold">Cerrar caja</h2>
                    <p class="text-xs text-slate-500">Cuenta el efectivo que tienes en el cajón (incluye el monto inicial).</p>
                </div>

                <SelectButton
                    v-model="usarConteo"
                    :options="[{ value: true, label: 'Contar billetes y monedas' }, { value: false, label: 'Escribir total' }]"
                    optionLabel="label"
                    optionValue="value"
                    :allowEmpty="false"
                    size="small"
                />

                <table v-if="usarConteo" class="w-full text-sm">
                    <tbody>
                        <tr v-for="d in denominaciones" :key="d" class="border-b border-slate-100 last:border-0">
                            <td class="py-1.5">{{ etiquetaDenominacion(d) }}</td>
                            <td class="py-1.5 w-24">
                                <InputNumber v-model="conteo[String(d)]" :min="0" placeholder="0" inputClass="w-20 text-center" size="small" />
                            </td>
                            <td class="py-1.5 text-right w-24 text-slate-600">{{ soles(d * (Number(conteo[String(d)]) || 0)) }}</td>
                        </tr>
                    </tbody>
                </table>
                <InputNumber v-else v-model="totalDirecto" prefix="S/ " locale="en-US" :minFractionDigits="2" :min="0" placeholder="Efectivo contado" fluid />

                <div class="flex justify-between text-lg font-semibold border-t pt-3">
                    <span>Efectivo contado</span><span>{{ soles(efectivoContado) }}</span>
                </div>

                <!-- Solo el administrador ve lo esperado antes de cerrar -->
                <div v-if="esAdmin" class="text-sm space-y-1 rounded-lg bg-slate-50 p-3">
                    <div class="flex justify-between"><span>Efectivo esperado</span><span>{{ soles(resumen.efectivo_esperado) }}</span></div>
                    <div class="flex justify-between font-medium" :class="diferenciaPrevia < 0 ? 'text-red-600' : 'text-emerald-700'">
                        <span>Diferencia</span><span>{{ soles(diferenciaPrevia) }}</span>
                    </div>
                </div>

                <Textarea v-model="cierre.observaciones" rows="2" autoResize placeholder="Observaciones del cierre (opcional)" fluid />
                <small class="text-red-600 block">{{ cierre.errors.efectivo_contado }}</small>

                <Button label="Cerrar caja" icon="pi pi-lock" severity="danger" size="large" class="w-full" :loading="cierre.processing" @click="cerrar" />
                <Message severity="secondary" size="small">Al cerrar verás el reporte con lo esperado, lo contado y la diferencia.</Message>
            </section>
        </div>
    </AppLayout>
</template>