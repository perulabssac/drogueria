<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Tag from 'primevue/tag';
import { cantidad, diasHasta, fecha, soles, stock } from '@/utils/formato';

const props = defineProps({
    secciones: Object,
    ventas: Object,
    ventasPorDia: Array,
    ingresosPorMedio: Array,
    topProductos: Array,
    topClientes: Array,
    utilidad: Object,
    porCobrar: Object,
    porPagar: Object,
    sunat: Object,
    cotizaciones: Object,
    inventario: Object,
    stockBajo: Array,
    porVencer: Array,
});

const usuario = usePage().props.auth.user;
const s = props.secciones;
// Cobranzas es del área de ventas: el contador ve el monto pero no entra al módulo
const puedeCobranzas = ['admin', 'vendedor'].includes(usuario.rol);

const saludo = computed(() => {
    const h = new Date().getHours();
    return h < 12 ? 'Buenos días' : h < 19 ? 'Buenas tardes' : 'Buenas noches';
});
const hoyTexto = new Date().toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

// ================= ALERTAS (lo que pide acción hoy) =================
const alertas = computed(() => {
    const a = [];
    if (props.sunat?.rechazados) a.push({ texto: `${props.sunat.rechazados} comprobante(s) rechazado(s) por SUNAT`, url: '/comprobantes', color: 'red', icono: 'pi pi-times-circle' });
    if (props.sunat?.pendientes) a.push({ texto: `${props.sunat.pendientes} comprobante(s) sin respuesta de SUNAT`, url: '/comprobantes', color: 'amber', icono: 'pi pi-clock' });
    if (props.inventario?.lotes_vencidos) a.push({ texto: `${props.inventario.lotes_vencidos} lote(s) vencido(s) con stock: dar de baja`, url: '/vencimientos?vista=vencidos', color: 'red', icono: 'pi pi-calendar-times' });
    if (props.inventario?.stock_bajo) a.push({ texto: `${props.inventario.stock_bajo} producto(s) con stock bajo`, url: '/inventario?vista=bajo_minimo', color: 'amber', icono: 'pi pi-arrow-down' });
    if (props.porPagar?.vencido > 0) a.push({ texto: `${soles(props.porPagar.vencido)} vencido con proveedores`, url: '/cuentas-por-pagar?vista=vencidas', color: 'red', icono: 'pi pi-credit-card' });
    if (props.porPagar?.esta_semana > 0) a.push({ texto: `${soles(props.porPagar.esta_semana)} por pagar a proveedores esta semana`, url: '/cuentas-por-pagar?vista=por_vencer', color: 'amber', icono: 'pi pi-credit-card' });
    if (props.porCobrar?.vencido > 0 && puedeCobranzas) a.push({ texto: `${soles(props.porCobrar.vencido)} vencido por cobrar a clientes`, url: '/cobranzas?estado=vencidas', color: 'red', icono: 'pi pi-money-bill' });
    if (props.cotizaciones?.vencen_pronto) a.push({ texto: `${props.cotizaciones.vencen_pronto} cotización(es) vencen en 2 días`, url: '/cotizaciones?estado=pendiente', color: 'sky', icono: 'pi pi-file-edit' });
    return a;
});
const COLOR_ALERTA = {
    red: 'border-red-200 bg-red-50 text-red-800 hover:bg-red-100',
    amber: 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100',
    sky: 'border-sky-200 bg-sky-50 text-sky-800 hover:bg-sky-100',
};

// ================= GRÁFICO DE VENTAS (30 días) =================
const maximo = computed(() => Math.max(1, ...(props.ventasPorDia ?? []).map((d) => d.total)));
const promedio = computed(() => {
    const dias = props.ventasPorDia ?? [];
    return dias.length ? dias.reduce((t, d) => t + d.total, 0) / dias.length : 0;
});
const diaSeleccionado = ref(null);
const etiquetaDia = (d) => {
    const [, m, dd] = d.dia.split('-');
    return `${dd}/${m}`;
};
const hoyIso = (() => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})();
const esHoy = (d) => d.dia === hoyIso;

// ================= MEDIOS DE PAGO =================
const totalMedios = computed(() => (props.ingresosPorMedio ?? []).reduce((t, m) => t + m.total, 0));
const COLORES_MEDIO = ['#10b981', '#8b5cf6', '#0ea5e9', '#f59e0b', '#ec4899', '#64748b', '#14b8a6'];

const variacionTexto = computed(() => {
    const v = props.ventas?.variacion;
    if (v === null || v === undefined) return 'Sin datos del mes anterior';
    return `${v >= 0 ? '▲' : '▼'} ${Math.abs(v)}% vs. mismo periodo del mes anterior`;
});

const textoVence = (f) => {
    const dias = diasHasta(f);
    return dias < 0 ? `Vencido hace ${-dias} d` : `${dias} d`;
};
</script>

<template>
    <Head title="Inicio" />
    <AppLayout titulo="Inicio">
        <div class="flex flex-wrap items-end justify-between gap-2 mb-5">
            <div>
                <p class="text-xl font-semibold">{{ saludo }}, {{ usuario.name.split(' ')[0] }}</p>
                <p class="text-sm text-slate-500 capitalize">{{ hoyTexto }}</p>
            </div>
            <Link v-if="s.ventas && usuario.rol !== 'contador'" href="/ventas/nueva">
                <span class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-4 py-2 text-sm font-medium">
                    <i class="pi pi-shopping-cart"></i> Nueva venta
                </span>
            </Link>
        </div>

        <!-- Alertas -->
        <div v-if="alertas.length" class="grid sm:grid-cols-2 xl:grid-cols-3 gap-3 mb-6">
            <Link v-for="(a, i) in alertas" :key="i" :href="a.url" class="flex items-center gap-3 rounded-lg border px-4 py-3 text-sm transition-colors" :class="COLOR_ALERTA[a.color]">
                <i :class="a.icono"></i>
                <span class="flex-1">{{ a.texto }}</span>
                <i class="pi pi-angle-right"></i>
            </Link>
        </div>
        <div v-else class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm mb-6">
            <i class="pi pi-check-circle mr-2"></i>Todo en orden: no hay alertas pendientes.
        </div>

        <!-- Indicadores principales -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <template v-if="s.ventas">
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <p class="text-xs font-medium text-emerald-700">Ventas de hoy</p>
                    <p class="text-2xl font-semibold text-emerald-900">{{ soles(ventas.hoy) }}</p>
                    <p class="text-xs text-emerald-700">{{ ventas.documentos_hoy }} comprobante(s)</p>
                </div>
                <div class="rounded-xl border border-sky-200 bg-sky-50 p-4">
                    <p class="text-xs font-medium text-sky-700">Ventas del mes</p>
                    <p class="text-2xl font-semibold text-sky-900">{{ soles(ventas.mes) }}</p>
                    <p class="text-xs" :class="ventas.variacion === null ? 'text-sky-700' : ventas.variacion >= 0 ? 'text-emerald-700' : 'text-red-600'">{{ variacionTexto }}</p>
                </div>
            </template>
            <div v-if="s.utilidad" class="rounded-xl border border-violet-200 bg-violet-50 p-4">
                <p class="text-xs font-medium text-violet-700">Utilidad bruta del mes</p>
                <p class="text-2xl font-semibold text-violet-900">{{ soles(utilidad.utilidad) }}</p>
                <p class="text-xs text-violet-700">
                    <span v-if="utilidad.margen !== null">Margen {{ utilidad.margen }}%</span> · costo {{ soles(utilidad.costo) }}
                </p>
            </div>
            <component :is="puedeCobranzas ? Link : 'div'" v-if="s.cobrar" href="/cobranzas" class="rounded-xl border border-amber-200 bg-amber-50 p-4" :class="puedeCobranzas ? 'hover:shadow' : ''">
                <p class="text-xs font-medium text-amber-700">Por cobrar a clientes</p>
                <p class="text-2xl font-semibold text-amber-900">{{ soles(porCobrar.total) }}</p>
                <p class="text-xs" :class="porCobrar.vencido > 0 ? 'text-red-600 font-medium' : 'text-amber-700'">
                    {{ porCobrar.vencido > 0 ? `Vencido: ${soles(porCobrar.vencido)}` : `${porCobrar.clientes} cliente(s)` }}
                </p>
            </component>
            <Link v-if="s.pagar" href="/cuentas-por-pagar" class="rounded-xl border border-rose-200 bg-rose-50 p-4 hover:shadow">
                <p class="text-xs font-medium text-rose-700">Por pagar a proveedores</p>
                <p class="text-2xl font-semibold text-rose-900">{{ soles(porPagar.total) }}</p>
                <p class="text-xs" :class="porPagar.vencido > 0 ? 'text-red-600 font-medium' : 'text-rose-700'">
                    {{ porPagar.vencido > 0 ? `Vencido: ${soles(porPagar.vencido)}` : `Esta semana: ${soles(porPagar.esta_semana)}` }}
                </p>
            </Link>
            <Link v-if="s.cotizaciones" href="/cotizaciones?estado=pendiente" class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 hover:shadow">
                <p class="text-xs font-medium text-indigo-700">Cotizaciones pendientes</p>
                <p class="text-2xl font-semibold text-indigo-900">{{ cotizaciones.pendientes }}</p>
                <p class="text-xs text-indigo-700">Por {{ soles(cotizaciones.monto) }}</p>
            </Link>
            <template v-if="s.inventario">
                <Link href="/inventario?vista=bajo_minimo" class="rounded-xl border border-amber-200 bg-amber-50 p-4 hover:shadow">
                    <p class="text-xs font-medium text-amber-700">Con stock bajo</p>
                    <p class="text-2xl font-semibold text-amber-900">{{ inventario.stock_bajo }}</p>
                    <p class="text-xs text-amber-700">de {{ inventario.productos }} productos activos</p>
                </Link>
                <Link href="/vencimientos" class="rounded-xl border border-orange-200 bg-orange-50 p-4 hover:shadow">
                    <p class="text-xs font-medium text-orange-700">Lotes por vencer (90 días)</p>
                    <p class="text-2xl font-semibold text-orange-900">{{ inventario.lotes_por_vencer }}</p>
                    <p class="text-xs" :class="inventario.lotes_vencidos ? 'text-red-600 font-medium' : 'text-orange-700'">{{ inventario.lotes_vencidos }} vencido(s) con stock</p>
                </Link>
            </template>
        </div>

        <!-- Gráfico de ventas y medios de pago -->
        <div v-if="s.ventas" class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
            <section class="xl:col-span-2 bg-white rounded-xl border border-slate-200 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-semibold">Ventas de los últimos 30 días</h2>
                    <p class="text-sm text-slate-500">
                        <template v-if="diaSeleccionado">{{ fecha(diaSeleccionado.dia) }}: <b class="text-slate-800">{{ soles(diaSeleccionado.total) }}</b></template>
                        <template v-else>Promedio diario: <b class="text-slate-800">{{ soles(promedio) }}</b></template>
                    </p>
                </div>
                <div class="flex items-end gap-1 h-48 border-b border-slate-200" @mouseleave="diaSeleccionado = null">
                    <div
                        v-for="d in ventasPorDia"
                        :key="d.dia"
                        class="flex-1 h-full flex items-end cursor-pointer group"
                        @mouseenter="diaSeleccionado = d"
                    >
                        <div
                            class="w-full rounded-t transition-colors"
                            :style="{
                                height: Math.max(d.total > 0 ? 3 : 1, (d.total / maximo) * 100) + '%',
                                backgroundColor: diaSeleccionado?.dia === d.dia ? '#047857' : esHoy(d) ? '#0ea5e9' : d.total > 0 ? '#10b981' : '#e2e8f0',
                            }"
                        ></div>
                    </div>
                </div>
                <div class="flex justify-between text-xs text-slate-400 mt-1">
                    <span>{{ ventasPorDia.length ? etiquetaDia(ventasPorDia[0]) : '' }}</span>
                    <span>{{ ventasPorDia.length ? etiquetaDia(ventasPorDia[Math.floor(ventasPorDia.length / 2)]) : '' }}</span>
                    <span>Hoy</span>
                </div>
            </section>

            <section class="bg-white rounded-xl border border-slate-200 p-5">
                <h2 class="font-semibold mb-4">Ingresos del mes por medio de pago</h2>
                <p v-if="!ingresosPorMedio.length" class="text-sm text-slate-400">Aún no hay cobros este mes.</p>
                <div v-for="(m, i) in ingresosPorMedio" :key="m.medio" class="mb-3">
                    <div class="flex justify-between text-sm mb-1">
                        <span>{{ m.medio }}</span>
                        <span class="font-medium">{{ soles(m.total) }}</span>
                    </div>
                    <div class="rounded-full overflow-hidden" style="height: 8px; background-color: #f1f5f9">
                        <div :style="{ width: (m.total / totalMedios) * 100 + '%', height: '100%', backgroundColor: COLORES_MEDIO[i % COLORES_MEDIO.length] }"></div>
                    </div>
                </div>
                <p v-if="ingresosPorMedio.length" class="text-xs text-slate-500 border-t border-slate-100 pt-2 mt-2">Total cobrado: <b>{{ soles(totalMedios) }}</b></p>
            </section>
        </div>

        <!-- Rankings -->
        <div v-if="topProductos || topClientes" class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
            <section v-if="topProductos" class="bg-white rounded-xl border border-slate-200 overflow-x-auto" :class="topClientes ? 'xl:col-span-2' : 'xl:col-span-3'">
                <h2 class="font-semibold p-4 border-b border-slate-100">Productos más vendidos del mes</h2>
                <p v-if="!topProductos.length" class="p-4 text-sm text-slate-400">Aún no hay ventas este mes.</p>
                <table v-else class="w-full text-sm">
                    <tbody>
                        <tr v-for="(p, i) in topProductos" :key="p.id" class="border-t border-slate-100 first:border-0">
                            <td class="p-3 w-10 text-center">
                                <span class="inline-flex w-6 h-6 rounded-full items-center justify-center text-xs font-semibold" :class="i < 3 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">{{ i + 1 }}</span>
                            </td>
                            <td class="p-3">{{ p.producto }}</td>
                            <td class="p-3 text-right text-slate-500 whitespace-nowrap">{{ cantidad(p.cantidad) }} {{ p.unidad }}</td>
                            <td class="p-3 text-right font-medium whitespace-nowrap">{{ soles(p.importe) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="topClientes" class="bg-white rounded-xl border border-slate-200 self-start">
                <h2 class="font-semibold p-4 border-b border-slate-100">Mejores clientes del mes</h2>
                <p v-if="!topClientes.length" class="p-4 text-sm text-slate-400">Aún no hay ventas a clientes identificados.</p>
                <div v-for="c in topClientes" :key="c.id" class="px-4 py-3 border-t border-slate-100 first:border-0">
                    <p class="text-sm font-medium truncate">{{ c.cliente }}</p>
                    <div class="flex justify-between text-xs text-slate-500">
                        <span>{{ c.documento }} · {{ c.documentos }} compra(s)</span>
                        <span class="font-semibold text-slate-800">{{ soles(c.total) }}</span>
                    </div>
                </div>
            </section>
        </div>

        <!-- Inventario -->
        <div v-if="s.inventario" class="grid lg:grid-cols-2 gap-6">
            <section class="bg-white rounded-xl border border-slate-200">
                <div class="flex items-center justify-between p-4 border-b border-slate-100">
                    <h2 class="font-semibold"><i class="pi pi-clock text-orange-500 mr-2"></i>Próximos a vencer</h2>
                    <Link href="/vencimientos" class="text-sm text-emerald-700 hover:underline">Ver todos</Link>
                </div>
                <p v-if="!porVencer.length" class="p-4 text-sm text-slate-400">No hay lotes por vencer en los próximos 90 días.</p>
                <div v-for="l in porVencer" :key="l.id" class="flex items-center gap-3 px-4 py-2.5 border-t border-slate-100 first:border-0 text-sm">
                    <div class="flex-1 min-w-0">
                        <p class="truncate">{{ l.producto.nombre }} {{ l.producto.concentracion }}</p>
                        <p class="text-xs text-slate-500">Lote {{ l.numero_lote }} · {{ stock(l.cantidad, l.producto) }}</p>
                    </div>
                    <Tag :value="textoVence(l.fecha_vencimiento)" :severity="diasHasta(l.fecha_vencimiento) <= 30 ? 'danger' : diasHasta(l.fecha_vencimiento) <= 60 ? 'warn' : 'info'" />
                </div>
            </section>

            <section class="bg-white rounded-xl border border-slate-200">
                <div class="flex items-center justify-between p-4 border-b border-slate-100">
                    <h2 class="font-semibold"><i class="pi pi-arrow-down text-amber-500 mr-2"></i>Stock bajo el mínimo</h2>
                    <Link href="/compras/nueva" class="text-sm text-emerald-700 hover:underline">Registrar compra</Link>
                </div>
                <p v-if="!stockBajo.length" class="p-4 text-sm text-slate-400">Todos los productos están sobre su stock mínimo.</p>
                <div v-for="p in stockBajo" :key="p.id" class="flex items-center gap-3 px-4 py-2.5 border-t border-slate-100 first:border-0 text-sm">
                    <p class="flex-1 min-w-0 truncate">{{ p.nombre }} {{ p.concentracion }}</p>
                    <span class="text-red-600 font-medium whitespace-nowrap">{{ stock(p.stock, p) }}</span>
                    <span class="text-xs text-slate-500 whitespace-nowrap">mín. {{ p.stock_minimo }} {{ p.unidad_venta }}</span>
                </div>
            </section>
        </div>
    </AppLayout>
</template>