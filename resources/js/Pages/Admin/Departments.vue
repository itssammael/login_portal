<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AdminNav from '@/Components/AdminNav.vue';

const props = defineProps({
    departments: {
        type: Array,
        required: true,
    },
    stats: {
        type: Object,
        default: () => ({
            total_departments: 0,
            total_sections: 0,
            total_employees: 0,
        }),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

// Search state
const search = ref(props.filters.search || '');

const applySearch = () => {
    router.get(route('admin.departments.index'), {
        search: search.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
};

const clearSearch = () => {
    search.value = '';
    applySearch();
};

// Department Modal State
const showDeptModal = ref(false);
const editingDept = ref(null);

const deptForm = useForm({
    name: '',
    acronym: '',
    contact_person_name: '',
    contact_person_position: '',
    department_phone_extension: '',
    email: '',
    department_address: '',
});

const openCreateDeptModal = () => {
    editingDept.value = null;
    deptForm.reset();
    deptForm.clearErrors();
    showDeptModal.value = true;
};

const openEditDeptModal = (dept) => {
    editingDept.value = dept;
    deptForm.clearErrors();
    deptForm.name = dept.name;
    deptForm.acronym = dept.acronym || '';
    deptForm.contact_person_name = dept.contact_person_name || '';
    deptForm.contact_person_position = dept.contact_person_position || '';
    deptForm.department_phone_extension = dept.department_phone_extension || '';
    deptForm.email = dept.email || '';
    deptForm.department_address = dept.department_address || '';
    showDeptModal.value = true;
};

const closeDeptModal = () => {
    showDeptModal.value = false;
    editingDept.value = null;
    deptForm.reset();
    deptForm.clearErrors();
};

const saveDepartment = () => {
    if (editingDept.value) {
        deptForm.put(route('admin.departments.update', editingDept.value.id), {
            preserveScroll: true,
            onSuccess: () => closeDeptModal(),
        });
    } else {
        deptForm.post(route('admin.departments.store'), {
            preserveScroll: true,
            onSuccess: () => closeDeptModal(),
        });
    }
};

// Section Modal State
const showSectionModal = ref(false);
const editingSection = ref(null);
const targetDeptForSection = ref(null);

const sectionForm = useForm({
    name: '',
    section_contact_person_name: '',
    section_contact_person_position: '',
    section_phone_extension: '',
    section_email: '',
    section_address: '',
});

const openCreateSectionModal = (dept) => {
    editingSection.value = null;
    targetDeptForSection.value = dept;
    sectionForm.reset();
    sectionForm.clearErrors();
    showSectionModal.value = true;
};

const openEditSectionModal = (dept, section) => {
    editingSection.value = section;
    targetDeptForSection.value = dept;
    sectionForm.clearErrors();
    sectionForm.name = section.name;
    sectionForm.section_contact_person_name = section.section_contact_person_name || '';
    sectionForm.section_contact_person_position = section.section_contact_person_position || '';
    sectionForm.section_phone_extension = section.section_phone_extension || '';
    sectionForm.section_email = section.section_email || '';
    sectionForm.section_address = section.section_address || '';
    showSectionModal.value = true;
};

const closeSectionModal = () => {
    showSectionModal.value = false;
    editingSection.value = null;
    targetDeptForSection.value = null;
    sectionForm.reset();
    sectionForm.clearErrors();
};

const saveSection = () => {
    if (editingSection.value) {
        sectionForm.put(route('admin.sections.update', editingSection.value.id), {
            preserveScroll: true,
            onSuccess: () => closeSectionModal(),
        });
    } else if (targetDeptForSection.value) {
        sectionForm.post(route('admin.departments.sections.store', targetDeptForSection.value.id), {
            preserveScroll: true,
            onSuccess: () => closeSectionModal(),
        });
    }
};

// Delete Confirmations
const showDeleteConfirm = ref(false);
const deleteType = ref('department'); // 'department' or 'section'
const itemToDelete = ref(null);

const confirmDeleteDept = (dept) => {
    deleteType.value = 'department';
    itemToDelete.value = dept;
    showDeleteConfirm.value = true;
};

const confirmDeleteSection = (section) => {
    deleteType.value = 'section';
    itemToDelete.value = section;
    showDeleteConfirm.value = true;
};

const closeDeleteModal = () => {
    showDeleteConfirm.value = false;
    itemToDelete.value = null;
};

const executeDelete = () => {
    if (deleteType.value === 'department' && itemToDelete.value) {
        router.delete(route('admin.departments.destroy', itemToDelete.value.id), {
            preserveScroll: true,
            onSuccess: () => closeDeleteModal(),
        });
    } else if (deleteType.value === 'section' && itemToDelete.value) {
        router.delete(route('admin.sections.destroy', itemToDelete.value.id), {
            preserveScroll: true,
            onSuccess: () => closeDeleteModal(),
        });
    }
};

// Collapsed state tracking for departments
const collapsedDepts = ref({});
const toggleCollapse = (deptId) => {
    collapsedDepts.value[deptId] = !collapsedDepts.value[deptId];
};
</script>

<template>
    <AppLayout title="Admin - Departments & Sections">
        <template #header>
            <AdminNav />
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h2 class="font-bold text-xl text-gray-900 leading-tight">
                        Department & Section Directory
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Manage municipal departments, organizational sections, contact extensions, and workforce assignments
                    </p>
                </div>
                <button
                    @click="openCreateDeptModal"
                    type="button"
                    class="inline-flex items-center space-x-2 px-4 py-2.5 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-xl shadow-xs transition cursor-pointer self-start sm:self-auto"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    <span>Add Department</span>
                </button>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-8xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

                <!-- Flash Alerts -->
                <div v-if="$page.props.flash?.success" class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>{{ $page.props.flash.success }}</span>
                </div>

                <div v-if="$page.props.flash?.error" class="p-4 bg-red-50 border border-red-200 text-red-800 rounded-xl text-sm font-medium flex items-center shadow-xs">
                    <svg class="size-5 me-2 text-red-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                    <span>{{ $page.props.flash.error }}</span>
                </div>

                <!-- Stats Summary Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <!-- Total Departments -->
                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-forest-900 uppercase tracking-wider">Departments</span>
                            <div class="text-3xl font-extrabold text-gray-950 mt-1">
                                {{ stats.total_departments }}
                            </div>
                        </div>
                        <div class="size-12 rounded-xl bg-forest-900/10 text-forest-900 flex items-center justify-center">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.75a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75V21m6 0v-3.75a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75V21" />
                            </svg>
                        </div>
                    </div>

                    <!-- Total Sections -->
                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-forest-900 uppercase tracking-wider">Organizational Sections</span>
                            <div class="text-3xl font-extrabold text-gray-950 mt-1">
                                {{ stats.total_sections }}
                            </div>
                        </div>
                        <div class="size-12 rounded-xl bg-forest-900/10 text-forest-900 flex items-center justify-center">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" />
                            </svg>
                        </div>
                    </div>

                    <!-- Total Assigned Employees -->
                    <div class="bg-cream-200 p-5 rounded-2xl shadow-xs border border-cream-500/50 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-forest-900 uppercase tracking-wider">Assigned Employees</span>
                            <div class="text-3xl font-extrabold text-gray-950 mt-1">
                                {{ stats.total_employees }}
                            </div>
                        </div>
                        <div class="size-12 rounded-xl bg-forest-900/10 text-forest-900 flex items-center justify-center">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="bg-cream-200 p-4 rounded-2xl shadow-xs border border-cream-500/50 flex items-center justify-between gap-4">
                    <div class="relative flex-1 max-w-lg">
                        <input
                            v-model="search"
                            @keyup.enter="applySearch"
                            type="text"
                            placeholder="Search by department, section, acronym, or contact person (Press Enter)..."
                            class="w-full pl-10 pr-4 py-2 bg-[#fffef9] border border-cream-500 text-gray-900 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 text-sm rounded-xl transition"
                        />
                        <svg class="size-4 text-gray-400 absolute left-3.5 top-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </div>

                    <div class="flex items-center space-x-2">
                        <button
                            v-if="search"
                            @click="clearSearch"
                            type="button"
                            class="px-3 py-2 bg-cream-100 hover:bg-cream-300 text-gray-700 text-xs font-semibold rounded-xl border border-cream-400 transition"
                        >
                            Clear Filter
                        </button>
                        <button
                            @click="applySearch"
                            type="button"
                            class="px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-xl shadow-xs transition"
                        >
                            Filter
                        </button>
                    </div>
                </div>

                <!-- Department List -->
                <div v-if="departments && departments.length > 0" class="space-y-6">
                    <div
                        v-for="dept in departments"
                        :key="dept.id"
                        class="bg-cream-200 rounded-2xl shadow-xs border border-cream-500/50 overflow-hidden transition hover:shadow-sm"
                    >
                        <!-- Department Header Card -->
                        <div class="p-5 sm:p-6 bg-[#fffef7] border-b border-cream-500/40">
                            <div class="flex flex-col md:flex-row md:items-start justify-between gap-4">
                                <div class="space-y-1">
                                    <div class="flex flex-wrap items-center gap-2.5">
                                        <h3 class="font-extrabold text-lg sm:text-xl text-gray-950 tracking-tight">
                                            {{ dept.name }}
                                        </h3>
                                        <span v-if="dept.acronym" class="px-2.5 py-0.5 rounded-full text-xs font-black uppercase tracking-wider bg-forest-900 text-white">
                                            {{ dept.acronym }}
                                        </span>
                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-500/20 text-amber-950">
                                            {{ dept.sections_count || 0 }} {{ (dept.sections_count === 1) ? 'Section' : 'Sections' }}
                                        </span>
                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500/20 text-forest-950">
                                            {{ dept.users_count || 0 }} {{ (dept.users_count === 1) ? 'Employee' : 'Employees' }}
                                        </span>
                                    </div>

                                    <!-- Department Contact & Meta Grid -->
                                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-x-6 gap-y-2 pt-2 text-xs text-gray-700">
                                        <div v-if="dept.contact_person_name" class="flex items-center space-x-1.5">
                                            <span class="font-bold text-gray-900">Head:</span>
                                            <span class="truncate">{{ dept.contact_person_name }} <span v-if="dept.contact_person_position" class="text-gray-500">({{ dept.contact_person_position }})</span></span>
                                        </div>
                                        <div v-if="dept.department_phone_extension" class="flex items-center space-x-1.5">
                                            <span class="font-bold text-gray-900">Ext:</span>
                                            <span>{{ dept.department_phone_extension }}</span>
                                        </div>
                                        <div v-if="dept.email" class="flex items-center space-x-1.5">
                                            <span class="font-bold text-gray-900">Email:</span>
                                            <a :href="'mailto:' + dept.email" class="text-forest-900 underline truncate">{{ dept.email }}</a>
                                        </div>
                                        <div v-if="dept.department_address" class="flex items-center space-x-1.5 sm:col-span-2 md:col-span-1">
                                            <span class="font-bold text-gray-900">Office:</span>
                                            <span class="truncate">{{ dept.department_address }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Department Actions -->
                                <div class="flex items-center space-x-2 shrink-0 self-end md:self-center">
                                    <button
                                        @click="openCreateSectionModal(dept)"
                                        type="button"
                                        class="px-3 py-1.5 bg-forest-900 hover:bg-forest-950 text-white rounded-xl text-xs font-bold shadow-2xs transition flex items-center space-x-1"
                                        title="Add a new section under this department"
                                    >
                                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                        </svg>
                                        <span>Add Section</span>
                                    </button>
                                    <button
                                        @click="openEditDeptModal(dept)"
                                        type="button"
                                        class="p-1.5 bg-cream-100 hover:bg-cream-300 text-gray-700 rounded-xl border border-cream-400 transition"
                                        title="Edit department details"
                                    >
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                        </svg>
                                    </button>
                                    <button
                                        @click="confirmDeleteDept(dept)"
                                        type="button"
                                        class="p-1.5 bg-red-50 hover:bg-red-100 text-red-700 rounded-xl border border-red-200 transition"
                                        title="Delete department"
                                    >
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Sections Accordion / Content Area -->
                        <div class="p-5 sm:p-6 bg-cream-200/50">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="text-xs font-extrabold uppercase tracking-wider text-forest-900">
                                    Sections under this Department ({{ dept.sections?.length || 0 }})
                                </h4>
                            </div>

                            <div v-if="dept.sections && dept.sections.length > 0" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                <div
                                    v-for="section in dept.sections"
                                    :key="section.id"
                                    class="bg-[#fffef9] p-4 rounded-xl border border-cream-500/60 shadow-2xs flex flex-col justify-between hover:border-forest-600/40 transition group"
                                >
                                    <div>
                                        <div class="flex items-start justify-between gap-2 mb-2">
                                            <h5 class="font-bold text-sm text-gray-900 group-hover:text-forest-900 transition leading-snug">
                                                {{ section.name }}
                                            </h5>
                                            <span class="shrink-0 px-2 py-0.5 rounded-md text-[10px] font-bold bg-emerald-100 text-forest-950 border border-emerald-300/60">
                                                {{ section.users_count || 0 }} Staff
                                            </span>
                                        </div>

                                        <div class="space-y-1 text-[11px] text-gray-600">
                                            <div v-if="section.section_contact_person_name" class="truncate">
                                                <span class="font-semibold text-gray-800">Lead:</span> {{ section.section_contact_person_name }}
                                                <span v-if="section.section_contact_person_position" class="text-gray-500">({{ section.section_contact_person_position }})</span>
                                            </div>
                                            <div v-if="section.section_phone_extension">
                                                <span class="font-semibold text-gray-800">Ext:</span> {{ section.section_phone_extension }}
                                            </div>
                                            <div v-if="section.section_email" class="truncate">
                                                <span class="font-semibold text-gray-800">Email:</span> {{ section.section_email }}
                                            </div>
                                            <div v-if="section.section_address" class="truncate">
                                                <span class="font-semibold text-gray-800">Office:</span> {{ section.section_address }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-3 pt-2.5 border-t border-cream-400/50 flex items-center justify-end space-x-1.5">
                                        <button
                                            @click="openEditSectionModal(dept, section)"
                                            type="button"
                                            class="px-2 py-1 bg-cream-100 hover:bg-cream-300 text-gray-700 rounded-lg text-[11px] font-semibold border border-cream-400 transition"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            @click="confirmDeleteSection(section)"
                                            type="button"
                                            class="px-2 py-1 bg-red-50 hover:bg-red-100 text-red-700 rounded-lg text-[11px] font-semibold border border-red-200 transition"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="text-center py-6 bg-[#fffef9]/70 rounded-xl border border-dashed border-cream-500 text-gray-500 text-xs">
                                No sections have been created for this department yet.
                                <button
                                    @click="openCreateSectionModal(dept)"
                                    class="ml-1 text-forest-900 font-bold underline cursor-pointer"
                                >
                                    Add Section now
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else class="text-center py-16 bg-cream-200 rounded-3xl border border-cream-500/50 p-6">
                    <svg class="size-12 text-forest-900/40 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.75a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75V21m6 0v-3.75a.75.75 0 01.75-.75h1.5a.75.75 0 01.75.75V21" />
                    </svg>
                    <h3 class="text-base font-bold text-gray-900">No departments found</h3>
                    <p class="text-xs text-gray-500 mt-1 max-w-sm mx-auto">
                        Get started by registering municipal departments and organizational sections.
                    </p>
                    <button
                        @click="openCreateDeptModal"
                        type="button"
                        class="mt-4 px-4 py-2 bg-forest-900 hover:bg-forest-950 text-white text-xs font-bold rounded-xl shadow-xs transition"
                    >
                        Create First Department
                    </button>
                </div>

            </div>
        </div>

        <!-- ==================== DEPARTMENT MODAL (Create / Edit) ==================== -->
        <div v-if="showDeptModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
            <div class="bg-cream-200 rounded-3xl shadow-xl border border-cream-500/60 w-full max-w-lg overflow-hidden animate-in fade-in zoom-in duration-150">
                <!-- Modal Header -->
                <div class="flex items-center justify-between p-5 bg-forest-900 text-white">
                    <h3 class="font-bold text-base">
                        {{ editingDept ? 'Edit Department' : 'Create New Department' }}
                    </h3>
                    <button @click="closeDeptModal" type="button" class="text-emerald-100 hover:text-white p-1 rounded-lg">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Modal Body -->
                <form @submit.prevent="saveDepartment" class="p-6 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Department Name *</label>
                        <input
                            v-model="deptForm.name"
                            type="text"
                            placeholder="e.g. Human Resource Management Office"
                            class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                            required
                        />
                        <div v-if="deptForm.errors.name" class="text-xs text-red-600 mt-1">{{ deptForm.errors.name }}</div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Acronym / Abbreviation</label>
                            <input
                                v-model="deptForm.acronym"
                                type="text"
                                placeholder="e.g. HRMO"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                            />
                            <div v-if="deptForm.errors.acronym" class="text-xs text-red-600 mt-1">{{ deptForm.errors.acronym }}</div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Phone Extension</label>
                            <input
                                v-model="deptForm.department_phone_extension"
                                type="text"
                                placeholder="e.g. 104"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                            />
                            <div v-if="deptForm.errors.department_phone_extension" class="text-xs text-red-600 mt-1">{{ deptForm.errors.department_phone_extension }}</div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Head / Contact Person</label>
                            <input
                                v-model="deptForm.contact_person_name"
                                type="text"
                                placeholder="e.g. Atty. Maria Santos"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                            />
                            <div v-if="deptForm.errors.contact_person_name" class="text-xs text-red-600 mt-1">{{ deptForm.errors.contact_person_name }}</div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Position / Title</label>
                            <input
                                v-model="deptForm.contact_person_position"
                                type="text"
                                placeholder="e.g. Department Head"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                            />
                            <div v-if="deptForm.errors.contact_person_position" class="text-xs text-red-600 mt-1">{{ deptForm.errors.contact_person_position }}</div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Official Email</label>
                        <input
                            v-model="deptForm.email"
                            type="email"
                            placeholder="e.g. hrmo@lgunet.gov.ph"
                            class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                        />
                        <div v-if="deptForm.errors.email" class="text-xs text-red-600 mt-1">{{ deptForm.errors.email }}</div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Office Location / Address</label>
                        <textarea
                            v-model="deptForm.department_address"
                            rows="2"
                            placeholder="e.g. 2nd Floor, Executive Building, City Hall"
                            class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                        ></textarea>
                        <div v-if="deptForm.errors.department_address" class="text-xs text-red-600 mt-1">{{ deptForm.errors.department_address }}</div>
                    </div>

                    <!-- Footer buttons -->
                    <div class="flex items-center justify-end space-x-2 pt-3 border-t border-cream-400">
                        <button
                            @click="closeDeptModal"
                            type="button"
                            class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-700 rounded-xl text-xs font-bold border border-cream-400 transition"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="deptForm.processing"
                            class="px-5 py-2 bg-forest-900 hover:bg-forest-950 text-white rounded-xl text-xs font-bold shadow-xs transition disabled:opacity-50"
                        >
                            {{ deptForm.processing ? 'Saving...' : (editingDept ? 'Update Department' : 'Save Department') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ==================== SECTION MODAL (Create / Edit) ==================== -->
        <div v-if="showSectionModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
            <div class="bg-cream-200 rounded-3xl shadow-xl border border-cream-500/60 w-full max-w-lg overflow-hidden animate-in fade-in zoom-in duration-150">
                <!-- Modal Header -->
                <div class="flex items-center justify-between p-5 bg-forest-900 text-white">
                    <div>
                        <h3 class="font-bold text-base">
                            {{ editingSection ? 'Edit Section' : 'Add Section' }}
                        </h3>
                        <p class="text-xs text-emerald-200 mt-0.5">
                            Under: <span class="font-bold text-white">{{ targetDeptForSection?.name }}</span>
                        </p>
                    </div>
                    <button @click="closeSectionModal" type="button" class="text-emerald-100 hover:text-white p-1 rounded-lg">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Modal Body -->
                <form @submit.prevent="saveSection" class="p-6 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Section Name *</label>
                        <input
                            v-model="sectionForm.name"
                            type="text"
                            placeholder="e.g. Recruitment and Selection Section"
                            class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                            required
                        />
                        <div v-if="sectionForm.errors.name" class="text-xs text-red-600 mt-1">{{ sectionForm.errors.name }}</div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Section Lead / Contact</label>
                            <input
                                v-model="sectionForm.section_contact_person_name"
                                type="text"
                                placeholder="e.g. John Doe"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                            />
                            <div v-if="sectionForm.errors.section_contact_person_name" class="text-xs text-red-600 mt-1">{{ sectionForm.errors.section_contact_person_name }}</div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Position / Designation</label>
                            <input
                                v-model="sectionForm.section_contact_person_position"
                                type="text"
                                placeholder="e.g. Section Chief"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                            />
                            <div v-if="sectionForm.errors.section_contact_person_position" class="text-xs text-red-600 mt-1">{{ sectionForm.errors.section_contact_person_position }}</div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Phone Extension</label>
                            <input
                                v-model="sectionForm.section_phone_extension"
                                type="text"
                                placeholder="e.g. 1041"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                            />
                            <div v-if="sectionForm.errors.section_phone_extension" class="text-xs text-red-600 mt-1">{{ sectionForm.errors.section_phone_extension }}</div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Section Email</label>
                            <input
                                v-model="sectionForm.section_email"
                                type="email"
                                placeholder="e.g. recruitment@lgunet.gov.ph"
                                class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                            />
                            <div v-if="sectionForm.errors.section_email" class="text-xs text-red-600 mt-1">{{ sectionForm.errors.section_email }}</div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-forest-900 uppercase tracking-wider mb-1">Office Room / Address</label>
                        <textarea
                            v-model="sectionForm.section_address"
                            rows="2"
                            placeholder="e.g. Room 204, City Hall Main"
                            class="w-full px-3.5 py-2 bg-[#fffef9] border border-cream-500 focus:border-forest-600 focus:ring-2 focus:ring-forest-200 rounded-xl text-gray-900 text-sm"
                        ></textarea>
                        <div v-if="sectionForm.errors.section_address" class="text-xs text-red-600 mt-1">{{ sectionForm.errors.section_address }}</div>
                    </div>

                    <!-- Footer buttons -->
                    <div class="flex items-center justify-end space-x-2 pt-3 border-t border-cream-400">
                        <button
                            @click="closeSectionModal"
                            type="button"
                            class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-700 rounded-xl text-xs font-bold border border-cream-400 transition"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="sectionForm.processing"
                            class="px-5 py-2 bg-forest-900 hover:bg-forest-950 text-white rounded-xl text-xs font-bold shadow-xs transition disabled:opacity-50"
                        >
                            {{ sectionForm.processing ? 'Saving...' : (editingSection ? 'Update Section' : 'Save Section') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ==================== DELETE CONFIRMATION MODAL ==================== -->
        <div v-if="showDeleteConfirm" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
            <div class="bg-cream-200 rounded-3xl shadow-xl border border-cream-500/60 w-full max-w-md overflow-hidden p-6 animate-in fade-in zoom-in duration-150">
                <div class="size-12 rounded-2xl bg-red-100 text-red-700 flex items-center justify-center mb-4 mx-auto">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                </div>

                <h3 class="text-center font-bold text-base text-gray-950">
                    Delete {{ deleteType === 'department' ? 'Department' : 'Section' }}?
                </h3>
                <p class="text-xs text-gray-600 text-center mt-2">
                    Are you sure you want to permanently delete <strong class="text-gray-900">"{{ itemToDelete?.name }}"</strong>?
                    <span v-if="deleteType === 'department'" class="block mt-1 text-red-600 font-medium">
                        Warning: All sections under this department will also be removed.
                    </span>
                </p>

                <div class="flex items-center justify-center space-x-3 mt-6">
                    <button
                        @click="closeDeleteModal"
                        type="button"
                        class="px-4 py-2 bg-cream-100 hover:bg-cream-300 text-gray-700 rounded-xl text-xs font-bold border border-cream-400 transition"
                    >
                        Cancel
                    </button>
                    <button
                        @click="executeDelete"
                        type="button"
                        class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-xs font-bold shadow-xs transition"
                    >
                        Yes, Delete
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
