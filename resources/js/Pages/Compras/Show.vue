<script setup>
import { computed, ref, watch } from 'vue';
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
    puedeActualizarPrecios: Boolean,
    preciosSugeridos: { type: Array, default: () => [] },
    sinMargen: { type: Array, default: () => [] },
});

const confirm = useConfirm();
const page = usePage();
const anulada = computed(() => props.compra.estado === 'anulada');
const soloLectura = page.props.auth.user.rol === 'contador';

// Pago al proveedor (solo compras al crédito)
const ESTADO_PAGO = { pendiente: ['Por pagar', 'info'], vencida: ['Vencida', 'danger'], pagada: ['Pagada', 'success'] };
const conDeuda = computed(() => props.compra.forma_pago === 'credito' && !anulada.value);
const pagosActivos = computed(() => (props.compra.pagos ?? []).filter((p) => p.estado === 'activo'));
const pagado = computed(() => pagosActivos.value.reduce((t, p) => t + Number(p.monto), 0));
const puedePagar = ['admin', 'contador'].includes(page.props.auth.user.rol);

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

// Precios de venta sugeridos con el nuevo costo y el margen de cada producto (todos marcados al inicio)
const seleccion = ref([...props.preciosSugeridos]);
watch(() => props.preciosSugeridos, (lista) => (seleccion.value = [...lista]));
const actualizando = ref(false);

const diferencia = (actual, sugerido) => Number(sugerido) - Number(actual ?? 0);
const claseDiferencia = (d) => (d > 0 ? 'text-red-600' : 'text-emerald-600');

const actualizarPrecios = () => {
    confirm.require({
        header: 'Actualizar precios de venta',
        message: `Se cambiará el precio de venta de ${seleccion.value.length} producto(s). Desde ahora se venderán al nuevo precio. ¿Continuar?`,
        icon: 'pi pi-tags',
        acceptProps: { label: 'Actualizar', severity: 'warn' },
        rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
        accept: () =>
            router.post(
                `/compras/${props.compra.id}/precios`,
                { productos: seleccion.value.map((p) => p.id) },
                {
                    preserveScroll: true,
                    onStart: () => (actualizando.value = true),
                    onFinish: () => (actualizando.value = false),
                },
            ),
    });
};
</script>

<template>
    <Head :title="'Compra ' + compra.documento" />
    <AppLayout :titulo="tiposDocumento[compra.tipo_documento] + ' ' + compra.documento">
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <Link href="/compras"><Button label="Volver" icon="pi pi-arrow-left" text /></Link>
            <Tag v-if="anulada" value="ANULADA" severity="danger" />
            <Tag v-else-if="conDeuda" :value="ESTADO_PAGO[compra.estado_pago][0]" :severity="ESTADO_PAGO[compra.estado_pago][1]" />
            <Button v-if="!anulada && !soloLectura" label="Anular compra" icon="pi pi-times" severity="danger" outlined class="ml-auto" @click="anular" />
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

        <!-- Nuevos precios de venta sugeridos (margen de cada producto sobre su nuevo costo) -->
        <section
            v-if="puedeActualizarPrecios && (preciosSugeridos.length || sinMargen.length)"
            class="bg-white rounded-xl border border-amber-300 mb-6"
        >
            <div class="flex flex-wrap items-center gap-2 p-4 border-b border-amber-200 bg-amber-50 rounded-t-xl">
                <i class="pi pi-tags text-amber-600"></i>
                <div>
                    <h2 class="font-semibold text-amber-900">Precios de venta sugeridos con el nuevo costo</h2>
                    <p class="text-xs text-amber-800">Calculados con el margen de cada producto. Marca los que quieres actualizar.</p>
                </div>
                <Button
                    v-if="preciosSugeridos.length"
                    :label="`Actualizar ${seleccion.length} precio(s)`"
                    icon="pi pi-check"
                    severity="warn"
                    class="ml-auto"
                    :disabled="!seleccion.length"
                    :loading="actualizando"
                    @click="actualizarPrecios"
                />
            </div>

            <p v-if="page.props.errors?.productos" class="m-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm">{{ page.props.errors.productos }}</p>

            <DataTable v-if="preciosSugeridos.length" v-model:selection="seleccion" :value="preciosSugeridos" dataKey="id" size="small">
                <Column selectionMode="multiple" headerStyle="width: 3rem" />
                <Column header="Producto">
                    <template #body="{ data }">
                        <span class="text-slate-500">{{ data.codigo }}</span> · {{ data.nombre }}
                    </template>
                </Column>
                <Column header="Costo s/IGV" class="text-right">
                    <template #body="{ data }">{{ precio(data.costo) }}</template>
                </Column>
                <Column header="Margen" class="text-right">
                    <template #body="{ data }">{{ Number(data.margen) }} %</template>
                </Column>
                <Column header="Precio presentación (con IGV)">
                    <template #body="{ data }">
                        <span class="text-slate-500">{{ data.unidad_venta }}</span>
                        {{ soles(data.precio_actual) }} <i class="pi pi-arrow-right text-xs text-slate-400 mx-1"></i>
                        <span class="font-semibold">{{ soles(data.precio_sugerido) }}</span>
                        <span v-if="diferencia(data.precio_actual, data.precio_sugerido) !== 0" class="ml-1 text-xs" :class="claseDiferencia(diferencia(data.precio_actual, data.precio_sugerido))">
                            ({{ diferencia(data.precio_actual, data.precio_sugerido) > 0 ? '+' : '' }}{{ diferencia(data.precio_actual, data.precio_sugerido).toFixed(2) }})
                        </span>
                    </template>
                </Column>
                <Column header="Precio fracción (con IGV)">
                    <template #body="{ data }">
                        <template v-if="data.fraccion_sugerida !== null">
                            <span class="text-slate-500">{{ data.unidad_fraccion }}</span>
                            {{ soles(data.fraccion_actual ?? 0) }} <i class="pi pi-arrow-right text-xs text-slate-400 mx-1"></i>
                            <span class="font-semibold">{{ soles(data.fraccion_sugerida) }}</span>
                        </template>
                        <span v-else class="text-slate-400">—</span>
                    </template>
                </Column>
            </DataTable>
            <p v-else class="p-4 text-sm text-emerald-700"><i class="pi pi-check-circle mr-1"></i>Los precios con margen ya están al día con este costo.</p>

            <div v-if="sinMargen.length" class="p-4 border-t border-slate-100 text-sm text-slate-600">
                <p class="mb-1"><i class="pi pi-info-circle text-sky-600 mr-1"></i>Sin margen asignado (revisa su precio a mano o asígnales un margen en Productos):</p>
                <div class="flex flex-wrap gap-2">
                    <Link v-for="p in sinMargen" :key="p.id" :href="`/productos/${p.id}/editar`" class="px-2 py-0.5 rounded bg-sky-50 text-sky-700 hover:bg-sky-100">
                        {{ p.codigo }} · {{ p.nombre }}
                    </Link>
                </div>
            </div>
        </section>

        <section class="flex flex-col lg:flex-row gap-6 lg:items-start">
            <!-- Pago al proveedor (compras al crédito) -->
            <div v-if="conDeuda" class="flex-1 bg-white rounded-xl border border-slate-200 p-4 text-sm">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-semibold">Pago al proveedor</h2>
                    <Link :href="`/cuentas-por-pagar/${compra.id}`">
                        <Button
                            :label="puedePagar && Number(compra.saldo) > 0 ? 'Registrar pago' : 'Ver pagos'"
                            :icon="puedePagar && Number(compra.saldo) > 0 ? 'pi pi-wallet' : 'pi pi-eye'"
                            :severity="puedePagar && Number(compra.saldo) > 0 ? 'success' : 'info'"
                            size="small"
                        />
                    </Link>
                </div>
                <div class="grid grid-cols-3 gap-3 mb-3">
                    <div class="rounded-lg bg-slate-50 p-2">
                        <p class="text-xs text-slate-500">Vence</p>
                        <p class="font-medium" :class="compra.estado_pago === 'vencida' ? 'text-red-600' : ''">{{ fecha(compra.fecha_vencimiento) }}</p>
                    </div>
                    <div class="rounded-lg bg-emerald-50 p-2">
                        <p class="text-xs text-emerald-700">Pagado</p>
                        <p class="font-medium text-emerald-800">{{ soles(pagado) }}</p>
                    </div>
                    <div class="rounded-lg p-2" :class="Number(compra.saldo) > 0 ? 'bg-red-50' : 'bg-emerald-50'">
                        <p class="text-xs" :class="Number(compra.saldo) > 0 ? 'text-red-700' : 'text-emerald-700'">Saldo</p>
                        <p class="font-medium" :class="Number(compra.saldo) > 0 ? 'text-red-800' : 'text-emerald-800'">{{ soles(compra.saldo) }}</p>
                    </div>
                </div>
                <p v-if="!pagosActivos.length" class="text-slate-400">Aún no se le ha pagado al proveedor.</p>
                <div v-for="p in pagosActivos" :key="p.id" class="flex justify-between border-t border-slate-100 py-1.5">
                    <span>{{ fecha(p.fecha) }} · {{ p.medio_nombre }}<span v-if="p.referencia" class="text-slate-500"> · {{ p.referencia }}</span></span>
                    <span class="font-medium">{{ soles(p.monto) }}</span>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 p-4 space-y-2 text-sm w-full lg:w-80 lg:ml-auto">
                <div class="flex justify-between"><span>Op. gravadas</span><span>{{ soles(compra.op_gravadas) }}</span></div>
                <div class="flex justify-between"><span>Op. exoneradas</span><span>{{ soles(compra.op_exoneradas) }}</span></div>
                <div class="flex justify-between"><span>IGV (18%)</span><span>{{ soles(compra.igv) }}</span></div>
                <div class="flex justify-between text-lg font-semibold border-t pt-2"><span>Total</span><span>{{ soles(compra.total) }}</span></div>
            </div>
        </section>
    </AppLayout>
</template>