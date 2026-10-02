<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import { soles, precio, fecha, stock as textoStock } from '@/utils/formato';

const props = defineProps({
    ajuste: Object,
    empresa: Object,
    puedeRegistrar: Boolean,
});

const esSalida = props.ajuste.tipo === 'salida';
const imprimir = () => window.print();
</script>

<template>
    <Head :title="`Ajuste ${ajuste.numero}`" />
    <AppLayout :titulo="`Ajuste ${ajuste.numero}`">
        <div class="no-print flex flex-wrap items-center gap-2 mb-4">
            <Link href="/ajustes"><Button label="Volver" icon="pi pi-arrow-left" text /></Link>
            <div class="ml-auto flex gap-2">
                <Link v-if="puedeRegistrar" href="/ajustes/nuevo">
                    <Button label="Nuevo ajuste" icon="pi pi-plus" severity="secondary" outlined />
                </Link>
                <Button label="Imprimir acta" icon="pi pi-print" severity="help" @click="imprimir" />
            </div>
        </div>

        <!-- ACTA (es lo que se imprime) -->
        <article class="bg-white rounded-xl border border-slate-200 p-6 lg:p-8 max-w-5xl mx-auto print:border-0 print:p-0">
            <header class="flex flex-wrap justify-between gap-4 border-b border-slate-200 pb-4 mb-4">
                <div>
                    <p class="font-semibold">{{ empresa?.razon_social }}</p>
                    <p class="text-sm text-slate-600">RUC {{ empresa?.ruc }}</p>
                    <p class="text-xs text-slate-500">{{ empresa?.direccion }}</p>
                </div>
                <div class="text-right">
                    <p class="text-lg font-bold">ACTA DE AJUSTE DE INVENTARIO</p>
                    <p class="text-xl font-semibold">{{ ajuste.numero }}</p>
                    <Tag :value="esSalida ? 'SALIDA' : 'ENTRADA'" :severity="esSalida ? 'danger' : 'success'" class="mt-1" />
                </div>
            </header>

            <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 text-sm mb-4">
                <div>
                    <p class="text-xs text-slate-500">Fecha y hora</p>
                    <p>{{ fecha(ajuste.created_at) }} {{ ajuste.created_at.substring(11, 16) }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Local</p>
                    <p>{{ ajuste.sucursal?.nombre }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Motivo</p>
                    <p class="font-medium">{{ ajuste.motivo_nombre }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Registrado por</p>
                    <p>{{ ajuste.usuario?.name }}</p>
                </div>
                <div class="col-span-2 lg:col-span-4">
                    <p class="text-xs text-slate-500">Detalle de lo ocurrido</p>
                    <p class="whitespace-pre-line">{{ ajuste.observacion }}</p>
                </div>
            </section>

            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-600 text-xs uppercase print:bg-transparent">
                    <tr class="border-y border-slate-200">
                        <th class="text-left p-2">Código</th>
                        <th class="text-left p-2">Producto</th>
                        <th class="text-left p-2">Lote</th>
                        <th class="text-left p-2">Vence</th>
                        <th class="text-right p-2">Cantidad</th>
                        <th class="text-right p-2">Costo unit.</th>
                        <th class="text-right p-2">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="it in ajuste.items" :key="it.id" class="border-b border-slate-100">
                        <td class="p-2 whitespace-nowrap">{{ it.producto.codigo }}</td>
                        <td class="p-2">
                            {{ [it.producto.nombre, it.producto.concentracion, it.producto.presentacion].filter(Boolean).join(' ') }}
                        </td>
                        <td class="p-2 whitespace-nowrap">{{ it.numero_lote }}</td>
                        <td class="p-2 whitespace-nowrap">{{ fecha(it.fecha_vencimiento) }}</td>
                        <td class="p-2 text-right whitespace-nowrap font-medium">{{ textoStock(it.cantidad, it.producto) }}</td>
                        <td class="p-2 text-right whitespace-nowrap">{{ precio(it.costo_unitario) }}</td>
                        <td class="p-2 text-right whitespace-nowrap">{{ soles(it.valor) }}</td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="font-semibold">
                        <td colspan="6" class="p-2 text-right">Valor total {{ esSalida ? 'dado de baja' : 'ingresado' }} (a costo, sin IGV)</td>
                        <td class="p-2 text-right whitespace-nowrap" :class="esSalida ? 'text-red-600' : 'text-emerald-700'">{{ soles(ajuste.valor) }}</td>
                    </tr>
                </tfoot>
            </table>

            <!-- Firmas -->
            <section class="grid grid-cols-3 gap-8 mt-16 text-center text-xs">
                <div>
                    <div class="border-t border-slate-400 pt-2">Responsable de almacén</div>
                </div>
                <div>
                    <div class="border-t border-slate-400 pt-2">Químico farmacéutico / Director técnico</div>
                </div>
                <div>
                    <div class="border-t border-slate-400 pt-2">Administración</div>
                </div>
            </section>
        </article>
    </AppLayout>
</template>