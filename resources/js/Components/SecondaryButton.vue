<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button :type="type" class="inline-flex items-center px-4 py-2 bg-cream-200 border border-cream-500 rounded-xl font-semibold text-xs text-gray-800 uppercase tracking-widest shadow-xs hover:bg-cream-300 focus:outline-none focus:ring-2 focus:ring-forest-600 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150">
        <slot />
    </button>
</template>
