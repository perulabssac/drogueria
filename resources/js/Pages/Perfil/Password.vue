<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import Password from 'primevue/password';

const form = useForm({ password_actual: '', password: '', password_confirmation: '' });

const guardar = () => form.put('/perfil/password', { onFinish: () => form.reset() });
</script>

<template>
    <Head title="Cambiar contraseña" />
    <AppLayout titulo="Cambiar mi contraseña">
        <form class="bg-white rounded-xl border border-slate-200 p-5 max-w-xl space-y-4" @submit.prevent="guardar">
            <p class="text-sm text-slate-500">Mínimo 8 caracteres, con letras y números. No la compartas con nadie: todo lo que se haga con tu usuario queda a tu nombre.</p>
            <div class="flex flex-col gap-1">
                <label class="text-sm">Contraseña actual *</label>
                <Password v-model="form.password_actual" toggleMask :feedback="false" fluid :invalid="!!form.errors.password_actual" autocomplete="current-password" autofocus />
                <small class="text-red-600">{{ form.errors.password_actual }}</small>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm">Contraseña nueva *</label>
                <Password v-model="form.password" toggleMask fluid :invalid="!!form.errors.password" autocomplete="new-password"
                    promptLabel="Escribe la nueva contraseña" weakLabel="Débil" mediumLabel="Aceptable" strongLabel="Segura" />
                <small class="text-red-600">{{ form.errors.password }}</small>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm">Repetir contraseña nueva *</label>
                <Password v-model="form.password_confirmation" toggleMask :feedback="false" fluid autocomplete="new-password" />
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <Link href="/"><Button label="Cancelar" severity="secondary" text /></Link>
                <Button type="submit" label="Cambiar contraseña" icon="pi pi-key" :loading="form.processing" />
            </div>
        </form>
    </AppLayout>
</template>