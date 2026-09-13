<script setup>
import { computed, useSlots } from 'vue';
import SectionTitle from './SectionTitle.vue';

defineEmits(['submitted']);

const hasActions = computed(() => !! useSlots().actions);
</script>

<template>
    <div class="md:grid md:grid-cols-3 md:gap-6">
        <SectionTitle>
            <template #title>
                <slot name="title" />
            </template>
            <template #description>
                <slot name="description" />
            </template>
        </SectionTitle>

        <div class="mt-5 md:mt-0 md:col-span-2">
            <form @submit.prevent="$emit('submitted')">
                <div
                    class="px-4 py-5 bg-cream-200 border border-cream-500/50 sm:p-6 shadow-sm"
                    :class="hasActions ? 'sm:rounded-tl-2xl sm:rounded-tr-2xl' : 'sm:rounded-2xl'"
                >
                    <div class="grid grid-cols-6 gap-6">
                        <slot name="form" />
                    </div>
                </div>

                <div v-if="hasActions" class="flex items-center justify-end px-4 py-3 bg-cream-200/90 border-x border-b border-cream-500/50 text-end sm:px-6 shadow-sm sm:rounded-bl-2xl sm:rounded-br-2xl">
                    <slot name="actions" />
                </div>
            </form>
        </div>
    </div>
</template>
