<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import Tag from 'primevue/tag';

const props = defineProps({
    soporte: Object,
    sistema: Object,
});

// Mensaje de WhatsApp ya escrito con los datos que necesita soporte
const mensajeWhatsapp = computed(
    () =>
        `Hola Perú Labs, necesito ayuda con el sistema de ${props.sistema.empresa ?? 'la droguería'}.\n` +
        `Usuario: ${props.sistema.usuario} (${props.sistema.rol})\n` +
        `Problema: `,
);
const enlaceWhatsapp = computed(() => `https://wa.me/${props.soporte.whatsapp}?text=${encodeURIComponent(mensajeWhatsapp.value)}`);
const enlaceCorreo = computed(
    () =>
        `mailto:${props.soporte.correo}?subject=${encodeURIComponent('Soporte - ' + (props.sistema.empresa ?? 'Droguería'))}` +
        `&body=${encodeURIComponent(datosTexto.value + '\n\nProblema: ')}`,
);

// Datos técnicos para copiar y pegar al reportar un problema
const datosTexto = computed(() =>
    [
        `Empresa: ${props.sistema.empresa} (RUC ${props.sistema.ruc})`,
        `Usuario: ${props.sistema.usuario} - ${props.sistema.correo} (${props.sistema.rol})`,
        `Sucursal: ${props.sistema.sucursal ?? '-'}`,
        `Versión: ${props.sistema.version} · SUNAT: ${props.sistema.entorno}`,
        `Fecha: ${new Date().toLocaleString('es-PE')}`,
    ].join('\n'),
);
const copiado = ref(false);
const copiarDatos = async () => {
    try {
        await navigator.clipboard.writeText(datosTexto.value);
        copiado.value = true;
        setTimeout(() => (copiado.value = false), 2500);
    } catch {
        copiado.value = false;
    }
};
</script>

<template>
    <Head title="Ayuda y soporte" />
    <AppLayout titulo="Ayuda y soporte">
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
            <!-- Contacto -->
            <section class="xl:col-span-2 bg-white rounded-xl border border-slate-200 p-6">
                <div class="flex flex-wrap items-center gap-6 mb-6">
                    <img src="/images/perulabs.png" :alt="soporte.empresa" class="h-24 object-contain" />
                    <div>
                        <h2 class="text-xl font-semibold">¿Necesitas ayuda?</h2>
                        <p class="text-slate-600">El equipo de <b>{{ soporte.empresa }}</b>, que desarrolló este sistema, te atiende.</p>
                        <p class="text-sm text-slate-500 mt-1"><i class="pi pi-clock mr-1"></i>Horario de atención: {{ soporte.horario }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <a :href="enlaceWhatsapp" target="_blank" rel="noopener" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 hover:bg-emerald-100 transition-colors">
                        <i class="pi pi-whatsapp text-2xl text-emerald-600"></i>
                        <p class="font-semibold text-emerald-800 mt-2">WhatsApp</p>
                        <p class="text-sm text-emerald-700">{{ soporte.telefono }}</p>
                        <p class="text-xs text-emerald-600 mt-1">La forma más rápida</p>
                    </a>
                    <a :href="`tel:+${soporte.whatsapp}`" class="rounded-xl border border-sky-200 bg-sky-50 p-4 hover:bg-sky-100 transition-colors">
                        <i class="pi pi-phone text-2xl text-sky-600"></i>
                        <p class="font-semibold text-sky-800 mt-2">Llamar</p>
                        <p class="text-sm text-sky-700">{{ soporte.telefono }}</p>
                        <p class="text-xs text-sky-600 mt-1">Para urgencias</p>
                    </a>
                    <a :href="enlaceCorreo" class="rounded-xl border border-violet-200 bg-violet-50 p-4 hover:bg-violet-100 transition-colors">
                        <i class="pi pi-envelope text-2xl text-violet-600"></i>
                        <p class="font-semibold text-violet-800 mt-2">Correo</p>
                        <p class="text-sm text-violet-700 break-all">{{ soporte.correo }}</p>
                        <p class="text-xs text-violet-600 mt-1">Consultas y solicitudes</p>
                    </a>
                </div>

                <!-- Guía para reportar -->
                <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm">
                    <h3 class="font-semibold text-amber-900 mb-2"><i class="pi pi-lightbulb mr-1"></i>Para resolver tu problema más rápido, cuéntanos:</h3>
                    <ol class="list-decimal pl-5 space-y-1 text-amber-900">
                        <li><b>Qué estabas haciendo</b> (por ejemplo: "emitiendo una factura a tal cliente").</li>
                        <li><b>Qué pasó</b> y el mensaje de error exacto, si salió alguno.</li>
                        <li>Una <b>captura de pantalla</b> (tecla <kbd class="px-1 rounded bg-white border">Impr Pant</kbd> o <kbd class="px-1 rounded bg-white border">Win + Shift + S</kbd>).</li>
                        <li>Los <b>datos del sistema</b> de la derecha: cópialos con un clic.</li>
                    </ol>
                </div>
            </section>

            <!-- Datos del sistema y del desarrollador -->
            <div class="space-y-6">
                <section class="bg-white rounded-xl border border-slate-200 p-5 text-sm">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="font-semibold">Datos del sistema</h2>
                        <Tag :value="sistema.entorno" :severity="sistema.entorno === 'Producción' ? 'success' : 'warn'" />
                    </div>
                    <dl class="space-y-1.5">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Empresa</dt><dd class="text-right font-medium">{{ sistema.empresa }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">RUC</dt><dd>{{ sistema.ruc }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Usuario</dt><dd class="text-right">{{ sistema.usuario }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Rol</dt><dd class="capitalize">{{ sistema.rol }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Sucursal</dt><dd class="text-right">{{ sistema.sucursal ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Versión</dt><dd>{{ sistema.version }}</dd></div>
                    </dl>
                    <Button
                        :label="copiado ? '¡Copiado!' : 'Copiar datos'"
                        :icon="copiado ? 'pi pi-check' : 'pi pi-copy'"
                        :severity="copiado ? 'success' : 'secondary'"
                        outlined
                        size="small"
                        class="mt-4 w-full"
                        @click="copiarDatos"
                    />
                </section>

                <section class="bg-white rounded-xl border border-slate-200 p-5 text-sm">
                    <h2 class="font-semibold mb-3">Desarrollado por</h2>
                    <p class="font-medium">{{ soporte.razon_social }}</p>
                    <p class="text-slate-500">RUC {{ soporte.ruc }}</p>
                    <p class="text-slate-500">{{ soporte.direccion }}</p>
                    <a :href="soporte.web" target="_blank" rel="noopener" class="inline-flex items-center gap-1 mt-3 text-emerald-700 hover:underline">
                        <i class="pi pi-globe"></i>{{ soporte.web.replace('https://', '') }}
                    </a>
                    <p class="text-xs text-slate-400 mt-3 italic">{{ soporte.lema }}</p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>