// Utilidades para llamar a las rutas JSON de Laravel (búsquedas rápidas)

/** GET que devuelve JSON, enviando la cookie de sesión. */
export async function obtenerJson(url, parametros = {}) {
    const query = new URLSearchParams(parametros).toString();
    const respuesta = await fetch(query ? `${url}?${query}` : url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!respuesta.ok) throw new Error(`Error ${respuesta.status} al consultar ${url}`);
    return respuesta.json();
}

/** Date de JavaScript => '2026-12-31' (sin problemas de zona horaria) */
export function aFechaISO(fecha) {
    if (!fecha) return null;
    const d = new Date(fecha);
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mm}-${dd}`;
}

/** Último día del mes de una fecha: los vencimientos vienen como MM/AAAA */
export function finDeMes(fecha) {
    if (!fecha) return null;
    const d = new Date(fecha);
    return new Date(d.getFullYear(), d.getMonth() + 1, 0);
}

/**
 * POST que envía y recibe JSON (ej. registrar un cliente sin salir de la venta).
 * Laravel exige el token CSRF: lo tomamos de la cookie XSRF-TOKEN.
 * Si hay errores de validación (422), lanza un objeto { errores: {...} }.
 */
export async function enviarJson(url, datos) {
    const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
    const respuesta = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': token,
        },
        credentials: 'same-origin',
        body: JSON.stringify(datos),
    });
    const cuerpo = await respuesta.json().catch(() => ({}));
    if (respuesta.status === 422) throw { errores: cuerpo.errors ?? {} };
    if (!respuesta.ok) throw new Error(cuerpo.message ?? `Error ${respuesta.status}`);
    return cuerpo;
}