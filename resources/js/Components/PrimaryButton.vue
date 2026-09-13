<script setup>
defineProps({
    type: {
        type: String,
        default: 'submit',
    },
});
</script>

<template>
    <button :type="type" class="inline-flex items-center px-4 py-2 bg-forest-900 border border-transparent rounded-xl font-semibold text-xs text-white uppercase tracking-widest hover:bg-forest-950 focus:bg-forest-950 active:bg-forest-950 focus:outline-none focus:ring-2 focus:ring-forest-600 focus:ring-offset-2 focus:ring-offset-cream-300 disabled:opacity-50 transition ease-in-out duration-150">
        <slot />
    </button>
</template>
