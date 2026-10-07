<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Toast from 'primevue/toast';
import ConfirmDialog from 'primevue/confirmdialog';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import { useToast } from 'primevue/usetoast';

defineProps({ titulo: String });

const page = usePage();
const toast = useToast();
const menuAbierto = ref(false);

const usuario = computed(() => page.props.auth.user);
const empresa = computed(() => page.props.empresa);

// Menú agrupado por áreas. "roles" indica quién ve cada opción (el admin ve todo).
// Un área se muestra solo si el usuario puede ver al menos una de sus opciones.
const areas = [
    {
        titulo: null,
        opciones: [{ texto: 'Inicio', icono: 'pi pi-home', url: '/', roles: ['vendedor', 'almacen', 'contador'] }],
    },
    {
        titulo: 'Ventas',
        opciones: [
            { texto: 'Nueva venta', icono: 'pi pi-shopping-cart', url: '/ventas/nueva', roles: ['vendedor'] },
            { texto: 'Cotizaciones', icono: 'pi pi-file-edit', url: '/cotizaciones', roles: ['vendedor', 'contador'] },
            { texto: 'Comprobantes', icono: 'pi pi-file', url: '/comprobantes', roles: ['vendedor', 'contador'] },
            { texto: 'Guías de remisión', icono: 'pi pi-map-marker', url: '/guias', roles: ['vendedor', 'almacen', 'contador'] },
            { texto: 'Caja', icono: 'pi pi-wallet', url: '/caja', roles: ['vendedor'] },
            { texto: 'Cobranzas', icono: 'pi pi-money-bill', url: '/cobranzas', roles: ['vendedor'] },
            { texto: 'Clientes', icono: 'pi pi-id-card', url: '/clientes', roles: ['vendedor'] },
        ],
    },
    {
        titulo: 'Inventario',
        opciones: [
            { texto: 'Productos', icono: 'pi pi-box', url: '/productos', roles: ['almacen'] },
            { texto: 'Importar productos', icono: 'pi pi-file-import', url: '/importar', roles: ['almacen'] },
            { texto: 'Stock', icono: 'pi pi-th-large', url: '/inventario', roles: ['almacen', 'contador'] },
            { texto: 'Kárdex', icono: 'pi pi-list', url: '/kardex', roles: ['almacen', 'contador'] },
            { texto: 'Vencimientos', icono: 'pi pi-calendar-times', url: '/vencimientos', roles: ['almacen', 'contador'] },
            { texto: 'Ajustes', icono: 'pi pi-sliders-h', url: '/ajustes', roles: ['almacen', 'contador'] },
            { texto: 'Toma de inventario', icono: 'pi pi-check-square', url: '/tomas', roles: ['almacen', 'contador'] },
        ],
    },
    {
        titulo: 'Compras',
        opciones: [
            { texto: 'Compras', icono: 'pi pi-truck', url: '/compras', roles: ['almacen', 'contador'] },
            { texto: 'Cuentas por pagar', icono: 'pi pi-credit-card', url: '/cuentas-por-pagar', roles: ['almacen', 'contador'] },
            { texto: 'Proveedores', icono: 'pi pi-building', url: '/proveedores', roles: ['almacen'] },
        ],
    },
    {
        titulo: 'Gestión',
        opciones: [
            { texto: 'Reportes contables', icono: 'pi pi-chart-bar', url: '/reportes', roles: ['contador'] },
            { texto: 'Rentabilidad', icono: 'pi pi-chart-line', url: '/rentabilidad', roles: ['contador'] },
            { texto: 'Usuarios', icono: 'pi pi-users', url: '/usuarios', roles: [] }, // solo admin
            { texto: 'Auditoría', icono: 'pi pi-eye', url: '/auditoria', roles: [] }, // solo admin
            { texto: 'Configuración', icono: 'pi pi-cog', url: '/configuracion', roles: [] }, // solo admin
        ],
    },
];

const permitido = (o) => usuario.value.rol === 'admin' || o.roles.includes(usuario.value.rol);
const menu = computed(() =>
    areas.map((a) => ({ ...a, opciones: a.opciones.filter(permitido) })).filter((a) => a.opciones.length > 0),
);

// "/compras" no debe marcar "/compras-..." ni al revés: se compara la ruta completa o un subnivel
const activo = (url) => (url === '/' ? page.url === '/' : page.url === url || page.url.startsWith(url + '/') || page.url.startsWith(url + '?'));

// Áreas desplegables: se recuerdan las que el usuario dejó abiertas (en esta computadora)
// y siempre se abre el área de la página actual.
const CLAVE_MENU = 'menu_areas_abiertas';
const leerGuardadas = () => {
    try {
        return JSON.parse(localStorage.getItem(CLAVE_MENU) ?? '[]');
    } catch {
        return [];
    }
};
const abiertas = ref(new Set([...leerGuardadas(), ...areas.filter((a) => a.titulo && a.opciones.some((o) => activo(o.url))).map((a) => a.titulo)]));
const estaAbierta = (area) => !area.titulo || abiertas.value.has(area.titulo);
const alternar = (titulo) => {
    const s = new Set(abiertas.value);
    s.has(titulo) ? s.delete(titulo) : s.add(titulo);
    abiertas.value = s;
    try {
        localStorage.setItem(CLAVE_MENU, JSON.stringify([...s]));
    } catch {
        // sin almacenamiento disponible: el menú funciona igual
    }
};
// Al navegar a otra área (por un enlace), esa área se abre sola
watch(
    () => page.url,
    () => {
        const actual = areas.find((a) => a.titulo && a.opciones.some((o) => activo(o.url)));
        if (actual && !abiertas.value.has(actual.titulo)) abiertas.value = new Set([...abiertas.value, actual.titulo]);
    },
);

// Muestra como notificación los mensajes que envía Laravel con ->with('success', ...)
watch(
    () => page.props.notificacion,
    (n) => {
        if (n?.exito) toast.add({ severity: 'success', summary: 'Listo', detail: n.exito, life: 4000 });
        if (n?.error) toast.add({ severity: 'error', summary: 'Atención', detail: n.error, life: 6000 });
    },
    { immediate: true, deep: true },
);

const salir = () => router.post('/logout');
</script>

<template>
    <Toast position="top-right" />
    <ConfirmDialog />

    <div class="min-h-screen flex">
        <!-- Menú lateral -->
        <aside
            class="no-print fixed inset-y-0 left-0 z-30 w-60 bg-slate-900 text-slate-200 flex flex-col transition-transform lg:translate-x-0"
            :class="menuAbierto ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="px-5 py-5 border-b border-slate-800">
                <div class="flex items-center gap-2">
                    <i class="pi pi-shield text-emerald-400 text-xl"></i>
                    <span class="font-semibold text-white">Droguería</span>
                </div>
                <p class="text-xs text-slate-400 mt-1 truncate">{{ empresa?.razon_social }}</p>
            </div>

            <nav class="flex-1 px-3 py-3 overflow-y-auto">
                <div v-for="(area, i) in menu" :key="area.titulo ?? 'inicio'" :class="i > 0 ? 'mt-2' : ''">
                    <button
                        v-if="area.titulo"
                        type="button"
                        class="w-full flex items-center gap-2 px-3 py-1.5 rounded-lg text-[11px] font-semibold uppercase tracking-wider transition-colors hover:bg-slate-800"
                        :class="area.opciones.some((o) => activo(o.url)) ? 'text-emerald-400' : 'text-slate-400'"
                        @click="alternar(area.titulo)"
                    >
                        {{ area.titulo }}
                        <i class="pi ml-auto text-[10px]" :class="estaAbierta(area) ? 'pi-chevron-down' : 'pi-chevron-right'"></i>
                    </button>
                    <div v-show="estaAbierta(area)" class="space-y-0.5 mt-0.5">
                        <Link
                            v-for="op in area.opciones"
                            :key="op.url"
                            :href="op.url"
                            class="flex items-center gap-3 px-3 py-1.5 rounded-lg text-sm transition-colors"
                            :class="activo(op.url) ? 'bg-emerald-600 text-white' : 'hover:bg-slate-800'"
                            @click="menuAbierto = false"
                        >
                            <i :class="op.icono" class="w-4 text-center"></i>
                            {{ op.texto }}
                        </Link>
                    </div>
                </div>
            </nav>

            <div class="px-5 py-4 border-t border-slate-800 text-xs">
                <p class="text-white font-medium">{{ usuario.name }}</p>
                <p class="text-slate-400 capitalize">{{ usuario.rol }} · {{ usuario.sucursal }}</p>
            </div>
        </aside>

        <!-- Fondo oscuro al abrir el menú en celulares -->
        <div v-if="menuAbierto" class="fixed inset-0 z-20 bg-black/40 lg:hidden" @click="menuAbierto = false"></div>

        <!-- Contenido -->
        <div class="flex-1 lg:ml-60 print:ml-0 min-w-0 overflow-x-hidden">
            <header class="no-print sticky top-0 z-10 bg-white border-b border-slate-200 px-4 lg:px-6 h-14 flex items-center gap-3">
                <Button icon="pi pi-bars" text rounded class="lg:hidden" @click="menuAbierto = true" aria-label="Menú" />
                <h1 class="text-lg font-semibold truncate">{{ titulo }}</h1>
                <div class="ml-auto flex items-center gap-2">
                    <Tag
                        v-if="empresa"
                        :value="empresa.entorno === 'produccion' ? 'SUNAT producción' : 'SUNAT pruebas (beta)'"
                        :severity="empresa.entorno === 'produccion' ? 'success' : 'warn'"
                    />
                    <Link href="/perfil/password">
                        <Button icon="pi pi-key" text rounded severity="secondary" v-tooltip.bottom="'Cambiar mi contraseña'" />
                    </Link>
                    <Button icon="pi pi-sign-out" text rounded severity="secondary" v-tooltip.bottom="'Cerrar sesión'" @click="salir" />
                </div>
            </header>

            <main class="p-4 lg:p-6 print:p-0">
                <slot />
            </main>
        </div>
    </div>
</template>