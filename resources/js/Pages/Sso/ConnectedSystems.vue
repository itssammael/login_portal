<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    systems: {
        type: Array,
        default: () => [],
    },
    pendingClient: {
        type: String,
        default: null,
    },
    flashMessage: {
        type: String,
        default: null,
    },
});

const bindingSystem = ref(null);

const form = useForm({
    username: '',
    password: '',
});

const openBindModal = (system) => {
    bindingSystem.value = system;
    form.reset();
    form.clearErrors();
};

const closeBindModal = () => {
    bindingSystem.value = null;
    form.reset();
    form.clearErrors();
};

const submitBind = () => {
    if (!bindingSystem.value) return;

    form.post(route('sso.connected-systems.bind', bindingSystem.value.client_id), {
        preserveScroll: true,
        onSuccess: () => {
            closeBindModal();
        },
    });
};

const unbindSystem = (system) => {
    if (confirm(`Are you sure you want to unbind your account for ${system.name}?`)) {
        router.delete(route('sso.connected-systems.unbind', system.client_id), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <AppLayout title="Connected Systems">
        <div class="min-h-full bg-cream-300 p-4 sm:p-6 lg:p-8">
            <div class="max-w-6xl mx-auto">
                
                <!-- Page Header -->
                <div class="mb-8 bg-cream-100 rounded-3xl p-6 sm:p-8 shadow-xs border border-cream-500/50 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center space-x-3 mb-2">
                            <div class="p-2.5 bg-amber-500/20 text-amber-950 rounded-2xl">
                                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                                </svg>
                            </div>
                            <h1 class="text-2xl sm:text-3xl font-black text-gray-950 tracking-tight">
                                Connected Systems & Account Binding
                            </h1>
                        </div>
                        <p class="text-sm text-gray-700 max-w-2xl font-medium leading-relaxed">
                            To use Single Sign-On (SSO) or launch systems directly from Login Portal, you must bind your local account credentials for each system first.
                        </p>
                    </div>

                    <div class="shrink-0">
                        <span class="inline-flex items-center px-3.5 py-1.5 rounded-full text-xs font-extrabold bg-forest-900 text-white shadow-xs tracking-wide uppercase">
                            CENTRAL IDENTITY
                        </span>
                    </div>
                </div>

                <!-- Flash Warning Message for Pending Bind -->
                <div v-if="flashMessage || pendingClient" class="mb-6 p-4 rounded-2xl bg-amber-100 border border-amber-300 text-amber-950 text-sm font-semibold flex items-center space-x-3 shadow-xs">
                    <svg class="size-5 shrink-0 text-amber-800" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                    <div>
                        {{ flashMessage || 'Account binding is required before you can launch Single Sign-On for the selected system.' }}
                    </div>
                </div>

                <!-- Systems Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div
                        v-for="system in systems"
                        :key="system.client_id"
                        class="bg-[#fffef7] rounded-3xl p-6 border shadow-xs transition duration-200 flex flex-col justify-between"
                        :class="[
                            pendingClient === system.client_id
                                ? 'border-amber-500 ring-2 ring-amber-400/50'
                                : 'border-cream-500/60 hover:shadow-md'
                        ]"
                    >
                        <div>
                            <!-- Header & Status -->
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center space-x-3">
                                    <div class="size-12 rounded-2xl flex items-center justify-center font-black text-xl" :class="system.client_id.includes('lfews') ? 'bg-blue-500/15 text-blue-900' : 'bg-amber-500/15 text-amber-900'">
                                        {{ system.client_id.includes('lfews') ? '🌊' : '📋' }}
                                    </div>
                                    <div>
                                        <h2 class="text-lg font-extrabold text-gray-950 leading-tight">
                                            {{ system.name }}
                                        </h2>
                                        <span class="text-xs text-gray-500 font-medium">
                                            {{ system.client_id }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Status Badge -->
                                <span
                                    v-if="system.is_bound"
                                    class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-extrabold bg-emerald-100 text-emerald-900 border border-emerald-300"
                                >
                                    <span class="size-2 rounded-full bg-emerald-600 animate-pulse"></span>
                                    <span>BOUND</span>
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-extrabold bg-amber-100 text-amber-900 border border-amber-300"
                                >
                                    <span class="size-2 rounded-full bg-amber-600"></span>
                                    <span>NOT BOUND</span>
                                </span>
                            </div>

                            <!-- System Details & Bound Account info -->
                            <div class="my-4 p-4 rounded-2xl bg-cream-200/60 border border-cream-500/40 text-xs space-y-2">
                                <template v-if="system.is_bound">
                                    <div class="flex items-center justify-between text-gray-900">
                                        <span class="font-bold">Bound User:</span>
                                        <span class="font-black text-gray-950">{{ system.bound_username }}</span>
                                    </div>
                                    <div v-if="system.bound_at" class="flex items-center justify-between text-gray-600">
                                        <span>Linked Since:</span>
                                        <span>{{ system.bound_at }}</span>
                                    </div>
                                </template>
                                <template v-else>
                                    <p class="text-gray-700 font-medium leading-relaxed">
                                        No account bound for {{ system.name }}. Enter your username/email and password for {{ system.name }} to bind your identity.
                                    </p>
                                </template>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="mt-4 pt-4 border-t border-cream-500/40 flex items-center justify-end space-x-3">
                            <template v-if="system.is_bound">
                                <a
                                    :href="`/sso/launch/${system.client_id}`"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white font-bold text-xs rounded-xl shadow-xs transition flex items-center space-x-1.5"
                                >

                                    <span>Open System</span>
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                    </svg>
                                </a>

                                <button
                                    @click="unbindSystem(system)"
                                    type="button"
                                    class="px-3 py-2 bg-red-100 hover:bg-red-200 text-red-800 font-bold text-xs rounded-xl transition"
                                >
                                    Unbind
                                </button>
                            </template>
                            <template v-else>
                                <button
                                    @click="openBindModal(system)"
                                    type="button"
                                    class="w-full py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-xs transition flex items-center justify-center space-x-2 cursor-pointer"
                                >
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                    </svg>
                                    <span>Bind {{ system.name }} Account</span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Bind Account Modal -->
        <DialogModal :show="bindingSystem !== null" @close="closeBindModal">
            <template #title>
                <div class="flex items-center space-x-2 text-gray-950 font-black text-xl">
                    <span>Bind Account for {{ bindingSystem?.name }}</span>
                </div>
            </template>

            <template #content>
                <p class="text-xs text-gray-600 mb-4">
                    Enter your existing credentials for <strong>{{ bindingSystem?.name }}</strong> to link it to your Login Portal account.
                </p>

                <form @submit.prevent="submitBind" class="space-y-4">
                    <div>
                        <InputLabel for="username" value="Username or Email" />
                        <TextInput
                            id="username"
                            v-model="form.username"
                            type="text"
                            class="mt-1 block w-full text-sm"
                            placeholder="e.g. john@example.com or john_doe"
                            required
                            autofocus
                        />
                        <InputError :message="form.errors.username" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel for="password" value="Password" />
                        <TextInput
                            id="password"
                            v-model="form.password"
                            type="password"
                            class="mt-1 block w-full text-sm"
                            placeholder="Enter password for this target system"
                            required
                        />
                        <InputError :message="form.errors.password" class="mt-1" />
                    </div>
                </form>
            </template>

            <template #footer>
                <SecondaryButton @click="closeBindModal">
                    Cancel
                </SecondaryButton>

                <PrimaryButton
                    class="ms-3"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                    @click="submitBind"
                >
                    {{ form.processing ? 'Verifying & Binding...' : 'Bind Account' }}
                </PrimaryButton>
            </template>
        </DialogModal>
    </AppLayout>
</template>
