<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import ToggleSwitch from 'primevue/toggleswitch';

const props = defineProps({
    proveedor: Object, // null al crear
});

const editando = computed(() => !!props.proveedor);
const vacio = { ruc: '', razon_social: '', direccion: '', telefono: '', email: '', contacto: '', activo: true };
const p = props.proveedor;

const form = useForm(p ? Object.fromEntries(Object.keys(vacio).map((k) => [k, p[k] ?? vacio[k]])) : { ...vacio });

const guardar = () => {
    editando.value ? form.put(`/proveedores/${p.id}`) : form.post('/proveedores');
};

const titulo = computed(() => (editando.value ? `Editar: ${p.razon_social}` : 'Nuevo proveedor'));
</script>

<template>
    <Head :title="titulo" />
    <AppLayout :titulo="titulo">
        <form class="space-y-6" @submit.prevent="guardar">
            <section class="bg-white rounded-xl border border-slate-200 p-5">
                <h2 class="font-semibold mb-4">Datos del proveedor</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">RUC *</label>
                        <InputText v-model="form.ruc" maxlength="11" :invalid="!!form.errors.ruc" autofocus />
                        <small class="text-red-600">{{ form.errors.ruc }}</small>
                    </div>
                    <div class="xl:col-span-3 flex flex-col gap-1">
                        <label class="text-sm">Razón social *</label>
                        <InputText v-model="form.razon_social" :invalid="!!form.errors.razon_social" />
                        <small class="text-red-600">{{ form.errors.razon_social }}</small>
                    </div>
                    <div class="sm:col-span-2 flex flex-col gap-1">
                        <label class="text-sm">Dirección</label>
                        <InputText v-model="form.direccion" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Contacto / vendedor</label>
                        <InputText v-model="form.contacto" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Teléfono</label>
                        <InputText v-model="form.telefono" />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm">Correo</label>
                        <InputText v-model="form.email" type="email" :invalid="!!form.errors.email" />
                        <small class="text-red-600">{{ form.errors.email }}</small>
                    </div>
                    <label class="flex items-center gap-2 text-sm sm:pt-6"><ToggleSwitch v-model="form.activo" /> Activo</label>
                </div>
            </section>

            <div class="flex justify-end gap-2">
                <Link href="/proveedores"><Button label="Cancelar" severity="secondary" text /></Link>
                <Button type="submit" :label="editando ? 'Guardar cambios' : 'Registrar proveedor'" icon="pi pi-check" :loading="form.processing" />
            </div>
        </form>
    </AppLayout>
</template>