<script setup>
import { onMounted, ref } from 'vue';

defineProps({
    modelValue: String,
});

defineEmits(['update:modelValue']);

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        ref="input"
        class="bg-[#fffef9] border-cream-500 text-gray-900 focus:border-forest-600 focus:ring-forest-600 rounded-xl shadow-xs"
        :value="modelValue"
        @input="$emit('update:modelValue', $event.target.value)"
    >
</template>
