<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Checkbox from 'primevue/checkbox';
import Button from 'primevue/button';
import Message from 'primevue/message';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const ingresar = () => {
    form.post('/login', { onFinish: () => form.reset('password') });
};
</script>

<template>
    <Head title="Iniciar sesión" />

    <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-emerald-50 to-slate-100 p-4">
        <div class="w-full max-w-sm bg-white rounded-2xl shadow-lg p-8">
            <div class="text-center mb-6">
                <i class="pi pi-shield text-4xl text-emerald-500"></i>
                <h1 class="text-2xl font-semibold mt-2">Droguería</h1>
                <p class="text-slate-500 text-sm">Ingresa con tu cuenta</p>
            </div>

            <form class="space-y-4" @submit.prevent="ingresar">
                <div class="flex flex-col gap-1">
                    <label for="email" class="text-sm font-medium">Correo</label>
                    <InputText id="email" v-model="form.email" type="email" autocomplete="username" autofocus :invalid="!!form.errors.email" fluid />
                    <small v-if="form.errors.email" class="text-red-600">{{ form.errors.email }}</small>
                </div>

                <div class="flex flex-col gap-1">
                    <label for="password" class="text-sm font-medium">Contraseña</label>
                    <Password inputId="password" v-model="form.password" :feedback="false" toggleMask autocomplete="current-password" fluid />
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox inputId="remember" v-model="form.remember" binary />
                    <label for="remember" class="text-sm">Recordarme</label>
                </div>

                <Button type="submit" label="Ingresar" icon="pi pi-sign-in" :loading="form.processing" fluid />
            </form>

            <Message severity="info" class="mt-6" size="small">
                Prueba: admin@drogueria.test / password
            </Message>
        </div>
    </div>
</template>