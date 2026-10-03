<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Message from 'primevue/message';
import Menu from 'primevue/menu';
import { fecha, cantidad } from '@/utils/formato';

const props = defineProps({
    guia: Object,
    motivos: Object,
    modalidades: Object,
    estados: Object,
    puedeEnviar: Boolean,
});

const g = computed(() => props.guia);
const SEVERIDAD = { pendiente: 'secondary', enviado: 'info', aceptado: 'success', rechazado: 'danger', error: 'warn' };
const MENSAJE = { pendiente: 'secondary', enviado: 'info', aceptado: 'success', rechazado: 'error', error: 'warn' };
const pendiente = computed(() => ['pendiente', 'enviado', 'error'].includes(g.value.estado));

const procesando = ref(false);
const enviar = () =>
    router.post(`/guias/${g.value.id}/enviar`, {}, {
        preserveScroll: true,
        onStart: () => (procesando.value = true),
        onFinish: () => (procesando.value = false),
    });

const menuDescargas = ref();
const descargas = computed(() => [
    { label: 'XML (guía electrónica)', icon: 'pi pi-file', url: `/guias/${g.value.id}/xml`, visible: !!g.value.xml_path },
    { label: 'CDR (constancia de SUNAT)', icon: 'pi pi-verified', url: `/guias/${g.value.id}/cdr`, visible: !!g.value.cdr_path },
]);
const hayDescargas = computed(() => descargas.value.some((d) => d.visible));
</script>

<template>
    <Head :title="`Guía ${g.numero}`" />
    <AppLayout :titulo="`Guía de remisión ${g.numero}`">
        <!-- Barra de acciones -->
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <Link href="/guias"><Button label="Volver" icon="pi pi-arrow-left" text /></Link>
            <Tag :value="estados[g.estado]" :severity="SEVERIDAD[g.estado]" class="text-sm" />
            <div class="ml-auto flex flex-wrap gap-2">
                <Button
                    v-if="pendiente && puedeEnviar"
                    :label="g.ticket ? 'Consultar respuesta' : 'Reenviar a SUNAT'"
                    :icon="g.ticket ? 'pi pi-sync' : 'pi pi-refresh'"
                    :loading="procesando"
                    @click="enviar"
                />
                <template v-if="hayDescargas">
                    <Button severity="help" @click="(e) => menuDescargas.toggle(e)">
                        <i class="pi pi-download"></i>
                        <span>Descargar</span>
                        <i class="pi pi-angle-down text-xs"></i>
                    </Button>
                    <Menu ref="menuDescargas" :model="descargas" popup />
                </template>
                <a v-if="g.estado !== 'rechazado'" :href="`/guias/${g.id}/imprimir`" target="_blank">
                    <Button label="Imprimir" icon="pi pi-print" severity="info" />
                </a>
                <Link v-if="g.comprobante" :href="`/comprobantes/${g.comprobante.id}`">
                    <Button :label="`Ver ${g.comprobante.numero}`" icon="pi pi-file" severity="secondary" outlined />
                </Link>
            </div>
        </div>

        <!-- Respuesta de SUNAT -->
        <Message v-if="g.sunat_descripcion || g.estado === 'enviado'" :severity="MENSAJE[g.estado]" class="mb-4">
            <p>
                <span v-if="g.sunat_codigo" class="font-semibold">Código {{ g.sunat_codigo }}: </span>
                {{ g.sunat_descripcion || 'SUNAT está procesando la guía. Pulsa "Consultar respuesta" en unos segundos.' }}
            </p>
            <p v-if="g.ticket" class="text-xs mt-1">Ticket {{ g.ticket }}</p>
            <p v-if="g.estado === 'rechazado'" class="text-sm mt-2 font-medium">
                Esta guía no tiene validez. Corrige el dato indicado y emite una guía nueva.
            </p>
            <p v-if="g.estado === 'error'" class="text-sm mt-2">
                No se pudo completar el envío (conexión o datos). Revisa el mensaje y pulsa "Reenviar a SUNAT".
            </p>
        </Message>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <section class="bg-white rounded-xl border border-slate-200 p-5 text-sm space-y-3">
                <h2 class="font-semibold">Traslado</h2>
                <div><p class="text-xs text-slate-500">Destinatario</p><p class="font-medium">{{ g.destinatario_nombre }}</p><p class="text-slate-500">{{ g.destinatario_num_doc }}</p></div>
                <div><p class="text-xs text-slate-500">Motivo</p><p>{{ motivos[g.motivo] ?? g.motivo }}<span v-if="g.motivo_descripcion"> · {{ g.motivo_descripcion }}</span></p></div>
                <div class="grid grid-cols-2 gap-2">
                    <div><p class="text-xs text-slate-500">Emisión</p><p>{{ fecha(g.fecha_emision) }}</p></div>
                    <div><p class="text-xs text-slate-500">Inicio de traslado</p><p>{{ fecha(g.fecha_traslado) }}</p></div>
                </div>
                <div><p class="text-xs text-slate-500">Peso bruto total</p><p>{{ cantidad(g.peso_total) }} {{ g.unidad_peso }}</p></div>
            </section>

            <section class="bg-white rounded-xl border border-slate-200 p-5 text-sm space-y-3">
                <h2 class="font-semibold">Recorrido</h2>
                <div><p class="text-xs text-slate-500">Punto de partida</p><p>{{ g.partida_direccion }}</p><p class="text-xs text-slate-500">Ubigeo {{ g.partida_ubigeo }}</p></div>
                <div><p class="text-xs text-slate-500">Punto de llegada</p><p>{{ g.llegada_direccion }}</p><p class="text-xs text-slate-500">Ubigeo {{ g.llegada_ubigeo }}</p></div>
            </section>

            <section class="bg-white rounded-xl border border-slate-200 p-5 text-sm space-y-3">
                <h2 class="font-semibold">Transporte</h2>
                <p class="text-slate-600">{{ modalidades[g.modalidad] }}</p>
                <template v-if="g.modalidad === '02'">
                    <div><p class="text-xs text-slate-500">Vehículo</p><p class="font-medium">{{ g.vehiculo_placa }}</p></div>
                    <div>
                        <p class="text-xs text-slate-500">Conductor</p>
                        <p>{{ g.conductor_nombres }} {{ g.conductor_apellidos }}</p>
                        <p class="text-xs text-slate-500">Doc. {{ g.conductor_num_doc }} · Licencia {{ g.conductor_licencia }}</p>
                    </div>
                </template>
                <div v-else>
                    <p class="text-xs text-slate-500">Empresa de transportes</p>
                    <p class="font-medium">{{ g.transportista_nombre }}</p>
                    <p class="text-xs text-slate-500">RUC {{ g.transportista_ruc }}<span v-if="g.transportista_mtc"> · MTC {{ g.transportista_mtc }}</span></p>
                </div>
            </section>
        </div>

        <section class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-600 text-xs uppercase">
                    <tr>
                        <th class="text-left p-3">Código</th>
                        <th class="text-left p-3">Descripción</th>
                        <th class="text-left p-3">Unidad</th>
                        <th class="text-right p-3">Cantidad</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="it in g.items" :key="it.id" class="border-t border-slate-100">
                        <td class="p-3 whitespace-nowrap">{{ it.codigo }}</td>
                        <td class="p-3">{{ it.descripcion }}</td>
                        <td class="p-3">{{ it.unidad }}</td>
                        <td class="p-3 text-right">{{ cantidad(it.cantidad) }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-if="g.observaciones" class="p-4 text-sm text-slate-600 border-t border-slate-100">{{ g.observaciones }}</p>
        </section>
    </AppLayout>
</template>