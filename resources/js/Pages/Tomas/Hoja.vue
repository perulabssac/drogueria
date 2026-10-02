<script setup>
import { onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { fecha } from '@/utils/formato';

defineProps({
    toma: Object,
    items: Array,
    empresa: Object,
});

const imprimir = () => window.print();
onMounted(() => setTimeout(imprimir, 400));
</script>

<template>
    <Head :title="`Hoja de conteo ${toma.numero}`" />

    <div class="no-print bg-slate-800 text-white px-4 py-2 flex items-center gap-3 text-sm">
        <span>Hoja para contar a mano. El stock del sistema no aparece (conteo a ciegas).</span>
        <button type="button" class="ml-auto bg-purple-600 hover:bg-purple-700 rounded px-3 py-1" @click="imprimir">
            <i class="pi pi-print mr-1"></i> Imprimir
        </button>
    </div>

    <div class="p-6 text-[12px] text-black bg-white">
        <header class="flex justify-between mb-3">
            <div>
                <p class="font-semibold text-sm">{{ empresa?.razon_social }}</p>
                <p>RUC {{ empresa?.ruc }}</p>
            </div>
            <div class="text-right">
                <p class="font-bold text-base">HOJA DE CONTEO DE INVENTARIO</p>
                <p class="font-semibold">{{ toma.numero }} · {{ toma.alcance_texto }}</p>
                <p>Abierta el {{ fecha(toma.created_at) }}</p>
            </div>
        </header>

        <p class="mb-2">
            Anota la cantidad que hay físicamente en el estante. Para productos que se venden por unidad suelta, anota cajas completas
            y unidades sueltas por separado. Si un lote no está, escribe <b>0</b>. Si encuentras un lote que no está en la lista, anótalo al final.
        </p>

        <table class="w-full border-collapse">
            <thead>
                <tr class="bg-slate-100">
                    <th class="border border-slate-400 p-1 w-8">N°</th>
                    <th class="border border-slate-400 p-1 text-left">Código</th>
                    <th class="border border-slate-400 p-1 text-left">Producto</th>
                    <th class="border border-slate-400 p-1 text-left">Lote</th>
                    <th class="border border-slate-400 p-1">Vence</th>
                    <th class="border border-slate-400 p-1 w-24">Cantidad</th>
                    <th class="border border-slate-400 p-1 w-24">Sueltas</th>
                    <th class="border border-slate-400 p-1 w-32">Observación</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(it, i) in items" :key="it.id" class="break-inside-avoid">
                    <td class="border border-slate-400 p-1 text-center">{{ i + 1 }}</td>
                    <td class="border border-slate-400 p-1">{{ it.producto.codigo }}</td>
                    <td class="border border-slate-400 p-1">{{ it.producto.descripcion }}</td>
                    <td class="border border-slate-400 p-1">{{ it.numero_lote }}</td>
                    <td class="border border-slate-400 p-1 text-center whitespace-nowrap">{{ fecha(it.fecha_vencimiento) }}</td>
                    <td class="border border-slate-400 p-1 text-right text-slate-500">{{ it.producto.unidad_venta }}</td>
                    <td class="border border-slate-400 p-1 text-right text-slate-500">{{ it.producto.fraccionable ? it.producto.unidad_fraccion : '' }}</td>
                    <td class="border border-slate-400 p-1"></td>
                </tr>
                <!-- Filas en blanco para lotes encontrados -->
                <tr v-for="n in 5" :key="`extra-${n}`">
                    <td class="border border-slate-400 p-1 h-7 text-center text-slate-400">+</td>
                    <td v-for="c in 7" :key="c" class="border border-slate-400 p-1"></td>
                </tr>
            </tbody>
        </table>

        <section class="grid grid-cols-3 gap-8 mt-14 text-center">
            <div class="border-t border-slate-500 pt-1">Contó (nombre y firma)</div>
            <div class="border-t border-slate-500 pt-1">Verificó (nombre y firma)</div>
            <div class="border-t border-slate-500 pt-1">Fecha y hora del conteo</div>
        </section>
    </div>
</template>