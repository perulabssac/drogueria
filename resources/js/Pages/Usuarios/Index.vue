<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';

const props = defineProps({
    usuarios: Object,
    filtros: Object,
    roles: Object,
});

const confirm = useConfirm();
const yo = usePage().props.auth.user;
const buscar = ref(props.filtros.buscar ?? '');
const rol = ref(props.filtros.rol ?? null);
const opcionesRol = Object.entries(props.roles).map(([value, label]) => ({ value, label }));
const COLOR_ROL = { admin: 'danger', vendedor: 'info', almacen: 'warn', contador: 'contrast' };

const recargar = (extra = {}) =>
    router.get('/usuarios', { buscar: buscar.value || undefined, rol: rol.value || undefined, ...extra }, { preserveState: true, preserveScroll: true, replace: true });

let espera;
watch(buscar, () => {
    clearTimeout(espera);
    espera = setTimeout(() => recargar(), 350);
});
watch(rol, () => recargar());

// Activar / desactivar con confirmación (un usuario inactivo no puede ingresar)
const cambiarEstado = (u) =>
    confirm.require({
        header: u.activo ? 'Desactivar usuario' : 'Activar usuario',
        message: u.activo
            ? `${u.name} ya no podrá ingresar al sistema.${u.caja_abierta_id ? ' Ojo: tiene una caja abierta, ciérrala antes.' : ''} ¿Continuar?`
            : `${u.name} podrá volver a ingresar al sistema. ¿Continuar?`,
        icon: 'pi pi-exclamation-triangle',
        acceptProps: { label: u.activo ? 'Sí, desactivar' : 'Sí, activar', severity: u.activo ? 'danger' : 'success' },
        rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
        accept: () => router.post(`/usuarios/${u.id}/estado`, {}, { preserveScroll: true }),
    });
</script>

<template>
    <Head title="Usuarios" />
    <AppLayout titulo="Usuarios">
        <div class="bg-white rounded-xl border border-slate-200">
            <div class="p-4 flex flex-wrap gap-3 items-center border-b border-slate-100">
                <IconField class="w-full sm:w-72">
                    <InputIcon class="pi pi-search" />
                    <InputText v-model="buscar" placeholder="Nombre o correo" fluid />
                </IconField>
                <Select v-model="rol" :options="opcionesRol" optionLabel="label" optionValue="value" placeholder="Todos los roles" showClear class="w-full sm:w-48" />
                <Link href="/usuarios/nuevo" class="sm:ml-auto"><Button label="Nuevo usuario" icon="pi pi-user-plus" /></Link>
            </div>

            <DataTable
                :value="usuarios.data"
                lazy
                paginator
                :rows="usuarios.per_page"
                :totalRecords="usuarios.total"
                :first="(usuarios.current_page - 1) * usuarios.per_page"
                :rowClass="(u) => (u.activo ? '' : 'opacity-60')"
                @page="(e) => recargar({ page: e.page + 1 })"
            >
                <template #empty>No hay usuarios con ese filtro.</template>
                <Column header="Usuario">
                    <template #body="{ data }">
                        <p class="font-medium">
                            {{ data.name }}
                            <span v-if="data.id === yo.id" class="text-xs text-slate-500">(tú)</span>
                        </p>
                        <p class="text-xs text-slate-500">{{ data.email }}</p>
                    </template>
                </Column>
                <Column header="Rol">
                    <template #body="{ data }">
                        <Tag :value="roles[data.rol] ?? data.rol" :severity="COLOR_ROL[data.rol]" />
                    </template>
                </Column>
                <Column header="Sucursal">
                    <template #body="{ data }">{{ data.sucursal?.nombre ?? '—' }}</template>
                </Column>
                <Column header="Estado">
                    <template #body="{ data }">
                        <Tag :value="data.activo ? 'Activo' : 'Inactivo'" :severity="data.activo ? 'success' : 'secondary'" />
                        <Tag v-if="data.caja_abierta_id" value="Caja abierta" severity="warn" icon="pi pi-wallet" class="ml-1" />
                    </template>
                </Column>
                <Column class="text-right">
                    <template #body="{ data }">
                        <div class="flex justify-end gap-1">
                            <Link :href="`/usuarios/${data.id}/editar`">
                                <Button icon="pi pi-pencil" severity="info" size="small" v-tooltip.top="'Editar'" />
                            </Link>
                            <Button
                                v-if="data.id !== yo.id"
                                :icon="data.activo ? 'pi pi-ban' : 'pi pi-check'"
                                :severity="data.activo ? 'danger' : 'success'"
                                size="small"
                                v-tooltip.top="data.activo ? 'Desactivar' : 'Activar'"
                                @click="cambiarEstado(data)"
                            />
                        </div>
                    </template>
                </Column>
            </DataTable>
        </div>
    </AppLayout>
</template>