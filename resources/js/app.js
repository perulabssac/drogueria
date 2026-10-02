import '../css/app.css';
import 'primeicons/primeicons.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import PrimeVue from 'primevue/config';
import Aura from '@primeuix/themes/aura';
import { definePreset } from '@primeuix/themes';
import ToastService from 'primevue/toastservice';
import ConfirmationService from 'primevue/confirmationservice';
import Tooltip from 'primevue/tooltip';

// Tema Aura con color principal verde (rubro farmacéutico)
const Tema = definePreset(Aura, {
    semantic: {
        primary: {
            50: '{emerald.50}', 100: '{emerald.100}', 200: '{emerald.200}', 300: '{emerald.300}',
            400: '{emerald.400}', 500: '{emerald.500}', 600: '{emerald.600}', 700: '{emerald.700}',
            800: '{emerald.800}', 900: '{emerald.900}', 950: '{emerald.950}',
        },
    },
});

createInertiaApp({
    title: (title) => (title ? `${title} · Droguería` : 'Droguería'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(PrimeVue, {
                theme: { preset: Tema, options: { darkModeSelector: '.app-dark' } },
                locale: {
                    accept: 'Sí', reject: 'No', choose: 'Elegir', upload: 'Subir', cancel: 'Cancelar',
                    dayNames: ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'],
                    dayNamesShort: ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'],
                    dayNamesMin: ['D', 'L', 'M', 'X', 'J', 'V', 'S'],
                    monthNames: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'],
                    monthNamesShort: ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'],
                    today: 'Hoy', clear: 'Limpiar', firstDayOfWeek: 1, dateFormat: 'dd/mm/yy',
                    emptyMessage: 'Sin resultados', emptySearchMessage: 'Sin resultados',
                },
            })
            .use(ToastService)
            .use(ConfirmationService)
            .directive('tooltip', Tooltip)
            .mount(el);
    },
    progress: { color: '#10b981' },
});