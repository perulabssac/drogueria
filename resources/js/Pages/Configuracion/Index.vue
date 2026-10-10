<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Password from 'primevue/password';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import ToggleSwitch from 'primevue/toggleswitch';
import Button from 'primevue/button';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import { useConfirm } from 'primevue/useconfirm';
import { fecha } from '@/utils/formato';

const props = defineProps({
    empresa: Object,
    certificado: Object, // null si no hay certificado cargado
    soapActivo: Boolean,
    sucursales: Array,
    series: Array,
    tiposSerie: Object,
    pruebaSunat: Object, // resultado del último envío de prueba
    logoUrl: String, // null si aún no hay logo
});

const confirm = useConfirm();
const pestana = ref(props.pruebaSunat ? 'sunat' : 'empresa');

// ================= EMPRESA =================
const formEmpresa = useForm({
    ruc: props.empresa.ruc,
    razon_social: props.empresa.razon_social,
    nombre_comercial: props.empresa.nombre_comercial ?? '',
    giro: props.empresa.giro ?? '',
    direccion: props.empresa.direccion,
    ubigeo: props.empresa.ubigeo,
    departamento: props.empresa.departamento,
    provincia: props.empresa.provincia,
    distrito: props.empresa.distrito,
    urbanizacion: props.empresa.urbanizacion ?? '',
    telefono: props.empresa.telefono ?? '',
    email: props.empresa.email ?? '',
    cuentas_bancarias: props.empresa.cuentas_bancarias ?? '',
});
const guardarEmpresa = () => formEmpresa.put('/configuracion/empresa', { preserveScroll: true });

// Logo: se muestra una vista previa antes de subirlo
const formLogo = useForm({ logo: null });
const vistaPrevia = ref(null);
const claveArchivo = ref(0); // cambia para vaciar el selector de archivo
const elegirLogo = (evento) => {
    const archivo = evento.target.files[0] ?? null;
    formLogo.logo = archivo;
    formLogo.clearErrors();
    vistaPrevia.value = archivo ? URL.createObjectURL(archivo) : null;
};
const limpiarLogo = () => {
    formLogo.reset();
    vistaPrevia.value = null;
    claveArchivo.value++;
};
const subirLogo = () => formLogo.post('/configuracion/logo', { forceFormData: true, preserveScroll: true, onSuccess: limpiarLogo });
const eliminarLogo = () =>
    confirm.require({
        header: 'Quitar logo',
        message: 'El sistema y los comprobantes se mostrarán sin logo. ¿Continuar?',
        icon: 'pi pi-exclamation-triangle',
        acceptProps: { label: 'Quitar', severity: 'danger' },
        rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
        accept: () => router.delete('/configuracion/logo', { preserveScroll: true }),
    });

// ================= CONEXIÓN SUNAT =================
const opcionesEntorno = [
    { value: 'beta', label: 'Pruebas (beta)' },
    { value: 'produccion', label: 'Producción' },
];
const formSunat = useForm({
    entorno: props.empresa.entorno,
    sol_usuario: props.empresa.sol_usuario ?? '',
    sol_clave: '',
});
const guardarSunat = () => {
    const enviar = () => formSunat.put('/configuracion/sunat', { preserveScroll: true, onSuccess: () => formSunat.reset('sol_clave') });
    if (formSunat.entorno === 'produccion' && props.empresa.entorno !== 'produccion') {
        confirm.require({
            header: 'Pasar a producción',
            message: 'Desde ahora cada comprobante emitido tendrá validez tributaria ante SUNAT y no podrá borrarse. ¿Continuar?',
            icon: 'pi pi-exclamation-triangle',
            acceptProps: { label: 'Sí, pasar a producción', severity: 'danger' },
            rejectProps: { label: 'Cancelar', severity: 'secondary', outlined: true },
            accept: enviar,
        });
    } else {
        enviar();
    }
};

// Certificado digital
const formCertificado = useForm({ archivo: null, password: '' });
const subirCertificado = () =>
    formCertificado.post('/configuracion/certificado', {
        forceFormData: true, // necesario para enviar archivos
        preserveScroll: true,
        onSuccess: () => formCertificado.reset(),
    });
const generarDemo = () => router.post('/configuracion/certificado-demo', {}, { preserveScroll: true });

const severidadCertificado = computed(() => {
    if (!props.certificado) return 'danger';
    if (props.certificado.dias_restantes < 0) return 'danger';
    if (props.certificado.dias_restantes <= 30) return 'warn';
    return 'success';
});

// Factura de prueba
const probando = ref(false);
const probarSunat = () =>
    router.post('/configuracion/probar-sunat', {}, {
        preserveScroll: true,
        onStart: () => (probando.value = true),
        onFinish: () => (probando.value = false),
    });

// ================= SERIES =================
const vacioSerie = { id: null, sucursal_id: props.sucursales[0]?.id ?? null, tipo_comprobante: '01', serie: '', correlativo: 0, activo: true };
const formSerie = useForm({ ...vacioSerie });
const opcionesTipoSerie = Object.entries(props.tiposSerie).map(([value, label]) => ({ value, label: `${value} - ${label}` }));

const editarSerie = (s) => {
    formSerie.clearErrors();
    Object.assign(formSerie, { id: s.id, sucursal_id: s.sucursal_id, tipo_comprobante: s.tipo_comprobante, serie: s.serie, correlativo: s.correlativo, activo: s.activo });
};
const limpiarSerie = () => {
    formSerie.clearErrors();
    Object.assign(formSerie, { ...vacioSerie });
};
const guardarSerie = () => formSerie.post('/configuracion/series', { preserveScroll: true, onSuccess: limpiarSerie });
</script>

<template>
    <Head title="Configuración" />
    <AppLayout titulo="Configuración">
        <Tabs v-model:value="pestana">
            <TabList>
                <Tab value="empresa"><i class="pi pi-building mr-2"></i>Empresa</Tab>
                <Tab value="sunat"><i class="pi pi-send mr-2"></i>Conexión SUNAT</Tab>
                <Tab value="series"><i class="pi pi-list mr-2"></i>Series</Tab>
            </TabList>

            <TabPanels class="!bg-transparent !px-0">
                <!-- ================= EMPRESA ================= -->
                <TabPanel value="empresa">
                    <!-- Logo -->
                    <section class="bg-white rounded-xl border border-slate-200 p-5 mb-6">
                        <h2 class="font-semibold">Logo</h2>
                        <p class="text-sm text-slate-500 mb-4">
                            Aparece en las facturas y boletas (PDF), en la pantalla de inicio de sesión y en el menú. PNG o JPG horizontal, de máximo 1 MB.
                        </p>
                        <div class="flex flex-wrap items-center gap-6">
                            <div class="w-72 h-24 rounded-lg border border-dashed border-slate-300 bg-slate-50 flex items-center justify-center p-2">
                                <img v-if="vistaPrevia || logoUrl" :src="vistaPrevia || logoUrl" alt="Logo" class="max-h-full max-w-full object-contain" />
                                <span v-else class="text-sm text-slate-400">Sin logo</span>
                            </div>
                            <form class="flex-1 min-w-64 space-y-3" @submit.prevent="subirLogo">
                                <p v-if="vistaPrevia" class="text-sm text-amber-700">Vista previa: pulsa "{{ logoUrl ? 'Cambiar logo' : 'Subir logo' }}" para guardarlo.</p>
                                <input
                                    :key="claveArchivo"
                                    type="file"
                                    accept="image/png,image/jpeg"
                                    class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-violet-50 file:px-3 file:py-2 file:text-violet-700 hover:file:bg-violet-100"
                                    @input="elegirLogo"
                                />
                                <small v-if="formLogo.errors.logo" class="text-red-600 block">{{ formLogo.errors.logo }}</small>
                                <div class="flex gap-2">
                                    <Button
                                        type="submit"
                                        :label="logoUrl ? 'Cambiar logo' : 'Subir logo'"
                                        icon="pi pi-upload"
                                        severity="help"
                                        :disabled="!formLogo.logo"
                                        :loading="formLogo.processing"
                                    />
                                    <Button v-if="vistaPrevia" type="button" label="Descartar" severity="secondary" text @click="limpiarLogo" />
                                    <Button v-else-if="logoUrl" type="button" label="Quitar logo" icon="pi pi-trash" severity="danger" text @click="eliminarLogo" />
                                </div>
                            </form>
                        </div>
                    </section>

                    <form class="bg-white rounded-xl border border-slate-200 p-5 space-y-4" @submit.prevent="guardarEmpresa">
                        <p class="text-sm text-slate-500">Estos datos aparecen en tus comprobantes y en el XML que recibe SUNAT. Deben coincidir con tu ficha RUC.</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                            <div class="flex flex-col gap-1">
                                <label class="text-sm">RUC *</label>
                                <InputText v-model="formEmpresa.ruc" maxlength="11" :invalid="!!formEmpresa.errors.ruc" />
                                <small class="text-red-600">{{ formEmpresa.errors.ruc }}</small>
                            </div>
                            <div class="xl:col-span-2 flex flex-col gap-1">
                                <label class="text-sm">Razón social *</label>
                                <InputText v-model="formEmpresa.razon_social" :invalid="!!formEmpresa.errors.razon_social" />
                                <small class="text-red-600">{{ formEmpresa.errors.razon_social }}</small>
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm">Nombre comercial</label>
                                <InputText v-model="formEmpresa.nombre_comercial" />
                            </div>

                            <div class="sm:col-span-2 xl:col-span-4 flex flex-col gap-1">
                                <label class="text-sm">Giro del negocio</label>
                                <InputText
                                    v-model="formEmpresa.giro"
                                    maxlength="150"
                                    placeholder="Ej.: Distribuidor de medicamentos, material y dispositivos médicos"
                                    :invalid="!!formEmpresa.errors.giro"
                                />
                                <small v-if="formEmpresa.errors.giro" class="text-red-600">{{ formEmpresa.errors.giro }}</small>
                                <small v-else class="text-slate-500">Sale debajo de la razón social en facturas, boletas y cotizaciones. Opcional.</small>
                            </div>

                            <div class="sm:col-span-2 flex flex-col gap-1">
                                <label class="text-sm">Dirección fiscal *</label>
                                <InputText v-model="formEmpresa.direccion" :invalid="!!formEmpresa.errors.direccion" />
                                <small class="text-red-600">{{ formEmpresa.errors.direccion }}</small>
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm">Urbanización</label>
                                <InputText v-model="formEmpresa.urbanizacion" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm">Ubigeo *</label>
                                <InputText v-model="formEmpresa.ubigeo" maxlength="6" :invalid="!!formEmpresa.errors.ubigeo" />
                                <small v-if="formEmpresa.errors.ubigeo" class="text-red-600">{{ formEmpresa.errors.ubigeo }}</small>
                                <small v-else class="text-slate-500">Código INEI de 6 dígitos (San Isidro: 150131)</small>
                            </div>

                            <div class="flex flex-col gap-1">
                                <label class="text-sm">Departamento *</label>
                                <InputText v-model="formEmpresa.departamento" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm">Provincia *</label>
                                <InputText v-model="formEmpresa.provincia" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm">Distrito *</label>
                                <InputText v-model="formEmpresa.distrito" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm">Teléfono</label>
                                <InputText v-model="formEmpresa.telefono" />
                            </div>

                            <div class="flex flex-col gap-1">
                                <label class="text-sm">Correo</label>
                                <InputText v-model="formEmpresa.email" type="email" :invalid="!!formEmpresa.errors.email" />
                                <small class="text-red-600">{{ formEmpresa.errors.email }}</small>
                            </div>
                            <div class="sm:col-span-2 xl:col-span-3 flex flex-col gap-1">
                                <label class="text-sm">Cuentas bancarias (se imprimen en la factura)</label>
                                <Textarea v-model="formEmpresa.cuentas_bancarias" rows="2" autoResize />
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <Button type="submit" label="Guardar datos" icon="pi pi-check" :loading="formEmpresa.processing" />
                        </div>
                    </form>
                </TabPanel>

                <!-- ================= CONEXIÓN SUNAT ================= -->
                <TabPanel value="sunat">
                    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                        <!-- Entorno y clave SOL -->
                        <form class="bg-white rounded-xl border border-slate-200 p-5 space-y-4" @submit.prevent="guardarSunat">
                            <h2 class="font-semibold">Entorno y clave SOL</h2>
                            <Message v-if="!soapActivo" severity="error" size="small">
                                La extensión <b>soap</b> de PHP no está activa y es necesaria para enviar a SUNAT. En Laragon: Menú → PHP → Extensions → soap, y reinicia.
                            </Message>

                            <div class="flex flex-col gap-1">
                                <label class="text-sm">Entorno</label>
                                <SelectButton v-model="formSunat.entorno" :options="opcionesEntorno" optionLabel="label" optionValue="value" :allowEmpty="false" />
                                <small class="text-red-600">{{ formSunat.errors.entorno }}</small>
                                <small class="text-slate-500">
                                    <b>Pruebas:</b> los comprobantes no tienen valor tributario, ideal para aprender. <b>Producción:</b> todo es real.
                                </small>
                            </div>

                            <template v-if="formSunat.entorno === 'produccion'">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div class="flex flex-col gap-1">
                                        <label class="text-sm">Usuario SOL secundario *</label>
                                        <InputText v-model="formSunat.sol_usuario" placeholder="FACTURA1" :invalid="!!formSunat.errors.sol_usuario" />
                                        <small class="text-red-600">{{ formSunat.errors.sol_usuario }}</small>
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label class="text-sm">Clave SOL</label>
                                        <Password
                                            v-model="formSunat.sol_clave"
                                            :feedback="false"
                                            toggleMask
                                            fluid
                                            :placeholder="empresa.tiene_clave_sol ? '•••••• (guardada)' : ''"
                                            :invalid="!!formSunat.errors.sol_clave"
                                        />
                                        <small class="text-red-600">{{ formSunat.errors.sol_clave }}</small>
                                    </div>
                                </div>
                                <Message severity="info" size="small">
                                    Crea un usuario SOL secundario solo para facturación desde SUNAT Operaciones en Línea. La clave se guarda cifrada.
                                </Message>
                            </template>
                            <Message v-else severity="secondary" size="small">En pruebas se usan las credenciales públicas de SUNAT (MODDATOS); no necesitas tu clave SOL.</Message>

                            <div class="flex justify-end">
                                <Button type="submit" label="Guardar conexión" icon="pi pi-check" :loading="formSunat.processing" />
                            </div>
                        </form>

                        <!-- Certificado digital -->
                        <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
                            <h2 class="font-semibold">Certificado digital</h2>

                            <div v-if="certificado" class="rounded-lg border border-slate-200 p-3 text-sm grid grid-cols-2 gap-2">
                                <div>
                                    <p class="text-xs text-slate-500">Titular</p>
                                    <p class="font-medium">{{ certificado.titular }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-slate-500">Vence</p>
                                    <p class="font-medium flex items-center gap-2">
                                        {{ fecha(certificado.vence) }}
                                        <Tag :severity="severidadCertificado" :value="certificado.dias_restantes < 0 ? 'Vencido' : certificado.dias_restantes + ' días'" />
                                    </p>
                                </div>
                                <p v-if="certificado.es_demo" class="col-span-2">
                                    <Tag severity="warn" value="Certificado de prueba" /> <span class="text-slate-500">solo sirve para el entorno beta.</span>
                                </p>
                            </div>
                            <Message v-else severity="warn" size="small">Aún no hay certificado. Sin él no se pueden firmar comprobantes.</Message>

                            <form class="space-y-3" @submit.prevent="subirCertificado">
                                <p class="text-sm text-slate-600">Sube el certificado (.pfx o .p12) que te entregó la entidad certificadora:</p>
                                <input
                                    type="file"
                                    accept=".pfx,.p12"
                                    class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-2 file:text-emerald-700 hover:file:bg-emerald-100"
                                    @input="formCertificado.archivo = $event.target.files[0]"
                                />
                                <small class="text-red-600">{{ formCertificado.errors.archivo || formCertificado.errors.certificado }}</small>
                                <div class="flex gap-2">
                                    <Password v-model="formCertificado.password" :feedback="false" toggleMask placeholder="Contraseña del certificado" fluid :invalid="!!formCertificado.errors.password" />
                                    <Button type="submit" label="Subir" icon="pi pi-upload" :loading="formCertificado.processing" />
                                </div>
                            </form>

                            <div v-if="empresa.entorno === 'beta'" class="border-t border-slate-100 pt-4">
                                <p class="text-sm text-slate-600 mb-2">¿Aún no tienes certificado? Para practicar en beta puedes generar uno de prueba:</p>
                                <Button label="Generar certificado de prueba" icon="pi pi-key" severity="secondary" outlined @click="generarDemo" />
                            </div>
                        </section>

                        <!-- Prueba de conexión -->
                        <section class="xl:col-span-2 bg-white rounded-xl border border-slate-200 p-5 space-y-4">
                            <div class="flex flex-wrap items-center gap-3">
                                <div class="flex-1">
                                    <h2 class="font-semibold">Probar la conexión</h2>
                                    <p class="text-sm text-slate-500">Envía a SUNAT beta una factura ficticia de S/ 118.00. No se guarda en el sistema ni usa tus series.</p>
                                </div>
                                <Button
                                    label="Enviar factura de prueba"
                                    icon="pi pi-send"
                                    :loading="probando"
                                    :disabled="empresa.entorno !== 'beta' || !certificado"
                                    @click="probarSunat"
                                />
                            </div>

                            <template v-if="pruebaSunat">
                                <Message v-if="pruebaSunat.exito" severity="success">
                                    <p class="font-semibold">¡SUNAT aceptó la factura de prueba!</p>
                                    <p>Código {{ pruebaSunat.codigo }}: {{ pruebaSunat.mensaje }}</p>
                                    <p v-if="pruebaSunat.hash" class="text-xs mt-1">Hash de la firma: {{ pruebaSunat.hash }}</p>
                                </Message>
                                <Message v-else severity="error">
                                    <p class="font-semibold">No se pudo completar el envío</p>
                                    <p><span v-if="pruebaSunat.codigo">Código {{ pruebaSunat.codigo }}: </span>{{ pruebaSunat.mensaje }}</p>
                                </Message>
                                <ul v-if="pruebaSunat.notas?.length" class="text-sm text-amber-700 list-disc pl-5">
                                    <li v-for="(n, i) in pruebaSunat.notas" :key="i">{{ n }}</li>
                                </ul>
                            </template>
                        </section>
                    </div>
                </TabPanel>

                <!-- ================= SERIES ================= -->
                <TabPanel value="series">
                    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                        <section class="xl:col-span-2 bg-white rounded-xl border border-slate-200">
                            <DataTable :value="series" size="small">
                                <Column header="Tipo">
                                    <template #body="{ data }">{{ tiposSerie[data.tipo_comprobante] }}</template>
                                </Column>
                                <Column field="serie" header="Serie" class="font-medium" />
                                <Column header="Sucursal">
                                    <template #body="{ data }">{{ data.sucursal?.nombre }}</template>
                                </Column>
                                <Column header="Último emitido" class="text-right">
                                    <template #body="{ data }">{{ data.correlativo }}</template>
                                </Column>
                                <Column header="Estado">
                                    <template #body="{ data }">
                                        <Tag :value="data.activo ? 'Activa' : 'Inactiva'" :severity="data.activo ? 'success' : 'secondary'" />
                                    </template>
                                </Column>
                                <Column class="text-right">
                                    <template #body="{ data }">
                                        <Button icon="pi pi-pencil" text rounded v-tooltip.top="'Editar'" @click="editarSerie(data)" />
                                    </template>
                                </Column>
                            </DataTable>
                        </section>

                        <form class="bg-white rounded-xl border border-slate-200 p-5 space-y-4" @submit.prevent="guardarSerie">
                            <h2 class="font-semibold">{{ formSerie.id ? 'Editar serie' : 'Nueva serie' }}</h2>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm">Sucursal</label>
                                <Select v-model="formSerie.sucursal_id" :options="sucursales" optionLabel="nombre" optionValue="id" fluid />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label class="text-sm">Tipo de comprobante</label>
                                <Select v-model="formSerie.tipo_comprobante" :options="opcionesTipoSerie" optionLabel="label" optionValue="value" :disabled="!!formSerie.id" fluid />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="flex flex-col gap-1">
                                    <label class="text-sm">Serie</label>
                                    <InputText v-model="formSerie.serie" maxlength="4" placeholder="F001" :disabled="!!formSerie.id" :invalid="!!formSerie.errors.serie" />
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label class="text-sm">Último emitido</label>
                                    <InputNumber v-model="formSerie.correlativo" :min="0" :useGrouping="false" fluid :invalid="!!formSerie.errors.correlativo" />
                                </div>
                            </div>
                            <small class="text-red-600 block">{{ formSerie.errors.serie || formSerie.errors.correlativo }}</small>
                            <small class="text-slate-500 block">
                                "Último emitido" es 0 si la serie es nueva. Si ya emitías con otro sistema, pon el último número usado para continuar desde ahí.
                            </small>
                            <label class="flex items-center gap-2 text-sm"><ToggleSwitch v-model="formSerie.activo" /> Activa</label>
                            <div class="flex justify-end gap-2">
                                <Button v-if="formSerie.id" label="Cancelar" severity="secondary" text @click="limpiarSerie" />
                                <Button type="submit" label="Guardar serie" icon="pi pi-check" :loading="formSerie.processing" />
                            </div>
                        </form>
                    </div>
                </TabPanel>
            </TabPanels>
        </Tabs>
    </AppLayout>
</template>