<template>
    <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-cream-300">
        <div>
            <slot name="logo" />
        </div>

        <div class="w-full sm:max-w-md mt-6 px-6 py-6 bg-cream-200 border border-cream-500/50 shadow-md overflow-hidden sm:rounded-2xl">
            <slot />
        </div>
    </div>
</template>
