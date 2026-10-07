<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import DatePicker from 'primevue/datepicker';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import { aFechaISO } from '@/utils/http';

const props = defineProps({
    registros: Object,
    filtros: Object,
    usuarios: Array,
    modulos: Array,
    eventos: Object,
});

// ---------- Filtros ----------
const aFecha = (iso) => (iso ? new Date(iso + 'T00:00:00') : null);
const desde = ref(aFecha(props.filtros.desde));
const hasta = ref(aFecha(props.filtros.hasta));
const usuario = ref(props.filtros.usuario ? Number(props.filtros.usuario) : null);
const modulo = ref(props.filtros.modulo ?? null);
const evento = ref(props.filtros.evento ?? null);
const buscar = ref(props.filtros.buscar ?? '');

const opcionesEvento = Object.entries(props.eventos).map(([value, label]) => ({ value, label }));

const recargar = (extra = {}) =>
    router.get(
        '/auditoria',
        {
            desde: aFechaISO(desde.value) || undefined,
            hasta: aFechaISO(hasta.value) || undefined,
            usuario: usuario.value || undefined,
            modulo: modulo.value || undefined,
            evento: evento.value || undefined,
            buscar: buscar.value || undefined,
            ...extra,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );

watch([desde, hasta, usuario, modulo, evento], () => recargar());
let espera;
watch(buscar, () => {
    clearTimeout(espera);
    espera = setTimeout(() => recargar(), 350);
});

const limpiar = () => {
    usuario.value = null;
    modulo.value = null;
    evento.value = null;
    buscar.value = '';
};

// ---------- Formato ----------
// Cada tipo de evento con un color distinto
const COLOR_EVENTO = {
    creado: 'success',
    actualizado: 'info',
    eliminado: 'danger',
    login: 'secondary',
    logout: 'contrast',
    login_fallido: 'warn',
};
const fechaHora = (valor) => {
    const [f, h] = String(valor).split(' ');
    const [a, m, d] = f.split('-');
    return `${d}/${m}/${a} ${h?.substring(0, 5) ?? ''}`;
};
const mostrar = (v) => (v === null || v === undefined || v === '' ? '(vacío)' : v);

// Filas con cambios: se despliegan para ver el antes y el después
const expandidas = ref({});
const tieneCambios = (r) => r.cambios && Object.keys(r.cambios).length > 0;
</script>

<template>
    <Head title="Auditoría" />
    <AppLayout titulo="Auditoría">
        <div class="bg-white rounded-xl border border-slate-200">
            <div class="p-4 flex flex-wrap gap-3 items-center border-b border-slate-100">
                <DatePicker v-model="desde" placeholder="Desde" dateFormat="dd/mm/yy" showIcon class="w-full sm:w-40" />
                <DatePicker v-model="hasta" placeholder="Hasta" dateFormat="dd/mm/yy" showIcon class="w-full sm:w-40" />
                <Select v-model="usuario" :options="usuarios" optionLabel="name" optionValue="id" placeholder="Usuario" showClear class="w-full sm:w-44" />
                <Select v-model="modulo" :options="modulos" placeholder="Módulo" showClear class="w-full sm:w-44" />
                <Select v-model="evento" :options="opcionesEvento" optionLabel="label" optionValue="value" placeholder="Evento" showClear class="w-full sm:w-52" />
                <IconField class="w-full sm:w-64">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="buscar" placeholder="Buscar en la descripción" fluid />
                </IconField>
                <Button label="Limpiar filtros" icon="pi pi-filter-slash" severity="secondary" text @click="limpiar" />
            </div>

            <DataTable
                v-model:expandedRows="expandidas"
                :value="registros.data"
                dataKey="id"
                size="small"
                lazy
                paginator
                :rows="registros.per_page"
                :totalRecords="registros.total"
                :first="(registros.current_page - 1) * registros.per_page"
                @page="(e) => recargar({ page: e.page + 1 })"
            >
                <template #empty>No hay registros con estos filtros.</template>
                <Column expander headerStyle="width: 3rem">
                    <template #rowtogglericon="{ rowExpanded }">
                        <i :class="rowExpanded ? 'pi pi-chevron-down' : 'pi pi-chevron-right'"></i>
                    </template>
                </Column>
                <Column header="Fecha y hora" class="whitespace-nowrap">
                    <template #body="{ data }">{{ fechaHora(data.created_at) }}</template>
                </Column>
                <Column header="Usuario">
                    <template #body="{ data }">
                        <span v-if="data.usuario">{{ data.usuario.name }}</span>
                        <span v-else class="text-slate-400">{{ data.evento === 'login_fallido' ? 'Desconocido' : 'Sistema' }}</span>
                    </template>
                </Column>
                <Column header="Evento">
                    <template #body="{ data }"><Tag :value="data.evento_nombre" :severity="COLOR_EVENTO[data.evento] ?? 'secondary'" /></template>
                </Column>
                <Column field="modulo" header="Módulo" />
                <Column header="Descripción">
                    <template #body="{ data }">
                        {{ data.descripcion }}
                        <span v-if="tieneCambios(data)" class="ml-1 text-xs text-sky-600">({{ Object.keys(data.cambios).length }} cambio(s))</span>
                    </template>
                </Column>
                <Column header="IP">
                    <template #body="{ data }"><span class="text-slate-500 text-sm">{{ data.ip ?? '—' }}</span></template>
                </Column>

                <!-- Detalle: antes y después de cada campo, y desde qué navegador -->
                <template #expansion="{ data }">
                    <div class="p-3 bg-slate-50 rounded-lg text-sm space-y-2">
                        <table v-if="tieneCambios(data)" class="w-full max-w-3xl">
                            <thead class="text-xs text-slate-500">
                                <tr>
                                    <th class="text-left font-normal pb-1">Campo</th>
                                    <th class="text-left font-normal pb-1">Antes</th>
                                    <th class="text-left font-normal pb-1">Después</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(valores, campo) in data.cambios" :key="campo" class="border-t border-slate-200">
                                    <td class="py-1 font-medium">{{ campo }}</td>
                                    <td class="py-1 text-red-700">{{ mostrar(valores[0]) }}</td>
                                    <td class="py-1 text-emerald-700">{{ mostrar(valores[1]) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="text-slate-500">Sin detalle de campos.</p>
                        <p class="text-xs text-slate-500">Navegador: {{ data.navegador ?? '—' }}</p>
                    </div>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>