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

// Opciones del menú. "roles" indica quién puede verlas (el admin ve todo).
// Un grupo (con "hijos") se muestra si el usuario puede ver al menos una de sus opciones.
const opciones = [
    { texto: 'Inicio', icono: 'pi pi-home', url: '/', roles: ['vendedor', 'almacen', 'contador'] },
    { texto: 'Nueva venta', icono: 'pi pi-shopping-cart', url: '/ventas/nueva', roles: ['vendedor'] },
    { texto: 'Cotizaciones', icono: 'pi pi-file-edit', url: '/cotizaciones', roles: ['vendedor', 'contador'] },
    { texto: 'Comprobantes', icono: 'pi pi-file', url: '/comprobantes', roles: ['vendedor', 'contador'] },
    { texto: 'Guías de remisión', icono: 'pi pi-map-marker', url: '/guias', roles: ['vendedor', 'almacen', 'contador'] },
    { texto: 'Caja', icono: 'pi pi-wallet', url: '/caja', roles: ['vendedor'] },
    { texto: 'Cobranzas', icono: 'pi pi-money-bill', url: '/cobranzas', roles: ['vendedor'] },
    { texto: 'Clientes', icono: 'pi pi-id-card', url: '/clientes', roles: ['vendedor'] },
    { texto: 'Productos', icono: 'pi pi-box', url: '/productos', roles: ['almacen'] },
    {
        texto: 'Inventario',
        icono: 'pi pi-warehouse',
        hijos: [
            { texto: 'Stock', icono: 'pi pi-th-large', url: '/inventario', roles: ['almacen', 'contador'] },
            { texto: 'Kárdex', icono: 'pi pi-list', url: '/kardex', roles: ['almacen', 'contador'] },
            { texto: 'Vencimientos', icono: 'pi pi-calendar-times', url: '/vencimientos', roles: ['almacen', 'contador'] },
            { texto: 'Ajustes', icono: 'pi pi-sliders-h', url: '/ajustes', roles: ['almacen', 'contador'] },
            { texto: 'Toma de inventario', icono: 'pi pi-check-square', url: '/tomas', roles: ['almacen', 'contador'] },
        ],
    },
    { texto: 'Compras', icono: 'pi pi-truck', url: '/compras', roles: ['almacen', 'contador'] },
    { texto: 'Cuentas por pagar', icono: 'pi pi-credit-card', url: '/cuentas-por-pagar', roles: ['almacen', 'contador'] },
    { texto: 'Proveedores', icono: 'pi pi-building', url: '/proveedores', roles: ['almacen'] },
    { texto: 'Reportes contables', icono: 'pi pi-chart-bar', url: '/reportes', roles: ['contador'] },
    { texto: 'Usuarios', icono: 'pi pi-users', url: '/usuarios', roles: [] }, // solo admin
    { texto: 'Configuración', icono: 'pi pi-cog', url: '/configuracion', roles: [] }, // solo admin
];

const permitido = (o) => usuario.value.rol === 'admin' || o.roles.includes(usuario.value.rol);
const menu = computed(() =>
    opciones
        .map((o) => (o.hijos ? { ...o, hijos: o.hijos.filter(permitido) } : o))
        .filter((o) => (o.hijos ? o.hijos.length > 0 : permitido(o))),
);

const activo = (url) => (url === '/' ? page.url === '/' : page.url.startsWith(url));

// Grupos abiertos: al entrar, se abre el grupo de la página actual
const abiertos = ref(new Set(opciones.filter((o) => o.hijos?.some((h) => activo(h.url))).map((o) => o.texto)));
const alternar = (texto) => {
    const s = new Set(abiertos.value);
    s.has(texto) ? s.delete(texto) : s.add(texto);
    abiertos.value = s;
};

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

            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
                <template v-for="op in menu" :key="op.texto">
                    <!-- Grupo con submenú -->
                    <div v-if="op.hijos">
                        <button
                            type="button"
                            class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors hover:bg-slate-800"
                            :class="op.hijos.some((h) => activo(h.url)) ? 'text-white font-medium' : ''"
                            @click="alternar(op.texto)"
                        >
                            <i :class="op.icono"></i>
                            {{ op.texto }}
                            <i class="pi ml-auto text-xs" :class="abiertos.has(op.texto) ? 'pi-chevron-down' : 'pi-chevron-right'"></i>
                        </button>
                        <div v-show="abiertos.has(op.texto)" class="mt-1 ml-5 pl-2 border-l border-slate-700 space-y-1">
                            <Link
                                v-for="h in op.hijos"
                                :key="h.url"
                                :href="h.url"
                                class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors"
                                :class="activo(h.url) ? 'bg-emerald-600 text-white' : 'hover:bg-slate-800'"
                                @click="menuAbierto = false"
                            >
                                <i :class="h.icono"></i>
                                {{ h.texto }}
                            </Link>
                        </div>
                    </div>

                    <!-- Opción simple -->
                    <Link
                        v-else
                        :href="op.url"
                        class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors"
                        :class="activo(op.url) ? 'bg-emerald-600 text-white' : 'hover:bg-slate-800'"
                        @click="menuAbierto = false"
                    >
                        <i :class="op.icono"></i>
                        {{ op.texto }}
                    </Link>
                </template>
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