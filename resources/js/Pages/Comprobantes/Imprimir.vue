<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { fecha, cantidad } from '@/utils/formato';

const props = defineProps({
    comprobante: Object,
    empresa: Object,
    formato: String, // 'a4' | 'ticket'
    autoImprimir: Boolean,
    letras: String,
    qr: String, // null en la nota de venta
});

const c = computed(() => props.comprobante);
const e = computed(() => props.empresa);
const esTicket = computed(() => props.formato === 'ticket');
const esInterno = computed(() => c.value.tipo_comprobante === 'NV');

const TITULOS = {
    '01': 'FACTURA ELECTRÓNICA',
    '03': 'BOLETA DE VENTA ELECTRÓNICA',
    '07': 'NOTA DE CRÉDITO ELECTRÓNICA',
    '08': 'NOTA DE DÉBITO ELECTRÓNICA',
    NV: 'NOTA DE VENTA',
};
const DOCUMENTOS = { 6: 'RUC', 1: 'DNI', 4: 'C.E.', 7: 'PASAPORTE', 0: 'DOC.' };

const titulo = computed(() => TITULOS[c.value.tipo_comprobante] ?? c.value.tipo_nombre);
const docCliente = computed(() => DOCUMENTOS[c.value.cliente.tipo_documento] ?? 'DOC.');
const hora = computed(() => String(c.value.fecha_emision).substring(11, 16));
const direccionEmpresa = computed(() => [e.value.direccion, e.value.distrito, e.value.provincia, e.value.departamento].filter(Boolean).join(' - '));
const hayGratuitas = computed(() => Number(c.value.op_gratuitas) > 0);

const dinero = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const monto = (v) => dinero.format(Number(v ?? 0));
const pu = (v) => Number(v ?? 0).toFixed(3);
const venc = (v) => fecha(v).substring(3); // MM/AAAA, como en las facturas de droguería

// QR en SVG (nítido al imprimir en cualquier impresora)
const qrSvg = ref('');

// Ancho del papel de la ticketera: 58 mm o 80 mm (se recuerda en esta computadora)
const leerAncho = () => {
    try {
        return localStorage.getItem('anchoTicket') === '58' ? 58 : 80;
    } catch {
        return 80;
    }
};
const ancho = ref(leerAncho());
const es58 = computed(() => ancho.value === 58);
// Ancho imprimible real: el cabezal no llega a los bordes del rollo
// (rollo de 58 mm → 48 mm útiles; rollo de 80 mm → 72 mm útiles)
const anchoUtil = computed(() => (es58.value ? 48 : 72));
const cambiarAncho = (mm) => {
    ancho.value = mm;
    try {
        localStorage.setItem('anchoTicket', String(mm));
    } catch {
        /* sin almacenamiento: solo aplica a esta vista */
    }
};

// Tamaño de hoja.
// - A4: hoja A4 con margen.
// - Ticket: sin márgenes y sin tamaño fijo, para que el contenido empiece arriba del papel
//   que tiene configurado la ticketera (el largo lo decide su driver).
const estiloPagina = document.createElement('style');
const actualizarPagina = () => {
    estiloPagina.textContent = esTicket.value
        ? '@page { margin: 0; } html, body { background: #fff; margin: 0; }'
        : '@page { size: A4; margin: 10mm; } html, body { background: #fff; }';
};

onMounted(async () => {
    if (props.qr) {
        qrSvg.value = await QRCode.toString(props.qr, { type: 'svg', margin: 0, errorCorrectionLevel: 'Q' });
    }
    actualizarPagina();
    document.head.appendChild(estiloPagina);

    if (props.autoImprimir) setTimeout(() => window.print(), 300);
});
onBeforeUnmount(() => estiloPagina.remove());

const imprimir = () => window.print();
// Recarga completa para aplicar el nuevo tamaño de hoja
const cambiarFormato = (f) => window.location.replace(`/comprobantes/${c.value.id}/imprimir?formato=${f}`);
const cerrar = () => (window.opener ? window.close() : window.location.assign(`/comprobantes/${c.value.id}`));
</script>

<template>
    <Head :title="`${c.numero} - imprimir`" />

    <!-- Barra de herramientas (no se imprime) -->
    <div class="print:hidden sticky top-0 z-10 bg-slate-800 text-white">
        <div class="max-w-4xl mx-auto px-4 py-2 flex flex-wrap items-center gap-2 text-sm">
            <span class="font-semibold mr-2">{{ c.tipo_nombre }} {{ c.numero }}</span>
            <button class="px-3 py-1.5 rounded" :class="!esTicket ? 'bg-emerald-600' : 'bg-slate-700 hover:bg-slate-600'" @click="cambiarFormato('a4')">
                <i class="pi pi-file mr-1"></i>A4
            </button>
            <button class="px-3 py-1.5 rounded" :class="esTicket ? 'bg-emerald-600' : 'bg-slate-700 hover:bg-slate-600'" @click="cambiarFormato('ticket')">
                <i class="pi pi-receipt mr-1"></i>Ticket
            </button>
            <template v-if="esTicket">
                <span class="ml-2 text-slate-300">Papel:</span>
                <button v-for="mm in [58, 80]" :key="mm" class="px-2 py-1.5 rounded" :class="ancho === mm ? 'bg-emerald-600' : 'bg-slate-700 hover:bg-slate-600'" @click="cambiarAncho(mm)">
                    {{ mm }} mm
                </button>
            </template>
            <div class="ml-auto flex gap-2">
                <button class="px-3 py-1.5 rounded bg-emerald-600 hover:bg-emerald-500" @click="imprimir"><i class="pi pi-print mr-1"></i>Imprimir / PDF</button>
                <button class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600" @click="cerrar"><i class="pi pi-times mr-1"></i>Cerrar</button>
            </div>
        </div>
    </div>

    <div class="bg-slate-200 print:bg-white min-h-screen print:min-h-0 py-6 print:py-0">
        <!-- ======================= A4 ======================= -->
        <article v-if="!esTicket" class="hoja-a4 bg-white mx-auto shadow print:shadow-none text-[11px] text-slate-900 leading-snug">
            <!-- Cabecera: emisor + recuadro del comprobante -->
            <header class="flex gap-6 items-start">
                <div class="flex-1">
                    <h1 class="text-lg font-bold">{{ e.nombre_comercial || e.razon_social }}</h1>
                    <p v-if="e.nombre_comercial" class="font-semibold">{{ e.razon_social }}</p>
                    <p v-if="e.giro" class="italic text-slate-700">{{ e.giro }}</p>
                    <p>{{ direccionEmpresa }}</p>
                    <p v-if="c.sucursal && c.sucursal.direccion !== e.direccion">Establecimiento: {{ c.sucursal.direccion }}</p>
                    <p v-if="e.telefono || e.email">
                        <span v-if="e.telefono">Telf.: {{ e.telefono }}</span>
                        <span v-if="e.telefono && e.email"> · </span>
                        <span v-if="e.email">{{ e.email }}</span>
                    </p>
                </div>
                <div class="w-64 border-2 border-slate-900 rounded-md text-center py-3 px-2">
                    <p class="font-bold text-sm">R.U.C. {{ e.ruc }}</p>
                    <p class="font-bold text-sm my-1 bg-slate-900 text-white py-1">{{ titulo }}</p>
                    <p class="font-bold text-base">{{ c.numero }}</p>
                </div>
            </header>

            <!-- Datos del cliente y de la operación -->
            <section class="mt-4 border border-slate-400 rounded-md p-3 grid grid-cols-[1fr_auto] gap-x-6 gap-y-0.5">
                <div class="space-y-0.5">
                    <p><b class="inline-block w-24">Cliente:</b>{{ c.cliente.razon_social }}</p>
                    <p><b class="inline-block w-24">{{ docCliente }}:</b>{{ c.cliente.numero_documento }}</p>
                    <p v-if="c.cliente.direccion"><b class="inline-block w-24">Dirección:</b>{{ c.cliente.direccion }}</p>
                    <p v-if="c.vendedor"><b class="inline-block w-24">Vendedor:</b>{{ c.vendedor.name }}</p>
                </div>
                <div class="space-y-0.5">
                    <p><b class="inline-block w-28">Fecha emisión:</b>{{ fecha(c.fecha_emision) }} {{ hora }}</p>
                    <p v-if="!c.referencia"><b class="inline-block w-28">Condición:</b>{{ c.forma_pago === 'credito' ? 'CRÉDITO' : 'CONTADO' }}</p>
                    <p v-if="c.fecha_vencimiento"><b class="inline-block w-28">Vencimiento:</b>{{ fecha(c.fecha_vencimiento) }}</p>
                    <p><b class="inline-block w-28">Moneda:</b>{{ c.moneda === 'USD' ? 'DÓLARES' : 'SOLES' }}</p>
                    <p v-if="c.guia_remision"><b class="inline-block w-28">Guía remisión:</b>{{ c.guia_remision }}</p>
                    <p v-if="c.orden_compra"><b class="inline-block w-28">Orden compra:</b>{{ c.orden_compra }}</p>
                </div>
                <p v-if="c.referencia" class="col-span-2 mt-1">
                    <b>Documento que modifica:</b> {{ c.referencia.tipo_nombre }} {{ c.referencia.numero }}
                    <span v-if="c.motivo_descripcion"> · Motivo: {{ c.motivo_descripcion }}</span>
                </p>
            </section>

            <!-- Detalle -->
            <table class="w-full mt-4 border-collapse">
                <thead>
                    <tr class="bg-slate-900 text-white">
                        <th class="p-1.5 text-right w-12">Cant.</th>
                        <th class="p-1.5 text-left w-12">Unid.</th>
                        <th class="p-1.5 text-left w-16">Código</th>
                        <th class="p-1.5 text-left">Descripción</th>
                        <th class="p-1.5 text-left w-20">Lote</th>
                        <th class="p-1.5 text-left w-16 whitespace-nowrap">F. venc.</th>
                        <th class="p-1.5 text-right w-20">P. unit.</th>
                        <th class="p-1.5 text-right w-20">Importe</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="i in c.items" :key="i.id" class="border-b border-slate-300 align-top">
                        <td class="p-1.5 text-right">{{ cantidad(i.cantidad) }}</td>
                        <td class="p-1.5">{{ i.unidad }}</td>
                        <td class="p-1.5">{{ i.codigo }}</td>
                        <td class="p-1.5">
                            {{ i.descripcion }}
                            <span v-if="i.bonificacion" class="font-semibold"> (BONIFICACIÓN)</span>
                        </td>
                        <td class="p-1.5">{{ i.numero_lote }}</td>
                        <td class="p-1.5">{{ venc(i.fecha_vencimiento) }}</td>
                        <td class="p-1.5 text-right">{{ i.bonificacion ? '0.000' : pu(i.precio_unitario) }}</td>
                        <td class="p-1.5 text-right">{{ monto(i.total) }}</td>
                    </tr>
                </tbody>
            </table>

            <!-- Importe en letras + totales -->
            <section class="mt-3 flex gap-6 items-start">
                <div class="flex-1 space-y-2">
                    <p class="border border-slate-400 rounded-md p-2"><b>SON:</b> {{ letras }}</p>
                    <p v-if="hayGratuitas" class="font-semibold">TRANSFERENCIA GRATUITA DE UN BIEN Y/O SERVICIO PRESTADO GRATUITAMENTE</p>

                    <!-- Cuotas (crédito) -->
                    <div v-if="c.cuotas?.length" class="border border-slate-400 rounded-md p-2">
                        <p class="font-bold mb-1">Información del crédito</p>
                        <p v-for="q in c.cuotas" :key="q.id">Cuota {{ q.numero }}: vence {{ fecha(q.fecha_vencimiento) }} · S/ {{ monto(q.monto) }}</p>
                    </div>

                    <!-- Pagos (contado) -->
                    <div v-if="c.pagos?.length" class="border border-slate-400 rounded-md p-2">
                        <p class="font-bold mb-1">Forma de pago</p>
                        <p v-for="p in c.pagos" :key="p.id">
                            {{ p.medio_nombre }}: S/ {{ monto(p.monto) }}
                            <span v-if="p.referencia"> · Op. {{ p.referencia }}</span>
                            <span v-if="p.recibido"> · Recibido S/ {{ monto(p.recibido) }} · Vuelto S/ {{ monto(p.vuelto) }}</span>
                        </p>
                    </div>

                    <p v-if="c.observaciones"><b>Observaciones:</b> {{ c.observaciones }}</p>
                </div>

                <table class="w-64 border-collapse">
                    <tbody>
                        <tr><td class="py-0.5">Op. gravadas</td><td class="text-right">S/ {{ monto(c.op_gravadas) }}</td></tr>
                        <tr><td class="py-0.5">Op. exoneradas</td><td class="text-right">S/ {{ monto(c.op_exoneradas) }}</td></tr>
                        <tr v-if="Number(c.op_inafectas)"><td class="py-0.5">Op. inafectas</td><td class="text-right">S/ {{ monto(c.op_inafectas) }}</td></tr>
                        <tr v-if="hayGratuitas"><td class="py-0.5">Op. gratuitas</td><td class="text-right">S/ {{ monto(c.op_gratuitas) }}</td></tr>
                        <tr><td class="py-0.5">IGV (18%)</td><td class="text-right">S/ {{ monto(c.igv) }}</td></tr>
                        <tr class="border-t-2 border-slate-900 font-bold text-sm">
                            <td class="pt-1">IMPORTE TOTAL</td><td class="text-right pt-1">S/ {{ monto(c.total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Pie: QR, hash, cuentas bancarias y leyenda -->
            <footer class="mt-5 pt-3 border-t border-slate-400 flex gap-4 items-start">
                <div v-if="qrSvg" class="w-24 h-24 shrink-0" v-html="qrSvg"></div>
                <div class="flex-1 space-y-1">
                    <p v-if="c.hash"><b>Resumen (hash):</b> <span class="font-mono break-all">{{ c.hash }}</span></p>
                    <p v-if="!esInterno">
                        Representación impresa de la {{ titulo }}. Puede consultarla en
                        <b>www.sunat.gob.pe</b>.
                    </p>
                    <p v-else class="font-semibold">
                        DOCUMENTO DE USO INTERNO. NO ES COMPROBANTE DE PAGO. Solicite su boleta o factura.
                    </p>
                    <div v-if="e.cuentas_bancarias" class="mt-2">
                        <p class="font-bold">Cuentas bancarias</p>
                        <p class="whitespace-pre-line">{{ e.cuentas_bancarias }}</p>
                    </div>
                </div>
            </footer>
        </article>

        <!-- ======================= TICKET 58 / 80 mm ======================= -->
        <article
            v-else
            class="ticket bg-white mx-auto shadow print:shadow-none leading-tight text-black font-mono font-bold"
            :class="es58 ? 'text-[9px]' : 'text-[11px]'"
            :style="{ width: anchoUtil - 3 + 'mm' }"
        >
            <div class="text-center">
                <p class="font-bold" :class="es58 ? 'text-[11px]' : 'text-[13px]'">{{ e.nombre_comercial || e.razon_social }}</p>
                <p v-if="e.nombre_comercial">{{ e.razon_social }}</p>
                <p v-if="e.giro" class="font-normal">{{ e.giro }}</p>
                <p>RUC {{ e.ruc }}</p>
                <p>{{ direccionEmpresa }}</p>
                <p v-if="e.telefono">Telf.: {{ e.telefono }}</p>
                <p class="mt-2 font-bold">{{ titulo }}</p>
                <p class="font-bold" :class="es58 ? 'text-[11px]' : 'text-[13px]'">{{ c.numero }}</p>
            </div>

            <div class="raya"></div>
            <p>Fecha: {{ fecha(c.fecha_emision) }} {{ hora }}</p>
            <p>Cliente: {{ c.cliente.razon_social }}</p>
            <p>{{ docCliente }}: {{ c.cliente.numero_documento }}</p>
            <p v-if="c.cliente.direccion">Dir.: {{ c.cliente.direccion }}</p>
            <p v-if="!c.referencia">Condición: {{ c.forma_pago === 'credito' ? 'CRÉDITO' : 'CONTADO' }}</p>
            <p v-if="c.vendedor">Vendedor: {{ c.vendedor.name }}</p>
            <!-- Nota de crédito: qué comprobante modifica y por qué -->
            <template v-if="c.referencia">
                <p>Modifica: {{ c.referencia.tipo_nombre }} {{ c.referencia.numero }}</p>
                <p>Motivo: {{ c.motivo_descripcion }}</p>
            </template>

            <div class="raya"></div>
            <div v-for="i in c.items" :key="i.id" class="mb-1">
                <p>{{ i.descripcion }}<span v-if="i.bonificacion"> (BONIF.)</span></p>
                <p class="text-[0.9em]">Lote {{ i.numero_lote }} · Venc. {{ venc(i.fecha_vencimiento) }}</p>
                <p class="flex justify-between">
                    <span>{{ cantidad(i.cantidad) }} {{ i.unidad }} x {{ i.bonificacion ? '0.000' : pu(i.precio_unitario) }}</span>
                    <span>{{ monto(i.total) }}</span>
                </p>
            </div>

            <div class="raya"></div>
            <p class="flex justify-between"><span>Op. gravadas</span><span>{{ monto(c.op_gravadas) }}</span></p>
            <p class="flex justify-between"><span>Op. exoneradas</span><span>{{ monto(c.op_exoneradas) }}</span></p>
            <p v-if="hayGratuitas" class="flex justify-between"><span>Op. gratuitas</span><span>{{ monto(c.op_gratuitas) }}</span></p>
            <p class="flex justify-between"><span>IGV 18%</span><span>{{ monto(c.igv) }}</span></p>
            <p class="flex justify-between font-bold mt-1" :class="es58 ? 'text-[11px]' : 'text-[13px]'"><span>TOTAL S/</span><span>{{ monto(c.total) }}</span></p>
            <p class="mt-1">SON: {{ letras }}</p>

            <template v-if="c.pagos?.length">
                <div class="raya"></div>
                <p v-for="p in c.pagos" :key="p.id" class="flex justify-between">
                    <span>{{ p.medio_nombre }}<span v-if="p.referencia"> #{{ p.referencia }}</span></span><span>{{ monto(p.monto) }}</span>
                </p>
                <template v-for="p in c.pagos" :key="'v' + p.id">
                    <p v-if="p.recibido" class="flex justify-between"><span>Recibido</span><span>{{ monto(p.recibido) }}</span></p>
                    <p v-if="p.recibido" class="flex justify-between font-bold"><span>Vuelto</span><span>{{ monto(p.vuelto) }}</span></p>
                </template>
            </template>

            <template v-if="c.cuotas?.length">
                <div class="raya"></div>
                <p v-for="q in c.cuotas" :key="q.id">Cuota {{ q.numero }} vence {{ fecha(q.fecha_vencimiento) }}: {{ monto(q.monto) }}</p>
            </template>

            <div class="raya"></div>
            <div v-if="qrSvg" class="mx-auto my-2" :class="es58 ? 'w-24 h-24' : 'w-28 h-28'" v-html="qrSvg"></div>
            <p v-if="c.hash" class="text-center text-[0.9em] break-all">Hash: {{ c.hash }}</p>
            <p v-if="!esInterno" class="text-center text-[0.9em] mt-1">Representación impresa de la {{ titulo }}. Consulte en www.sunat.gob.pe</p>
            <p v-else class="text-center font-bold mt-1">DOCUMENTO INTERNO. NO ES COMPROBANTE DE PAGO.</p>
            <p v-if="!c.referencia" class="text-center mt-2">¡Gracias por su compra!</p>
        </article>
    </div>
</template>

<style scoped>
.hoja-a4 {
    width: 210mm;
    min-height: 297mm;
    padding: 12mm;
}
/* El ancho del ticket es el área imprimible (48 o 72 mm); en pantalla se ve con
   margen alrededor para simular el rollo, al imprimir el margen desaparece */
.ticket {
    /* Negro puro: las impresoras térmicas imprimen los grises muy tenues */
    color: #000;
    box-sizing: content-box;
    padding: 4mm 5mm;
    overflow-wrap: anywhere;
}
.raya {
    border-top: 1px dashed #000;
    margin: 6px 0;
}
:deep(svg) {
    width: 100%;
    height: 100%;
}
@media print {
    .hoja-a4 {
        width: auto;
        min-height: 0;
        padding: 0;
    }
    .ticket {
        margin: 0;
        padding: 0 1.5mm 4mm 1.5mm;
    }
}
</style>