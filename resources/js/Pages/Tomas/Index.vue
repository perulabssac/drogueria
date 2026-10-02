<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import Tag from 'primevue/tag';
import Message from 'primevue/message';
import ProgressBar from 'primevue/progressbar';
import { fecha } from '@/utils/formato';

const props = defineProps({
    tomas: Object,
    laboratorios: Array,
    categorias: Array,
    hayAbierta: Boolean,
});

const rol = usePage().props.auth.user.rol;
const puedeAbrir = ['admin', 'almacen'].includes(rol);

const ESTADOS = {
    en_conteo: { texto: 'En conteo', severidad: 'warn' },
    aprobada: { texto: 'Aprobada', severidad: 'success' },
    anulada: { texto: 'Anulada', severidad: 'secondary' },
};

// ================= NUEVA TOMA =================
const dialogo = ref(false);
const form = useForm({ alcance: 'todo', alcance_valor: null, observacion: '' });

const ALCANCES = [
    { value: 'todo', label: 'Todo el almacén', icono: 'pi pi-warehouse', ayuda: 'Se cuentan todos los lotes con stock.' },
    { value: 'laboratorio', label: 'Un laboratorio', icono: 'pi pi-building', ayuda: 'Ideal para contar por partes, un laboratorio cada vez.' },
    { value: 'categoria', label: 'Una categoría', icono: 'pi pi-tags', ayuda: 'Ej. solo antibióticos o solo productos controlados.' },
];
const elegirAlcance = (a) => {
    form.alcance = a;
    form.alcance_valor = null;
};
const opcionesValor = computed(() =>
    form.alcance === 'laboratorio'
        ? props.laboratorios.map((l) => ({ value: String(l.id), label: l.nombre }))
        : props.categorias.map((c) => ({ value: c, label: c })),
);

const abrir = () => form.post('/tomas', { onSuccess: () => (dialogo.value = false) });
const pagina = (e) => router.get('/tomas', { page: e.page + 1 }, { preserveScroll: true, replace: true });
</script>

<template>
    <Head title="Toma de inventario" />
    <AppLayout titulo="Toma de inventario">
        <Message severity="info" class="mb-4">
            <b>¿Cómo funciona?</b> 1) Abre una toma: el sistema congela el stock de cada lote. 2) Cuenta lo que hay en el almacén
            (en pantalla o con la hoja impresa). 3) El administrador la aprueba y las diferencias se corrigen solas con ajustes.
            <b>Consejo:</b> cuenta cuando no haya ventas (antes de abrir o después de cerrar).
        </Message>

        <div class="bg-white rounded-xl border border-slate-200">
            <div class="p-4 flex flex-wrap items-center gap-3 border-b border-slate-100">
                <p class="text-sm text-slate-600">Historial de tomas de inventario de este local.</p>
                <Button
                    v-if="puedeAbrir"
                    label="Nueva toma"
                    icon="pi pi-plus"
                    class="sm:ml-auto"
                    :disabled="hayAbierta"
                    v-tooltip.left="hayAbierta ? 'Ya hay una toma en conteo: termínala primero' : null"
                    @click="dialogo = true"
                />
            </div>

            <DataTable
                :value="tomas.data"
                lazy
                paginator
                :rows="tomas.per_page"
                :totalRecords="tomas.total"
                :first="(tomas.current_page - 1) * tomas.per_page"
                @page="pagina"
            >
                <template #empty>Aún no hay tomas de inventario.</template>
                <Column header="N°">
                    <template #body="{ data }">
                        <Link :href="`/tomas/${data.id}`" class="font-medium text-emerald-700 hover:underline">{{ data.numero }}</Link>
                        <p class="text-xs text-slate-500">{{ fecha(data.created_at) }} {{ data.created_at.substring(11, 16) }}</p>
                    </template>
                </Column>
                <Column header="Alcance">
                    <template #body="{ data }">{{ data.alcance_texto }}</template>
                </Column>
                <Column header="Estado">
                    <template #body="{ data }"><Tag :value="ESTADOS[data.estado].texto" :severity="ESTADOS[data.estado].severidad" /></template>
                </Column>
                <Column header="Avance" style="min-width: 10rem">
                    <template #body="{ data }">
                        <ProgressBar :value="data.items_count ? Math.round((data.contados_count / data.items_count) * 100) : 0" :showValue="false" style="height: 6px" />
                        <p class="text-xs text-slate-500 mt-1">{{ data.contados_count }} de {{ data.items_count }} lotes contados</p>
                    </template>
                </Column>
                <Column header="Diferencias" class="text-center">
                    <template #body="{ data }">
                        <span :class="data.diferencias_count ? 'text-red-600 font-semibold' : 'text-slate-400'">{{ data.diferencias_count }}</span>
                    </template>
                </Column>
                <Column header="Responsables">
                    <template #body="{ data }">
                        <p class="text-sm">Abrió: {{ data.usuario?.name }}</p>
                        <p v-if="data.aprobador" class="text-xs text-slate-500">Aprobó: {{ data.aprobador.name }}</p>
                    </template>
                </Column>
                <Column class="text-right">
                    <template #body="{ data }">
                        <Link :href="`/tomas/${data.id}`">
                            <Button
                                :icon="data.estado === 'en_conteo' ? 'pi pi-pencil' : 'pi pi-eye'"
                                :severity="data.estado === 'en_conteo' ? 'warn' : 'info'"
                                size="small"
                                v-tooltip.left="data.estado === 'en_conteo' ? 'Continuar conteo' : 'Ver resultado'"
                            />
                        </Link>
                    </template>
                </Column>
            </DataTable>
        </div>

        <!-- Abrir una toma -->
        <Dialog v-model:visible="dialogo" modal header="Nueva toma de inventario" :style="{ width: '36rem' }">
            <div class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <button
                        v-for="a in ALCANCES"
                        :key="a.value"
                        type="button"
                        class="rounded-lg border-2 p-3 text-left transition-colors"
                        :class="form.alcance === a.value ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200 hover:bg-slate-50'"
                        @click="elegirAlcance(a.value)"
                    >
                        <i :class="a.icono" class="text-emerald-700"></i>
                        <p class="font-medium text-sm mt-1">{{ a.label }}</p>
                    </button>
                </div>
                <p class="text-xs text-slate-500">{{ ALCANCES.find((a) => a.value === form.alcance).ayuda }}</p>

                <div v-if="form.alcance !== 'todo'" class="flex flex-col gap-1">
                    <label class="text-sm">{{ form.alcance === 'laboratorio' ? 'Laboratorio' : 'Categoría' }} *</label>
                    <Select
                        v-model="form.alcance_valor"
                        :options="opcionesValor"
                        optionLabel="label"
                        optionValue="value"
                        filter
                        placeholder="Elige"
                        fluid
                        :invalid="!!form.errors.alcance_valor"
                    />
                    <small class="text-red-600">{{ form.errors.alcance_valor }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-sm">Observación</label>
                    <Textarea v-model="form.observacion" rows="2" autoResize maxlength="500" fluid placeholder="Ej. Inventario de cierre de octubre" />
                </div>
                <small v-if="form.errors.alcance" class="text-red-600 block">{{ form.errors.alcance }}</small>
            </div>
            <template #footer>
                <Button label="Cancelar" severity="secondary" text @click="dialogo = false" />
                <Button label="Abrir toma" icon="pi pi-play" :loading="form.processing" :disabled="form.alcance !== 'todo' && !form.alcance_valor" @click="abrir" />
            </template>
        </Dialog>
    </AppLayout>
</template>