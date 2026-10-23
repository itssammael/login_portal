<script setup>
import { Head, usePoll } from '@inertiajs/vue3';
import FeedbackAnalyticsReport from '@/Components/Feedback/FeedbackAnalyticsReport.vue';
const props = defineProps({ report: Object, data: Object, eventTitle: String, publishedAt: String });
usePoll(props.report.refresh_seconds * 1000);
</script>

<template>
    <main class="min-h-screen bg-cream-100 px-4 py-10 text-gray-900 sm:px-8"><Head :title="report.title" /><div class="mx-15 max-w-8xl"><FeedbackAnalyticsReport :report="report" :data="data" :event-title="eventTitle" /></div></main>
</template>
