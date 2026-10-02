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
// En los siguientes pasos iremos agregando más opciones aquí.
const opciones = [
    { texto: 'Inicio', icono: 'pi pi-home', url: '/', roles: ['vendedor', 'almacen'] },
    { texto: 'Nueva venta', icono: 'pi pi-shopping-cart', url: '/ventas/nueva', roles: ['vendedor'] },
    { texto: 'Comprobantes', icono: 'pi pi-file', url: '/comprobantes', roles: ['vendedor', 'contador'] },
    { texto: 'Caja', icono: 'pi pi-wallet', url: '/caja', roles: ['vendedor'] },
    { texto: 'Cobranzas', icono: 'pi pi-money-bill', url: '/cobranzas', roles: ['vendedor'] },
    { texto: 'Productos', icono: 'pi pi-box', url: '/productos', roles: ['almacen'] },
    { texto: 'Compras', icono: 'pi pi-truck', url: '/compras', roles: ['almacen', 'contador'] },
    { texto: 'Proveedores', icono: 'pi pi-building', url: '/proveedores', roles: ['almacen'] },
    { texto: 'Reportes contables', icono: 'pi pi-chart-bar', url: '/reportes', roles: ['contador'] },
    { texto: 'Usuarios', icono: 'pi pi-users', url: '/usuarios', roles: [] }, // solo admin
    { texto: 'Configuración', icono: 'pi pi-cog', url: '/configuracion', roles: [] }, // solo admin
];

const menu = computed(() =>
    opciones.filter((o) => usuario.value.rol === 'admin' || o.roles.includes(usuario.value.rol)),
);

const activo = (url) => (url === '/' ? page.url === '/' : page.url.startsWith(url));

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

            <nav class="flex-1 px-3 py-4 space-y-1">
                <Link
                    v-for="op in menu"
                    :key="op.url"
                    :href="op.url"
                    class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors"
                    :class="activo(op.url) ? 'bg-emerald-600 text-white' : 'hover:bg-slate-800'"
                    @click="menuAbierto = false"
                >
                    <i :class="op.icono"></i>
                    {{ op.texto }}
                </Link>
            </nav>

            <div class="px-5 py-4 border-t border-slate-800 text-xs">
                <p class="text-white font-medium">{{ usuario.name }}</p>
                <p class="text-slate-400 capitalize">{{ usuario.rol }} · {{ usuario.sucursal }}</p>
            </div>
        </aside>

        <!-- Fondo oscuro al abrir el menú en celulares -->
        <div v-if="menuAbierto" class="fixed inset-0 z-20 bg-black/40 lg:hidden" @click="menuAbierto = false"></div>

        <!-- Contenido -->
        <div class="flex-1 lg:ml-60 min-w-0 overflow-x-hidden">
            <header class="no-print sticky top-0 z-10 bg-white border-b border-slate-200 px-4 lg:px-6 h-14 flex items-center gap-3">
                <div class="lg:hidden">
    <Button icon="pi pi-bars" text rounded @click="menuAbierto = true" aria-label="Menú" />
</div>
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

            <main class="p-4 lg:p-6">
                <slot />
            </main>
        </div>
    </div>
</template>