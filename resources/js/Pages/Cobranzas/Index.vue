<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import SelectButton from 'primevue/selectbutton';
import Tag from 'primevue/tag';
import { soles, fecha } from '@/utils/formato';

const props = defineProps({
    cuentas: Object,
    filtros: Object,
    resumen: Object,
});

const buscar = ref(props.filtros.buscar ?? '');
const estado = ref(props.filtros.estado ?? 'pendientes');
const opcionesEstado = [
    { value: 'pendientes', label: 'Por cobrar' },
    { value: 'vencidas', label: 'Vencidas' },
    { value: 'canceladas', label: 'Canceladas' },
    { value: 'todas', label: 'Todas' },
];

const recargar = (extra = {}) =>
    router.get(
        '/cobranzas',
        { buscar: buscar.value || undefined, estado: estado.value, ...extra },
        { preserveState: true, preserveScroll: true, replace: true },
    );

let espera;
watch(buscar, () => {
    clearTimeout(espera);
    espera = setTimeout(() => recargar(), 350);
});
watch(estado, () => recargar());

// Situación de la cuenta según su próxima cuota pendiente
const situacion = (c) => {
    if (c.saldo <= 0) return { texto: 'Cancelada', severidad: 'success' };
    if (c.vencido > 0) return { texto: `Vencida ${Math.abs(c.proxima?.dias ?? 0)} d`, severidad: 'danger' };
    if (c.proxima && c.proxima.dias <= 7) return { texto: c.proxima.dias === 0 ? 'Vence hoy' : `Vence en ${c.proxima.dias} d`, severidad: 'warn' };
    return { texto: 'Al día', severidad: 'info' };
};
</script>

<template>
    <Head title="Cobranzas" />
    <AppLayout titulo="Cobranzas (cuentas por cobrar)">
        <!-- Resumen de la cartera -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <p class="text-xs text-slate-500 uppercase">Total por cobrar</p>
                <p class="text-2xl font-semibold mt-1">{{ soles(resumen.por_cobrar) }}</p>
                <p class="text-xs text-slate-500 mt-1">{{ resumen.cuentas }} ventas · {{ resumen.clientes }} clientes</p>
            </div>
            <div class="bg-white rounded-xl border border-red-200 p-5">
                <p class="text-xs text-red-600 uppercase">Vencido</p>
                <p class="text-2xl font-semibold mt-1 text-red-600">{{ soles(resumen.vencido) }}</p>
                <p class="text-xs text-slate-500 mt-1">Cuotas que ya debieron pagarse</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <p class="text-xs text-slate-500 uppercase">Por vencer</p>
                <p class="text-2xl font-semibold mt-1">{{ soles(resumen.por_cobrar - resumen.vencido) }}</p>
                <p class="text-xs text-slate-500 mt-1">Cuotas aún dentro de plazo</p>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200">
            <div class="p-4 flex flex-wrap gap-3 items-center border-b border-slate-100">
                <IconField class="w-full sm:w-72">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="buscar" placeholder="Cliente, RUC/DNI o número (F001-25)" fluid />
                </IconField>
                <SelectButton v-model="estado" :options="opcionesEstado" optionLabel="label" optionValue="value" :allowEmpty="false" />
            </div>

            <DataTable
                :value="cuentas.data"
                lazy
                paginator
                :rows="cuentas.per_page"
                :totalRecords="cuentas.total"
                :first="(cuentas.current_page - 1) * cuentas.per_page"
                :rowClass="(c) => (c.vencido > 0 ? 'bg-red-50/40' : '')"
                @page="(e) => recargar({ page: e.page + 1 })"
            >
                <template #empty>No hay cuentas en esta vista.</template>
                <Column header="Comprobante">
                    <template #body="{ data }">
                        <Link :href="`/cobranzas/${data.id}`" class="font-medium text-emerald-700 hover:underline">{{ data.numero }}</Link>
                        <p class="text-xs text-slate-500">{{ data.tipo_nombre }} · {{ fecha(data.fecha_emision) }}</p>
                    </template>
                </Column>
                <Column header="Cliente">
                    <template #body="{ data }">
                        <p>{{ data.cliente?.razon_social }}</p>
                        <p class="text-xs text-slate-500">
                            {{ data.cliente?.numero_documento }}<span v-if="data.cliente?.telefono"> · <i class="pi pi-phone text-[10px]"></i> {{ data.cliente.telefono }}</span>
                        </p>
                    </template>
                </Column>
                <Column header="Próxima cuota">
                    <template #body="{ data }">
                        <template v-if="data.proxima">
                            <p>{{ fecha(data.proxima.fecha_vencimiento) }}</p>
                            <p class="text-xs text-slate-500">Cuota {{ data.proxima.numero }} de {{ data.cuotas }} · {{ soles(data.proxima.pendiente) }}</p>
                        </template>
                        <span v-else class="text-slate-400">—</span>
                    </template>
                </Column>
                <Column header="Estado">
                    <template #body="{ data }">
                        <Tag :value="situacion(data).texto" :severity="situacion(data).severidad" />
                    </template>
                </Column>
                <Column header="Total" class="text-right">
                    <template #body="{ data }">{{ soles(data.total) }}</template>
                </Column>
                <Column header="Saldo" class="text-right">
                    <template #body="{ data }">
                        <span class="font-semibold" :class="data.vencido > 0 ? 'text-red-600' : ''">{{ soles(data.saldo) }}</span>
                    </template>
                </Column>
                <Column class="text-right">
                    <template #body="{ data }">
                        <Link :href="`/cobranzas/${data.id}`">
                            <Button v-if="data.saldo > 0" label="Cobrar" icon="pi pi-money-bill" severity="success" size="small" />
                            <Button v-else icon="pi pi-eye" severity="info" size="small" v-tooltip.top="'Ver detalle'" />
                        </Link>
                    </template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>