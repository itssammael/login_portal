if (typeof window !== 'undefined') {
    window.addEventListener('unhandledrejection', (event) => {
        const msg = event?.reason?.message || (typeof event?.reason === 'string' ? event.reason : '');
        if (
            msg.includes('message channel closed before a response was received') ||
            msg.includes('A listener indicated an asynchronous response by returning true')
        ) {
            event.preventDefault();
            event.stopImmediatePropagation?.();
        }
    });
}

import '../css/app.css';
import './echo';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import ToastContainer from '@/Components/ToastContainer.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        delete el.dataset.page;
        return createApp({
            render: () => h('div', [h(App, props), h(ToastContainer)]),
        })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);

    },
    progress: {
        color: '#4F46E5',
    },
});
