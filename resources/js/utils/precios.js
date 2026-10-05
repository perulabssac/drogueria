// Fórmulas de precio con margen SOBRE EL COSTO (iguales a app/Support/Precios.php)
//   precio sin IGV = costo × (1 + margen %)
//   precio con IGV = precio sin IGV × 1.18 (solo productos gravados)

export const TASA_IGV = 0.18;

/** Redondea hacia arriba al múltiplo indicado: 14.573 con 0.10 → 14.60 */
export const redondear = (precio, redondeo) => {
    if (!redondeo || redondeo <= 0) return Math.round(precio * 100) / 100;
    const veces = Math.ceil(Math.round((precio / redondeo) * 1e6) / 1e6);
    return Math.round(veces * redondeo * 100) / 100;
};

/** Precio de venta con IGV para un costo sin IGV y un margen sobre el costo. */
export const precioSugerido = (costo, margen, gravado, redondeo = 0.1) => {
    if (!costo || costo <= 0 || margen === null || margen === undefined) return null;
    return redondear(costo * (1 + margen / 100) * (gravado ? 1 + TASA_IGV : 1), redondeo);
};

/** Ganancia y márgenes de un precio con IGV frente a un costo sin IGV. */
export const analizarPrecio = (precioConIgv, costo, gravado) => {
    if (!precioConIgv || !costo) return null;
    const sinIgv = gravado ? precioConIgv / (1 + TASA_IGV) : precioConIgv;
    const ganancia = sinIgv - costo;
    return {
        costoConIgv: gravado ? costo * (1 + TASA_IGV) : costo,
        precioSinIgv: sinIgv,
        ganancia,
        margenCosto: (ganancia / costo) * 100,
        margenVenta: sinIgv > 0 ? (ganancia / sinIgv) * 100 : null,
    };
};