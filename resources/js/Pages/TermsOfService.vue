<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';

defineProps({
    terms: String,
});
</script>

<template>
    <Head title="Terms of Service" />

    <div class="font-sans text-gray-900 antialiased">
        <div class="pt-8 pb-12 bg-cream-300 min-h-screen">
            <div class="flex flex-col items-center pt-6 sm:pt-0">
                <div>
                    <AuthenticationCardLogo />
                </div>

                <div class="w-full sm:max-w-2xl mt-6 p-8 bg-cream-200 border border-cream-500/50 shadow-sm overflow-hidden sm:rounded-2xl prose prose-neutral" v-html="terms" />
            </div>
        </div>
    </div>
</template>
