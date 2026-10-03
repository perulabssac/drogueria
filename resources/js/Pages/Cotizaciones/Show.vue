<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Message from 'primevue/message';
import { useConfirm } from 'primevue/useconfirm';
import { cantidad, fecha, precio, soles } from '@/utils/formato';

const props = defineProps({
    cotizacion: Object,
    estados: Object,
    puedeGestionar: Boolean,
});

const confirm = useConfirm();
const c = computed(() => props.cotizacion);
const SEVERIDAD = { pendiente: 'info', vendida: 'success', vencida: 'warn', anulada: 'danger' };
const vigente = computed(() => c.value.estado_actual === 'pendiente');
const vencida = computed(() => c.value.estado_actual === 'vencida');

const anular = () =>
    confirm.require({
        header: `Anular ${c.value.numero}`,
        message: 'La cotización quedará anulada y ya no se podrá convertir en venta. ¿Continuar?',
        icon: 'pi pi-exclamation-triangle',
        acceptProps: { label: 'Anular', severity: 'danger' },
        rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
        accept: () => router.post(`/cotizaciones/${c.value.id}/anular`, {}, { preserveScroll: true }),
    });
</script>

<template>
    <Head :title="`Cotización ${c.numero}`" />
    <AppLayout :titulo="`Cotización ${c.numero}`">
        <!-- Barra de acciones: cada botón con su color -->
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <Link href="/cotizaciones"><Button label="Volver" icon="pi pi-arrow-left" text /></Link>
            <Tag :value="estados[c.estado_actual]" :severity="SEVERIDAD[c.estado_actual]" class="text-sm" />
            <div class="ml-auto flex flex-wrap gap-2">
                <Link v-if="vigente && puedeGestionar" :href="`/ventas/nueva?cotizacion=${c.id}`">
                    <Button label="Convertir en venta" icon="pi pi-shopping-cart" severity="success" />
                </Link>
                <Link v-if="vigente && puedeGestionar" :href="`/cotizaciones/${c.id}/editar`">
                    <Button label="Editar" icon="pi pi-pencil" severity="warn" />
                </Link>
                <a :href="`/cotizaciones/${c.id}/imprimir`" target="_blank">
                    <Button label="Imprimir / PDF" icon="pi pi-print" severity="info" />
                </a>
                <Link v-if="puedeGestionar" :href="`/cotizaciones/nueva?desde=${c.id}`">
                    <Button :label="vencida ? 'Renovar' : 'Duplicar'" icon="pi pi-copy" severity="help" />
                </Link>
                <Link v-if="c.comprobante" :href="`/comprobantes/${c.comprobante.id}`">
                    <Button :label="`Ver ${c.comprobante.numero}`" icon="pi pi-file" severity="secondary" outlined />
                </Link>
                <Button v-if="c.estado === 'pendiente' && puedeGestionar" label="Anular" icon="pi pi-ban" severity="danger" outlined @click="anular" />
            </div>
        </div>

        <Message v-if="vencida" severity="warn" class="mb-4">
            Esta cotización venció el {{ fecha(c.fecha_vencimiento) }}. Los precios pudieron cambiar: usa <b>Renovar</b> para crear una nueva con los precios de hoy.
        </Message>
        <Message v-if="c.estado === 'vendida'" severity="success" class="mb-4">
            El cliente aceptó: se emitió la venta <b>{{ c.comprobante?.numero }}</b>.
        </Message>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <section class="lg:col-span-2 bg-white rounded-xl border border-slate-200 p-5 text-sm grid sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <p class="text-xs text-slate-500">Cliente</p>
                    <p class="font-medium">{{ c.cliente.razon_social }}</p>
                    <p class="text-slate-500">
                        {{ c.cliente.tipo_documento === '6' ? 'RUC' : 'Doc.' }} {{ c.cliente.numero_documento }}
                        <span v-if="c.cliente.direccion"> · {{ c.cliente.direccion }}</span>
                    </p>
                </div>
                <div><p class="text-xs text-slate-500">Fecha</p><p class="font-medium">{{ fecha(c.fecha) }}</p></div>
                <div>
                    <p class="text-xs text-slate-500">Válida hasta</p>
                    <p class="font-medium" :class="vencida ? 'text-amber-600' : ''">{{ fecha(c.fecha_vencimiento) }} ({{ c.validez_dias }} días)</p>
                </div>
                <div><p class="text-xs text-slate-500">Forma de pago</p><p class="font-medium capitalize">{{ c.forma_pago }}</p></div>
                <div><p class="text-xs text-slate-500">Vendedor</p><p class="font-medium">{{ c.vendedor?.name ?? c.usuario?.name }}</p></div>
                <div v-if="c.condiciones" class="sm:col-span-2">
                    <p class="text-xs text-slate-500">Condiciones</p>
                    <p class="whitespace-pre-line">{{ c.condiciones }}</p>
                </div>
                <div v-if="c.observaciones" class="sm:col-span-2">
                    <p class="text-xs text-slate-500">Observaciones</p>
                    <p class="whitespace-pre-line">{{ c.observaciones }}</p>
                </div>
            </section>

            <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-2 text-sm self-start">
                <div class="flex justify-between"><span class="text-slate-500">Op. gravadas</span><span>{{ soles(c.op_gravadas) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Op. exoneradas</span><span>{{ soles(c.op_exoneradas) }}</span></div>
                <div v-if="Number(c.op_gratuitas) > 0" class="flex justify-between">
                    <span class="text-slate-500">Bonificaciones (gratis)</span><span>{{ soles(c.op_gratuitas) }}</span>
                </div>
                <div class="flex justify-between"><span class="text-slate-500">IGV (18%)</span><span>{{ soles(c.igv) }}</span></div>
                <div class="flex justify-between border-t border-slate-200 pt-2 text-lg font-semibold">
                    <span>Total</span><span>{{ soles(c.total) }}</span>
                </div>
            </section>
        </div>

        <section class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                    <tr>
                        <th class="text-left p-3">Descripción</th>
                        <th class="text-left p-3">Unidad</th>
                        <th class="text-right p-3">Cantidad</th>
                        <th class="text-right p-3">Precio unit.</th>
                        <th class="text-right p-3">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="it in c.items" :key="it.id" class="border-t border-slate-100">
                        <td class="p-3">{{ it.descripcion }}</td>
                        <td class="p-3">{{ it.unidad }}</td>
                        <td class="p-3 text-right">{{ cantidad(it.cantidad) }}</td>
                        <td class="p-3 text-right">{{ it.bonificacion ? '—' : precio(it.precio_unitario) }}</td>
                        <td class="p-3 text-right font-medium">
                            <Tag v-if="it.bonificacion" value="Gratis" severity="success" />
                            <span v-else>{{ soles(it.importe) }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </AppLayout>
</template>