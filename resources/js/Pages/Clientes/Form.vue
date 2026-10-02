<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import ToggleSwitch from 'primevue/toggleswitch';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { soles, fecha } from '@/utils/formato';
import { enviarJson } from '@/utils/http';

const props = defineProps({
    cliente: Object, // null al crear
    tipos: Object,
    consultaActiva: Boolean,
    puedeEditarCredito: Boolean,
});

const editando = computed(() => !!props.cliente);
const c = props.cliente;
const campos = {
    tipo_documento: '6', numero_documento: '', razon_social: '', nombre_comercial: '',
    direccion: '', distrito: '', provincia: '', departamento: '',
    telefono: '', email: '', contacto: '', dias_credito: 0, limite_credito: 0, observaciones: '', activo: true,
};
const form = useForm(Object.fromEntries(Object.entries(campos).map(([k, v]) => [k, c?.[k] ?? v])));
form.limite_credito = Number(form.limite_credito);

const opcionesTipo = Object.entries(props.tipos).map(([value, label]) => ({ value, label }));
const consultable = computed(() => ['1', '6'].includes(form.tipo_documento));

// ===== Nuevo: buscar en SUNAT/RENIEC (si existe, se abre su ficha; si no, se registra y se abre) =====
const consultando = ref(false);
const errorConsulta = ref('');
const consultar = async () => {
    errorConsulta.value = '';
    consultando.value = true;
    try {
        const r = await enviarJson('/api/clientes/consultar', { numero: form.numero_documento.trim() });
        router.visit(`/clientes/${r.cliente.id}/editar`);
    } catch (e) {
        errorConsulta.value = e.errores?.numero?.[0] ?? e.message;
    } finally {
        consultando.value = false;
    }
};

// ===== Ficha: volver a consultar SUNAT/RENIEC (gasta 1 consulta) =====
const verificando = ref(false);
const verificar = () =>
    router.post(`/clientes/${c.id}/verificar`, {}, {
        preserveScroll: true,
        onStart: () => (verificando.value = true),
        onFinish: () => (verificando.value = false),
    });

const guardar = () => (editando.value ? form.put(`/clientes/${c.id}`) : form.post('/clientes'));
const titulo = computed(() => (editando.value ? c.razon_social : 'Nuevo cliente'));
</script>

<template>
    <Head :title="titulo" />
    <AppLayout :titulo="titulo">
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <Link href="/clientes"><Button label="Volver" icon="pi pi-arrow-left" text /></Link>
            <template v-if="editando">
                <Tag v-if="c.problema_sunat" :value="`${c.estado_sunat} · ${c.condicion_sunat}`" severity="danger" icon="pi pi-exclamation-triangle" />
                <Tag v-else-if="c.estado_sunat" value="SUNAT: Activo · Habido" severity="success" icon="pi pi-verified" />
                <span v-if="c.verificado_at" class="text-xs text-slate-500">Verificado el {{ fecha(c.verificado_at) }}</span>
                <Button
                    v-if="consultaActiva && ['1', '6'].includes(c.tipo_documento)"
                    :label="c.tipo_documento === '6' ? 'Actualizar desde SUNAT' : 'Actualizar desde RENIEC'"
                    icon="pi pi-refresh"
                    severity="help"
                    size="small"
                    class="ml-auto"
                    :loading="verificando"
                    v-tooltip.bottom="'Gasta 1 consulta de Decolecta'"
                    @click="verificar"
                />
            </template>
        </div>

        <Message v-if="editando && c.problema_sunat" severity="error" class="mb-4">
            {{ c.problema_sunat }} No se le pueden emitir facturas hasta que regularice su situación en SUNAT.
        </Message>

        <form class="space-y-6" @submit.prevent="guardar">
            <!-- Documento -->
            <section class="bg-white rounded-xl border border-slate-200 p-5">
                <h2 class="font-semibold mb-4">Documento</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Tipo *</label>
                        <Select v-model="form.tipo_documento" :options="opcionesTipo" optionLabel="label" optionValue="value" :disabled="editando" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Número *</label>
                        <InputText v-model="form.numero_documento" :disabled="editando" :invalid="!!form.errors.numero_documento" maxlength="15" autofocus @keydown.enter.prevent="!editando && consultable && consultaActiva && consultar()" />
                        <small class="text-red-600">{{ form.errors.numero_documento }}</small>
                    </div>
                    <div v-if="!editando && consultable && consultaActiva" class="flex flex-col gap-1 sm:pt-6">
                        <Button
                            :label="form.tipo_documento === '6' ? 'Buscar en SUNAT' : 'Buscar en RENIEC'"
                            icon="pi pi-search"
                            severity="help"
                            :loading="consultando"
                            :disabled="!form.numero_documento"
                            @click="consultar"
                        />
                    </div>
                </div>
                <Message v-if="errorConsulta" severity="warn" class="mt-3">{{ errorConsulta }}</Message>
                <p v-if="!editando && consultable && consultaActiva" class="text-xs text-slate-500 mt-3">
                    Escribe el número y pulsa <b>Buscar</b>: los datos oficiales se llenan solos. Si el cliente ya existe, se abre su ficha sin gastar consultas.
                </p>
            </section>

            <!-- Datos -->
            <section class="bg-white rounded-xl border border-slate-200 p-5">
                <h2 class="font-semibold mb-4">Datos del cliente</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                    <div class="sm:col-span-2 flex flex-col gap-1">
                        <label class="text-sm">{{ form.tipo_documento === '6' ? 'Razón social' : 'Nombre completo' }} *</label>
                        <InputText v-model="form.razon_social" :invalid="!!form.errors.razon_social" />
                        <small class="text-red-600">{{ form.errors.razon_social }}</small>
                    </div>
                    <div class="sm:col-span-2 flex flex-col gap-1">
                        <label class="text-sm">Nombre comercial</label>
                        <InputText v-model="form.nombre_comercial" placeholder="Ej. Botica San Juan" />
                    </div>
                    <div class="sm:col-span-2 xl:col-span-4 flex flex-col gap-1">
                        <label class="text-sm">Dirección</label>
                        <InputText v-model="form.direccion" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Distrito</label>
                        <InputText v-model="form.distrito" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Provincia</label>
                        <InputText v-model="form.provincia" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Departamento</label>
                        <InputText v-model="form.departamento" />
                    </div>
                </div>
            </section>

            <!-- Contacto -->
            <section class="bg-white rounded-xl border border-slate-200 p-5">
                <h2 class="font-semibold mb-4">Contacto</h2>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Persona de contacto</label>
                        <InputText v-model="form.contacto" placeholder="Ej. Q.F. Ana Torres" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Teléfono / WhatsApp</label>
                        <InputText v-model="form.telefono" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Correo</label>
                        <InputText v-model="form.email" type="email" :invalid="!!form.errors.email" />
                        <small class="text-red-600">{{ form.errors.email }}</small>
                    </div>
                </div>
            </section>

            <!-- Crédito -->
            <section class="bg-white rounded-xl border border-slate-200 p-5">
                <h2 class="font-semibold">Crédito</h2>
                <p class="text-xs text-slate-500 mb-4">
                    0 días = solo contado. Límite 0 = sin tope.
                    <span v-if="!puedeEditarCredito">Solo el administrador puede cambiar estas condiciones.</span>
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Días de crédito</label>
                        <InputNumber v-model="form.dias_credito" :min="0" :max="365" suffix=" días" :disabled="!puedeEditarCredito" fluid />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Límite de crédito</label>
                        <InputNumber v-model="form.limite_credito" prefix="S/ " locale="en-US" :minFractionDigits="2" :min="0" :disabled="!puedeEditarCredito" fluid />
                    </div>
                    <template v-if="editando">
                        <div>
                            <p class="text-sm">Deuda actual</p>
                            <p class="text-xl font-semibold" :class="c.deuda > 0 ? 'text-red-600' : 'text-slate-400'">{{ soles(c.deuda) }}</p>
                            <Tag v-if="c.deuda_vencida" value="Tiene cuotas vencidas" severity="danger" />
                        </div>
                        <div v-if="c.deuda > 0" class="sm:pt-6">
                            <Link :href="`/cobranzas?buscar=${c.numero_documento}`">
                                <Button label="Ver sus cuentas por cobrar" icon="pi pi-money-bill" severity="success" outlined size="small" />
                            </Link>
                        </div>
                    </template>
                </div>
            </section>

            <!-- Otros -->
            <section class="bg-white rounded-xl border border-slate-200 p-5">
                <h2 class="font-semibold mb-4">Otros</h2>
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div class="sm:col-span-3 flex flex-col gap-1">
                        <label class="text-sm">Observaciones</label>
                        <Textarea v-model="form.observaciones" rows="2" autoResize maxlength="500" placeholder="Ej. Recibe pedidos martes y viernes" />
                    </div>
                    <label class="flex items-center gap-2 text-sm sm:pt-6"><ToggleSwitch v-model="form.activo" /> Activo</label>
                </div>
            </section>

            <div class="flex justify-end gap-2">
                <Link href="/clientes"><Button label="Cancelar" severity="secondary" text /></Link>
                <Button type="submit" :label="editando ? 'Guardar cambios' : 'Registrar cliente'" icon="pi pi-check" :loading="form.processing" />
            </div>
        </form>
    </AppLayout>
</template>