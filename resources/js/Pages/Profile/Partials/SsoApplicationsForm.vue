<script setup>
import { ref, computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import ActionSection from '@/Components/ActionSection.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';

const page = usePage();
const applications = computed(() => page.props.sso_applications || []);

const bindingApp = ref(null);
const unbindingApp = ref(null);

const bindForm = useForm({
    portal_password: '',
    username: '',
    password: '',
});

const unbindForm = useForm({});

const openBindModal = (app) => {
    bindingApp.value = app;
    bindForm.reset();
    bindForm.clearErrors();
};

const closeBindModal = () => {
    bindingApp.value = null;
    bindForm.reset();
    bindForm.clearErrors();
};

const submitBind = () => {
    if (!bindingApp.value) return;

    bindForm.post(route('sso.connected-systems.bind', bindingApp.value.client_id), {
        preserveScroll: true,
        onSuccess: () => closeBindModal(),
    });
};

const promptUnbind = (app) => {
    unbindingApp.value = app;
};

const closeUnbindModal = () => {
    unbindingApp.value = null;
    unbindForm.reset();
};

const confirmUnbind = () => {
    if (!unbindingApp.value) return;

    unbindForm.delete(route('sso.connected-systems.unbind', unbindingApp.value.client_id), {
        preserveScroll: true,
        onSuccess: () => closeUnbindModal(),
        onFinish: () => closeUnbindModal(),
    });
};
</script>

<template>
    <ActionSection>
        <template #title>
            SSO Applications
        </template>

        <template #description>
            Connect your LGUNET Portal account to registered government applications to enable Single Sign-On.
        </template>

        <template #content>
            <div class="space-y-4">
                <div v-if="applications.length === 0" class="text-sm text-gray-500 italic py-2">
                    No SSO applications are currently available.
                </div>

                <div
                    v-for="app in applications"
                    :key="app.client_id"
                    class="p-4 rounded-2xl border border-gray-200 bg-white hover:border-gray-300 transition flex flex-col sm:flex-row sm:items-center justify-between gap-4"
                >
                    <div class="flex items-center space-x-3.5">
                        <div
                            v-if="app.icon_url"
                            class="size-11 rounded-xl bg-gray-50 border border-gray-200 p-1 flex items-center justify-center shrink-0 overflow-hidden"
                        >
                            <img :src="app.icon_url" :alt="app.name" class="w-full h-full object-contain rounded-lg" />
                        </div>
                        <div
                            v-else
                            class="size-11 rounded-xl bg-forest-50 text-forest-700 font-bold flex items-center justify-center shrink-0 text-sm border border-forest-100"
                        >
                            {{ app.name.substring(0, 2).toUpperCase() }}
                        </div>

                        <div>
                            <div class="flex items-center space-x-2">
                                <h4 class="font-bold text-gray-900 text-sm">
                                    {{ app.name }}
                                </h4>
                                <span
                                    v-if="app.is_bound"
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-800"
                                >
                                    Connected
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-gray-100 text-gray-600"
                                >
                                    Not Connected
                                </span>
                            </div>
                            <p class="text-xs text-gray-500 mt-0.5">
                                <span v-if="app.is_bound">
                                    Linked to <strong class="text-gray-700">{{ app.bound_username }}</strong>
                                    <span v-if="app.bound_at" class="text-gray-400"> ({{ app.bound_at }})</span>
                                </span>
                                <span v-else>
                                    {{ app.description || 'Link your account to launch with Single Sign-On' }}
                                </span>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center space-x-2 shrink-0 self-end sm:self-center">
                        <template v-if="app.is_bound">
                            <a
                                :href="app.launch_url"
                                class="inline-flex items-center px-3.5 py-2 bg-forest-600 hover:bg-forest-700 active:bg-forest-800 text-white text-xs font-semibold rounded-xl shadow-xs transition"
                            >
                                Open {{ app.name }}
                            </a>
                            <SecondaryButton @click="promptUnbind(app)" class="text-xs">
                                Unbind
                            </SecondaryButton>
                        </template>
                        <template v-else>
                            <PrimaryButton @click="openBindModal(app)" class="text-xs">
                                Bind Account
                            </PrimaryButton>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Bind Modal -->
            <DialogModal :show="!!bindingApp" @close="closeBindModal">
                <template #title>
                    Bind Account: {{ bindingApp?.name }}
                </template>

                <template #content>
                    <p class="text-xs text-gray-600 mb-4">
                        To securely connect <strong>{{ bindingApp?.name }}</strong> to your LGUNET Portal identity, verify your credentials. Your password will never be stored by LGUNET Portal or exposed in URLs.
                    </p>

                    <div class="space-y-4">
                        <div>
                            <InputLabel for="modal_portal_password" value="Confirm Your LGUNET Portal Password" />
                            <TextInput
                                id="modal_portal_password"
                                v-model="bindForm.portal_password"
                                type="password"
                                class="mt-1 block w-full text-sm"
                                placeholder="Enter your current LGUNET Portal password"
                                autocomplete="current-password"
                            />
                            <InputError :message="bindForm.errors.portal_password" class="mt-1" />
                        </div>

                        <div class="pt-2 border-t border-gray-100">
                            <InputLabel for="modal_username" :value="bindingApp?.name + ' Username / Email'" />
                            <TextInput
                                id="modal_username"
                                v-model="bindForm.username"
                                type="text"
                                class="mt-1 block w-full text-sm"
                                :placeholder="'Your existing ' + bindingApp?.name + ' username'"
                                autocomplete="username"
                            />
                            <InputError :message="bindForm.errors.username" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel for="modal_password" :value="bindingApp?.name + ' Password'" />
                            <TextInput
                                id="modal_password"
                                v-model="bindForm.password"
                                type="password"
                                class="mt-1 block w-full text-sm"
                                :placeholder="'Your ' + bindingApp?.name + ' password'"
                                autocomplete="current-password"
                            />
                            <InputError :message="bindForm.errors.password" class="mt-1" />
                        </div>
                    </div>
                </template>

                <template #footer>
                    <SecondaryButton @click="closeBindModal" :disabled="bindForm.processing">
                        Cancel
                    </SecondaryButton>

                    <PrimaryButton
                        class="ms-3"
                        :class="{ 'opacity-25': bindForm.processing }"
                        :disabled="bindForm.processing"
                        @click="submitBind"
                    >
                        Confirm & Bind
                    </PrimaryButton>
                </template>
            </DialogModal>

            <!-- Unbind Confirmation Modal -->
            <DialogModal :show="!!unbindingApp" @close="closeUnbindModal">
                <template #title>
                    Unbind {{ unbindingApp?.name }}
                </template>

                <template #content>
                    Are you sure you want to unbind your <strong>{{ unbindingApp?.name }}</strong> account? You will no longer be able to use Single Sign-On for this application until you bind it again.
                </template>

                <template #footer>
                    <SecondaryButton @click="closeUnbindModal">
                        Cancel
                    </SecondaryButton>

                    <DangerButton
                        class="ms-3"
                        :class="{ 'opacity-25': unbindForm.processing }"
                        :disabled="unbindForm.processing"
                        @click="confirmUnbind"
                    >
                        Unbind Account
                    </DangerButton>
                </template>
            </DialogModal>
        </template>
    </ActionSection>
</template>
