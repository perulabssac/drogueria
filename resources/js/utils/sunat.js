// Cómo se muestra cada estado SUNAT de un comprobante

export const ESTADOS_SUNAT = {
    pendiente: { texto: 'Pendiente', severidad: 'secondary', icono: 'pi pi-clock' },
    enviado: { texto: 'En proceso', severidad: 'info', icono: 'pi pi-spin pi-spinner' },
    aceptado: { texto: 'Aceptado', severidad: 'success', icono: 'pi pi-check-circle' },
    observado: { texto: 'Aceptado con obs.', severidad: 'warn', icono: 'pi pi-exclamation-circle' },
    rechazado: { texto: 'Rechazado', severidad: 'danger', icono: 'pi pi-times-circle' },
    error: { texto: 'Sin enviar', severidad: 'danger', icono: 'pi pi-wifi' },
    anulado: { texto: 'Anulado (baja)', severidad: 'secondary', icono: 'pi pi-ban' },
    interno: { texto: 'Interno', severidad: 'contrast', icono: 'pi pi-file' },
};

export const estadoSunat = (estado) => ESTADOS_SUNAT[estado] ?? { texto: estado, severidad: 'secondary', icono: '' };