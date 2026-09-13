import { ref } from 'vue';

const toasts = ref([]);
let nextId = 1;

export function useToast() {
    const add = ({ title = '', message = '', type = 'info', duration = 4500 }) => {
        const id = nextId++;
        const toast = {
            id,
            title,
            message,
            type,
            duration,
            createdAt: Date.now(),
        };

        toasts.value.push(toast);

        if (duration > 0) {
            setTimeout(() => {
                remove(id);
            }, duration);
        }

        return id;
    };

    const remove = (id) => {
        const index = toasts.value.findIndex((t) => t.id === id);
        if (index !== -1) {
            toasts.value.splice(index, 1);
        }
    };

    const success = (message, title = 'Success') => add({ title, message, type: 'success' });
    const error = (message, title = 'Error') => add({ title, message, type: 'error' });
    const warning = (message, title = 'Warning') => add({ title, message, type: 'warning' });
    const info = (message, title = 'Notification') => add({ title, message, type: 'info' });

    return {
        toasts,
        add,
        remove,
        success,
        error,
        warning,
        info,
    };
}
