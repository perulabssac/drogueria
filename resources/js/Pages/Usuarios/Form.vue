<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Select from 'primevue/select';
import ToggleSwitch from 'primevue/toggleswitch';
import Message from 'primevue/message';

const props = defineProps({
    usuario: Object, // null al crear
    roles: Object,
    sucursales: Array,
});

const editando = computed(() => !!props.usuario);
const u = props.usuario;

const form = useForm({
    name: u?.name ?? '',
    email: u?.email ?? '',
    rol: u?.rol ?? 'vendedor',
    sucursal_id: u?.sucursal_id ?? props.sucursales[0]?.id ?? null,
    activo: u?.activo ?? true,
    password: '',
    password_confirmation: '',
});

const opcionesRol = Object.entries(props.roles).map(([value, label]) => ({ value, label }));

const PERMISOS = {
    admin: 'Todo el sistema: ventas, caja, notas de crédito, almacén, usuarios y configuración.',
    vendedor: 'Ventas, comprobantes, su propia caja y cobranzas. No ve almacén ni configuración.',
    almacen: 'Productos, compras y proveedores. No vende ni maneja caja.',
    contador: 'Solo consulta: reportes contables, comprobantes y compras (con descargas). No puede vender, cobrar ni modificar nada.',
};

const guardar = () => {
    const opciones = { onSuccess: () => form.reset('password', 'password_confirmation') };
    editando.value ? form.put(`/usuarios/${u.id}`, opciones) : form.post('/usuarios', opciones);
};

const titulo = computed(() => (editando.value ? `Editar usuario: ${u.name}` : 'Nuevo usuario'));
</script>

<template>
    <Head :title="titulo" />
    <AppLayout :titulo="titulo">
        <form class="space-y-6" @submit.prevent="guardar">
            <section class="bg-white rounded-xl border border-slate-200 p-5">
                <h2 class="font-semibold mb-4">Datos de acceso</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Nombre completo *</label>
                        <InputText v-model="form.name" :invalid="!!form.errors.name" autofocus />
                        <small class="text-red-600">{{ form.errors.name }}</small>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Correo (usuario para ingresar) *</label>
                        <InputText v-model="form.email" type="email" :invalid="!!form.errors.email" autocomplete="off" />
                        <small class="text-red-600">{{ form.errors.email }}</small>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Rol *</label>
                        <Select v-model="form.rol" :options="opcionesRol" optionLabel="label" optionValue="value" :invalid="!!form.errors.rol" />
                        <small class="text-red-600">{{ form.errors.rol }}</small>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Sucursal *</label>
                        <Select v-model="form.sucursal_id" :options="sucursales" optionLabel="nombre" optionValue="id" :invalid="!!form.errors.sucursal_id" />
                        <small class="text-red-600">{{ form.errors.sucursal_id }}</small>
                    </div>
                    <Message severity="secondary" :closable="false" class="sm:col-span-2 xl:col-span-3">
                        <b>{{ roles[form.rol] }}:</b> {{ PERMISOS[form.rol] }}
                    </Message>
                    <label class="flex items-center gap-2 text-sm"><ToggleSwitch v-model="form.activo" /> Activo (puede ingresar)</label>
                </div>
            </section>

            <section class="bg-white rounded-xl border border-slate-200 p-5">
                <h2 class="font-semibold">{{ editando ? 'Cambiar contraseña' : 'Contraseña inicial' }}</h2>
                <p class="text-xs text-slate-500 mb-4">
                    {{ editando ? 'Déjalo en blanco para mantener la actual. ' : '' }}Mínimo 8 caracteres, con letras y números. El usuario podrá cambiarla después desde el ícono de llave.
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Contraseña{{ editando ? ' nueva' : ' *' }}</label>
                        <Password v-model="form.password" toggleMask :feedback="false" fluid :invalid="!!form.errors.password" autocomplete="new-password" />
                        <small class="text-red-600">{{ form.errors.password }}</small>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Repetir contraseña</label>
                        <Password v-model="form.password_confirmation" toggleMask :feedback="false" fluid autocomplete="new-password" />
                    </div>
                </div>
            </section>

            <div class="flex justify-end gap-2">
                <Link href="/usuarios"><Button label="Cancelar" severity="secondary" text /></Link>
                <Button type="submit" :label="editando ? 'Guardar cambios' : 'Crear usuario'" icon="pi pi-check" :loading="form.processing" />
            </div>
        </form>
    </AppLayout>
</template>