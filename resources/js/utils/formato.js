// Funciones de formato reutilizables en todas las pantallas

const moneda = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' });
const numero = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 2 });

/** Montos totales: S/ 1,084.90 */
export const soles = (valor) => moneda.format(Number(valor ?? 0));

/** Precios unitarios con 3 decimales, como en las facturas de droguería: S/ 23.000 */
export const precio = (valor) => 'S/ ' + Number(valor ?? 0).toFixed(3);

export const cantidad = (valor) => numero.format(Number(valor ?? 0));

/**
 * Muestra el stock (guardado en unidades mínimas) en presentaciones.
 * Ej: 1540 unidades de una caja x 100 => "15 CJA + 40 TAB"; 25 frascos => "25 FCO"
 */
export const stock = (valor, producto) => {
    const total = Number(valor ?? 0);
    const factor = producto.fraccionable ? Number(producto.unidades_por_presentacion) : 1;
    if (factor <= 1) return `${cantidad(total)} ${producto.unidad_venta}`;

    const enteras = Math.floor(total / factor);
    const resto = Math.round((total - enteras * factor) * 100) / 100;
    const texto = `${cantidad(enteras)} ${producto.unidad_venta}`;
    return resto > 0 ? `${texto} + ${cantidad(resto)} ${producto.unidad_fraccion}` : texto;
};

/** '2026-12-31' o '2026-12-31T00:00:00.000000Z' => '31/12/2026' */
export const fecha = (valor) => {
    if (!valor) return '';
    const [a, m, d] = String(valor).substring(0, 10).split('-');
    return `${d}/${m}/${a}`;
};

/** Días que faltan para una fecha (negativo si ya pasó). */
export const diasHasta = (valor) => {
    if (!valor) return null;
    const hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    const objetivo = new Date(String(valor).substring(0, 10) + 'T00:00:00');
    return Math.round((objetivo - hoy) / 86400000);
};