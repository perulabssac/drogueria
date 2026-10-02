<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';
import { soles, precio, fecha, cantidad } from '@/utils/formato';

const props = defineProps({
    compra: Object,
    tiposDocumento: Object,
});

const confirm = useConfirm();
const page = usePage();
const anulada = computed(() => props.compra.estado === 'anulada');
const soloLectura = page.props.auth.user.rol === 'contador';

const anular = () => {
    confirm.require({
        header: 'Anular compra',
        message: 'Se retirará del almacén todo lo que ingresó con esta compra. Si ya se vendió parte de algún lote, no se podrá anular. ¿Continuar?',
        icon: 'pi pi-exclamation-triangle',
        acceptProps: { label: 'Anular', severity: 'danger' },
        rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
        accept: () => router.post(`/compras/${props.compra.id}/anular`, {}, { preserveScroll: true }),
    });
};
</script>

<template>
    <Head :title="'Compra ' + compra.documento" />
    <AppLayout :titulo="tiposDocumento[compra.tipo_documento] + ' ' + compra.documento">
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <Link href="/compras"><Button label="Volver" icon="pi pi-arrow-left" text /></Link>
            <Tag v-if="anulada" value="ANULADA" severity="danger" />
            <Button v-else-if="!soloLectura" label="Anular compra" icon="pi pi-times" severity="danger" outlined class="ml-auto" @click="anular" />
        </div>

        <!-- Errores de negocio (ej. no se puede anular) -->
        <p v-if="page.props.errors?.compra" class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm">{{ page.props.errors.compra }}</p>

        <section class="bg-white rounded-xl border border-slate-200 p-4 grid sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm mb-6">
            <div>
                <p class="text-slate-500 text-xs">Proveedor</p>
                <p class="font-medium">{{ compra.proveedor.razon_social }}</p>
                <p class="text-slate-500">RUC {{ compra.proveedor.ruc }}</p>
            </div>
            <div>
                <p class="text-slate-500 text-xs">Fecha de emisión</p>
                <p class="font-medium">{{ fecha(compra.fecha_emision) }}</p>
            </div>
            <div>
                <p class="text-slate-500 text-xs">Condición</p>
                <p class="font-medium">
                    {{ compra.forma_pago === 'credito' ? 'Crédito · vence ' + fecha(compra.fecha_vencimiento) : 'Contado' }}
                </p>
            </div>
            <div>
                <p class="text-slate-500 text-xs">Registrado por</p>
                <p class="font-medium">{{ compra.usuario.name }}</p>
            </div>
            <p v-if="compra.observaciones" class="sm:col-span-2 lg:col-span-4 text-slate-600">{{ compra.observaciones }}</p>
        </section>

        <section class="bg-white rounded-xl border border-slate-200 mb-6">
            <DataTable :value="compra.items" size="small">
                <Column header="Código">
                    <template #body="{ data }">{{ data.producto.codigo }}</template>
                </Column>
                <Column header="Cant." class="text-right">
                    <template #body="{ data }">{{ cantidad(data.cantidad) }}</template>
                </Column>
                <Column header="Unid.">
                    <template #body="{ data }">{{ data.producto.unidad_venta }}</template>
                </Column>
                <Column header="Descripción">
                    <template #body="{ data }">
                        {{ data.producto.nombre }} {{ data.producto.concentracion }} {{ data.producto.presentacion }}
                        <Tag v-if="data.bonificacion" value="Bonificación" severity="success" class="ml-1" />
                    </template>
                </Column>
                <Column header="F. venc.">
                    <template #body="{ data }">{{ fecha(data.fecha_vencimiento).substring(3) }}</template>
                </Column>
                <Column field="numero_lote" header="N° lote" />
                <Column header="Precio unit." class="text-right">
                    <template #body="{ data }">{{ precio(data.precio_unitario) }}</template>
                </Column>
                <Column header="Importe" class="text-right">
                    <template #body="{ data }">{{ soles(data.total) }}</template>
                </Column>
            </DataTable>
        </section>

        <section class="flex justify-end">
            <div class="bg-white rounded-xl border border-slate-200 p-4 space-y-2 text-sm w-full sm:w-80">
                <div class="flex justify-between"><span>Op. gravadas</span><span>{{ soles(compra.op_gravadas) }}</span></div>
                <div class="flex justify-between"><span>Op. exoneradas</span><span>{{ soles(compra.op_exoneradas) }}</span></div>
                <div class="flex justify-between"><span>IGV (18%)</span><span>{{ soles(compra.igv) }}</span></div>
                <div class="flex justify-between text-lg font-semibold border-t pt-2"><span>Total</span><span>{{ soles(compra.total) }}</span></div>
            </div>
        </section>
    </AppLayout>
</template>