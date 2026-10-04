<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import Message from 'primevue/message';
import Menu from 'primevue/menu';
import Dialog from 'primevue/dialog';
import Textarea from 'primevue/textarea';
import { soles, precio, fecha, cantidad } from '@/utils/formato';
import { estadoSunat } from '@/utils/sunat';

const props = defineProps({
    comprobante: Object,
    puedeReenviarse: Boolean,
    puedeNotaCredito: Boolean,
    puedeBaja: Boolean,
    limiteBaja: String,
});

const c = computed(() => props.comprobante);
const estado = computed(() => estadoSunat(c.value.estado));
const esInterno = computed(() => c.value.tipo_comprobante === 'NV');
const esRechazado = computed(() => c.value.estado === 'rechazado');
const esAnulado = computed(() => c.value.estado === 'anulado');
// Rechazado por SUNAT o dado de baja: no tiene validez (no se imprime, no se cobra, no se despacha)
const sinValidez = computed(() => esRechazado.value || esAnulado.value);
const esNotaCredito = computed(() => c.value.tipo_comprobante === '07');
// Venta al crédito que aún tiene deuda: se muestra el botón "Cobrar"
// El contador solo consulta e imprime/descarga: no reenvía, no cobra ni vende
const soloLectura = usePage().props.auth.user.rol === 'contador';
const puedeCobrar = computed(() => !soloLectura && c.value.forma_pago === 'credito' && Number(c.value.saldo) > 0 && !sinValidez.value);
// Factura o boleta válida: se puede emitir la guía de remisión para despachar la mercadería
const puedeGuia = computed(() => !soloLectura && ['01', '03'].includes(c.value.tipo_comprobante) && !sinValidez.value);
const procesando = ref(false);

const accion = (url) =>
    router.post(url, {}, {
        preserveScroll: true,
        onStart: () => (procesando.value = true),
        onFinish: () => (procesando.value = false),
    });

// Imprime sin salir de esta pantalla: la representación impresa se carga en un marco
// invisible y solo aparece el diálogo de impresión del navegador.
const imprimiendo = ref(null);
const imprimir = (formato) => {
    document.getElementById('marco-impresion')?.remove();
    imprimiendo.value = formato;

    const marco = document.createElement('iframe');
    marco.id = 'marco-impresion';
    // Fuera de la pantalla pero con tamaño real (algunos navegadores imprimen en blanco un marco oculto)
    marco.style.cssText = 'position:fixed;left:-10000px;top:0;width:800px;height:600px;border:0';
    marco.src = `/comprobantes/${c.value.id}/imprimir?formato=${formato}&auto=1`;
    marco.onload = () => setTimeout(() => (imprimiendo.value = null), 1500);
    document.body.appendChild(marco);
};

// Menú "Descargar": archivos electrónicos del comprobante (solo los que existen)
const menuDescargas = ref();
const descargas = computed(() => [
    { label: 'XML (comprobante electrónico)', icon: 'pi pi-file', url: `/comprobantes/${c.value.id}/xml`, visible: !!c.value.xml_path },
    { label: 'CDR (constancia de SUNAT)', icon: 'pi pi-verified', url: `/comprobantes/${c.value.id}/cdr`, visible: !!c.value.cdr_path },
    { label: 'CDR de la baja', icon: 'pi pi-ban', url: `/comprobantes/${c.value.id}/baja/cdr`, visible: !!c.value.baja_cdr_path },
]);
const hayDescargas = computed(() => descargas.value.some((d) => d.visible));

const severidadMensaje = computed(() => ({ aceptado: 'success', observado: 'warn', enviado: 'info', pendiente: 'secondary', anulado: 'secondary' })[c.value.estado] ?? 'error');

// ===== Comunicación de baja (anular ante SUNAT dentro de los 7 días) =====
const dialogoBaja = ref(false);
const formBaja = useForm({ motivo: '' });
const MOTIVOS_BAJA = ['Error en los datos del cliente', 'Error en los productos o montos', 'Venta no realizada'];

const abrirBaja = () => {
    formBaja.reset();
    formBaja.clearErrors();
    dialogoBaja.value = true;
};

const enviarBaja = () =>
    formBaja.post(`/comprobantes/${c.value.id}/baja`, {
        preserveScroll: true,
        onSuccess: () => (dialogoBaja.value = false),
    });

const BAJA = {
    enviada: { severidad: 'info', titulo: 'Comunicación de baja en proceso' },
    aceptada: { severidad: 'secondary', titulo: 'Dado de baja ante SUNAT' },
    rechazada: { severidad: 'error', titulo: 'SUNAT rechazó la comunicación de baja' },
    error: { severidad: 'warn', titulo: 'La comunicación de baja no llegó a SUNAT' },
};
const baja = computed(() => BAJA[c.value.baja_estado] ?? null);
</script>

<template>
    <Head :title="c.tipo_nombre + ' ' + c.numero" />
    <AppLayout :titulo="c.tipo_nombre + ' ' + c.numero">
        <!-- Barra de acciones -->
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <Link href="/comprobantes"><Button label="Volver" icon="pi pi-arrow-left" text /></Link>
            <Tag :value="estado.texto" :severity="estado.severidad" :icon="estado.icono" class="text-sm" />
            <div class="ml-auto flex flex-wrap gap-2">
                <Button v-if="puedeReenviarse && !soloLectura" label="Reenviar a SUNAT" icon="pi pi-refresh" :loading="procesando" @click="accion(`/comprobantes/${c.id}/reenviar`)" />
                <Button v-if="c.estado === 'enviado' && !soloLectura" label="Consultar respuesta" icon="pi pi-sync" :loading="procesando" @click="accion(`/comprobantes/${c.id}/consultar`)" />
                <Button v-if="c.baja_estado === 'enviada' && !soloLectura" label="Consultar baja" icon="pi pi-sync" severity="secondary" :loading="procesando" @click="accion(`/comprobantes/${c.id}/baja/consultar`)" />

                <!-- Archivos electrónicos (XML y CDR) agrupados en un menú -->
                <template v-if="hayDescargas">
                    <Button severity="help" @click="(e) => menuDescargas.toggle(e)">
                        <template #default>
                            <i class="pi pi-download"></i>
                            <span>Descargar</span>
                            <i class="pi pi-angle-down text-xs"></i>
                        </template>
                    </Button>
                    <Menu ref="menuDescargas" :model="descargas" popup />
                </template>

                <!-- Un comprobante rechazado o dado de baja no tiene validez: no se imprime -->
                <template v-if="!sinValidez">
                    <!-- Botones de impresión en azul para ubicarlos rápido -->
                    <Button label="Ticket" icon="pi pi-receipt" severity="info" :loading="imprimiendo === 'ticket'" @click="imprimir('ticket')" />
                    <Button label="Imprimir A4" icon="pi pi-print" severity="info" :loading="imprimiendo === 'a4'" @click="imprimir('a4')" />
                    <!-- Vista previa en pestaña aparte (para elegir 58/80 mm o guardar PDF) -->
                    <a :href="`/comprobantes/${c.id}/imprimir?formato=ticket`" target="_blank">
                        <Button icon="pi pi-eye" severity="info" v-tooltip.top="'Vista previa'" />
                    </a>
                </template>

                <!-- Venta al crédito con saldo: registrar el cobro de una cuota -->
                <Link v-if="puedeCobrar" :href="`/cobranzas/${c.id}`">
                    <Button label="Cobrar" icon="pi pi-money-bill" severity="success" />
                </Link>
                <!-- Guía de remisión para despachar la mercadería (botón oscuro) -->
                <Link v-if="puedeGuia" :href="`/guias/nueva?comprobante=${c.id}`">
                    <Button label="Emitir guía" icon="pi pi-map-marker" severity="contrast" />
                </Link>
                <!-- Nota de crédito (anulación o devolución): solo administrador -->
                <Link v-if="puedeNotaCredito" :href="`/comprobantes/${c.id}/nota-credito`">
                    <Button label="Nota de crédito" icon="pi pi-file-edit" severity="warn" />
                </Link>
                <!-- Comunicación de baja (rojo): anula el comprobante ante SUNAT, solo administrador -->
                <Button v-if="puedeBaja" label="Dar de baja" icon="pi pi-ban" severity="danger" @click="abrirBaja" />
                <Link v-if="!soloLectura" href="/ventas/nueva"><Button label="Nueva venta" icon="pi pi-plus" /></Link>
            </div>
        </div>

        <!-- Nota de crédito: qué comprobante modifica -->
        <Message v-if="esNotaCredito && c.referencia" severity="warn" class="mb-4">
            Modifica a
            <Link :href="`/comprobantes/${c.referencia.id}`" class="font-semibold underline">{{ c.referencia.tipo_nombre }} {{ c.referencia.numero }}</Link>
            · Motivo {{ c.motivo_codigo }}: {{ c.motivo_descripcion }}
        </Message>

        <!-- Factura o boleta con notas de crédito emitidas -->
        <Message v-if="c.notas?.length" severity="secondary" class="mb-4">
            <span class="font-medium">Notas de crédito de este comprobante:</span>
            <Link v-for="n in c.notas" :key="n.id" :href="`/comprobantes/${n.id}`" class="ml-2 underline">
                {{ n.numero }} ({{ soles(n.total) }}{{ n.estado === 'rechazado' ? ' · rechazada' : '' }})
            </Link>
        </Message>

        <!-- Nota de venta: documento interno -->
        <Message v-if="esInterno" severity="info" class="mb-4">
            Documento de uso interno, no enviado a SUNAT. No es comprobante de pago: si el cliente lo requiere, emítele boleta o factura.
        </Message>

        <!-- Respuesta de SUNAT -->
        <Message v-if="!esInterno && c.sunat_descripcion" :severity="severidadMensaje" class="mb-4">
            <p><span v-if="c.sunat_codigo" class="font-semibold">Código {{ c.sunat_codigo }}: </span>{{ c.sunat_descripcion }}</p>
            <ul v-if="c.sunat_observaciones?.length" class="list-disc pl-5 mt-1 text-sm">
                <li v-for="(o, i) in c.sunat_observaciones" :key="i">{{ o }}</li>
            </ul>
            <p v-if="c.resumen" class="text-xs mt-1">Informado en el resumen {{ c.resumen }} · Ticket {{ c.ticket }}</p>
            <p v-if="esRechazado" class="text-sm mt-2 font-medium">
                Este comprobante no tiene validez y su stock ya volvió a los lotes. Corrige el dato indicado y emite una venta nueva.
            </p>
        </Message>

        <!-- Comunicación de baja -->
        <Message v-if="baja" :severity="baja.severidad" class="mb-4">
            <p class="font-semibold">{{ baja.titulo }}<span v-if="c.baja_documento"> · {{ c.baja_documento }}</span></p>
            <p class="text-sm mt-1">
                Motivo: {{ c.baja_motivo }}
                <span v-if="c.baja_usuario"> · Solicitada por {{ c.baja_usuario.name }}</span>
                <span v-if="c.baja_at"> el {{ fecha(c.baja_at) }} {{ String(c.baja_at).substring(11, 16) }}</span>
            </p>
            <p v-if="c.baja_descripcion" class="text-sm mt-1">
                <span v-if="c.baja_codigo" class="font-semibold">Código {{ c.baja_codigo }}: </span>{{ c.baja_descripcion }}
            </p>
            <p v-if="esAnulado" class="text-sm mt-2 font-medium">
                Este comprobante ya no tiene validez: la mercadería volvió al stock y no cuenta en ventas, caja ni deudas.
            </p>
            <p v-else-if="['rechazada', 'error'].includes(c.baja_estado)" class="text-sm mt-2 font-medium">
                El comprobante sigue válido. Puedes volver a intentar la baja o anularlo con una nota de crédito.
            </p>
        </Message>

        <!-- Cabecera -->
        <section class="bg-white rounded-xl border border-slate-200 p-5 grid sm:grid-cols-2 xl:grid-cols-4 gap-4 text-sm mb-6">
            <div class="sm:col-span-2">
                <p class="text-xs text-slate-500">Cliente</p>
                <p class="font-medium">{{ c.cliente.razon_social }}</p>
                <p class="text-slate-500">{{ c.cliente.tipo_documento === '6' ? 'RUC' : 'Doc.' }} {{ c.cliente.numero_documento }}<span v-if="c.cliente.direccion"> · {{ c.cliente.direccion }}</span></p>
            </div>
            <div>
                <p class="text-xs text-slate-500">Emisión</p>
                <p class="font-medium">{{ fecha(c.fecha_emision) }} {{ String(c.fecha_emision).substring(11, 16) }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500">Condición</p>
                <p class="font-medium">{{ c.forma_pago === 'credito' ? 'Crédito · vence ' + fecha(c.fecha_vencimiento) : 'Contado' }}</p>
            </div>
            <div>
                <p class="text-xs text-slate-500">Vendedor</p>
                <p class="font-medium">{{ c.vendedor?.name ?? c.usuario?.name }}</p>
            </div>
            <div v-if="c.guia_remision">
                <p class="text-xs text-slate-500">Guía de remisión (en la factura)</p>
                <p class="font-medium">{{ c.guia_remision }}</p>
            </div>
            <div v-if="c.guias?.length">
                <p class="text-xs text-slate-500">Guías electrónicas emitidas</p>
                <div class="flex flex-wrap gap-2 mt-1">
                    <Link v-for="gr in c.guias" :key="gr.id" :href="`/guias/${gr.id}`">
                        <Tag :value="gr.numero" :severity="{ aceptado: 'success', rechazado: 'danger', error: 'warn' }[gr.estado] ?? 'info'" class="cursor-pointer" />
                    </Link>
                </div>
            </div>
            <div v-if="c.orden_compra">
                <p class="text-xs text-slate-500">Orden de compra</p>
                <p class="font-medium">{{ c.orden_compra }}</p>
            </div>
            <div v-if="c.hash" class="sm:col-span-2">
                <p class="text-xs text-slate-500">Hash (firma digital)</p>
                <p class="font-mono text-xs break-all">{{ c.hash }}</p>
            </div>
            <p v-if="c.observaciones" class="sm:col-span-2 xl:col-span-4 text-slate-600">{{ c.observaciones }}</p>
        </section>

        <!-- Detalle: una línea por lote, como en la factura de la droguería -->
        <section class="bg-white rounded-xl border border-slate-200 mb-6">
            <DataTable :value="c.items" size="small">
                <Column header="Código">
                    <template #body="{ data }">{{ data.codigo }}</template>
                </Column>
                <Column header="Cant." class="text-right">
                    <template #body="{ data }">{{ cantidad(data.cantidad) }}</template>
                </Column>
                <Column field="unidad" header="Unid." />
                <Column header="Descripción">
                    <template #body="{ data }">
                        {{ data.descripcion }}
                        <Tag v-if="data.bonificacion" value="Bonificación" severity="success" class="ml-1" />
                    </template>
                </Column>
                <Column header="F. venc.">
                    <template #body="{ data }">{{ fecha(data.fecha_vencimiento).substring(3) }}</template>
                </Column>
                <Column field="numero_lote" header="N° lote" />
                <Column header="Precio unit." class="text-right">
                    <template #body="{ data }">{{ data.bonificacion ? '—' : precio(data.precio_unitario) }}</template>
                </Column>
                <Column header="Importe" class="text-right">
                    <template #body="{ data }">{{ soles(data.total) }}</template>
                </Column>
            </DataTable>
        </section>

        <div class="grid md:grid-cols-2 gap-6">
            <!-- Cuotas (venta al crédito) -->
            <section v-if="c.cuotas?.length" class="bg-white rounded-xl border border-slate-200 p-5 text-sm self-start">
                <h2 class="font-semibold mb-3">Cuotas</h2>
                <div v-for="q in c.cuotas" :key="q.id" class="flex justify-between py-1 border-b border-slate-100 last:border-0">
                    <span>Cuota {{ q.numero }} · vence {{ fecha(q.fecha_vencimiento) }}</span>
                    <span class="font-medium">{{ soles(q.monto) }}</span>
                </div>
                <!-- Lo que el cliente aún debe (se actualiza con cada cobro) -->
                <div class="flex justify-between pt-3 mt-2 border-t font-semibold">
                    <span>Saldo pendiente</span>
                    <span :class="Number(c.saldo) > 0 ? 'text-red-600' : 'text-emerald-700'">{{ Number(c.saldo) > 0 ? soles(c.saldo) : 'Cancelado' }}</span>
                </div>
                <Link v-if="!soloLectura" :href="`/cobranzas/${c.id}`" class="inline-block mt-2 text-emerald-700 hover:underline">Ver cobros y cuotas →</Link>
            </section>

            <!-- Pagos (venta al contado) -->
            <section v-else-if="c.pagos?.length" class="bg-white rounded-xl border border-slate-200 p-5 text-sm self-start">
                <h2 class="font-semibold mb-3">Pagos</h2>
                <div v-for="p in c.pagos" :key="p.id" class="flex justify-between gap-4 py-1 border-b border-slate-100 last:border-0">
                    <span>
                        {{ p.medio_nombre }}
                        <span v-if="p.referencia" class="text-slate-500"> · Op. {{ p.referencia }}</span>
                        <span v-if="p.recibido" class="text-slate-500"> · Recibido {{ soles(p.recibido) }} · Vuelto {{ soles(p.vuelto) }}</span>
                    </span>
                    <span class="font-medium whitespace-nowrap">{{ soles(p.monto) }}</span>
                </div>
            </section>
            <div v-else></div>

            <!-- Totales -->
            <section class="bg-white rounded-xl border border-slate-200 p-5 space-y-2 text-sm">
                <div class="flex justify-between"><span>Op. gravadas</span><span>{{ soles(c.op_gravadas) }}</span></div>
                <div class="flex justify-between"><span>Op. exoneradas</span><span>{{ soles(c.op_exoneradas) }}</span></div>
                <div v-if="Number(c.op_gratuitas)" class="flex justify-between text-slate-500"><span>Op. gratuitas</span><span>{{ soles(c.op_gratuitas) }}</span></div>
                <div class="flex justify-between"><span>IGV (18%)</span><span>{{ soles(c.igv) }}</span></div>
                <div class="flex justify-between text-lg font-semibold border-t pt-2"><span>Total</span><span>{{ soles(c.total) }}</span></div>
            </section>
        </div>

        <!-- Diálogo: comunicación de baja -->
        <Dialog v-model:visible="dialogoBaja" modal :header="`Dar de baja ${c.tipo_nombre} ${c.numero}`" :style="{ width: '32rem' }">
            <Message severity="warn" class="mb-4">
                <p class="text-sm">
                    Se anulará ante SUNAT. El comprobante dejará de tener validez, la mercadería volverá al stock y ya no contará en ventas ni caja.
                    <b>No se puede deshacer.</b>
                </p>
                <p v-if="limiteBaja" class="text-sm mt-1">Plazo para comunicar la baja: hasta el {{ fecha(limiteBaja) }}.</p>
            </Message>
            <div class="flex flex-col gap-1">
                <label class="text-sm">Motivo de la baja *</label>
                <Textarea v-model="formBaja.motivo" rows="2" maxlength="100" autoResize fluid placeholder="Ej.: Error en los datos del cliente" />
                <div class="flex justify-between">
                    <small class="text-red-600">{{ formBaja.errors.motivo }}</small>
                    <small class="text-slate-400">{{ formBaja.motivo.length }}/100</small>
                </div>
                <div class="flex flex-wrap gap-2 mt-1">
                                        <Button
                        v-for="m in MOTIVOS_BAJA"
                        :key="m"
                        :label="m"
                        size="small"
                        :severity="formBaja.motivo === m ? 'danger' : 'secondary'"
                        :outlined="formBaja.motivo !== m"
                        :icon="formBaja.motivo === m ? 'pi pi-check' : undefined"
                        @click="formBaja.motivo = m"
                    />
                </div>
            </div>
            <template #footer>
                <Button label="Cancelar" severity="secondary" text @click="dialogoBaja = false" />
                <Button label="Dar de baja" icon="pi pi-ban" severity="danger" :loading="formBaja.processing" :disabled="formBaja.motivo.trim().length < 3" @click="enviarBaja" />
            </template>
        </Dialog>
    </AppLayout>
</template>