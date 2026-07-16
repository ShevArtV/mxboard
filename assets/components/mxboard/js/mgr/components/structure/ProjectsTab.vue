<script setup>
import { ref, computed, onMounted } from 'vue';
import {
    DataTable, Column, Button, InputText, Dialog, Select, Checkbox, useToast, useConfirm,
} from 'primevue';
import {
    DepartmentApi, ProjectApi, errorMessage, listOf,
} from '../../api/connector.js';
import { t } from '../../utils/i18n.js';

const toast = useToast();
const confirm = useConfirm();

const departments = ref([]);
const projects = ref([]);
const loading = ref(false);
const saving = ref(false);

const createOpen = ref(false);
const createForm = ref({ department_id: 0, key: '', name: '', description: '' });
const editOpen = ref(false);
const editForm = ref({ id: 0, name: '', description: '', active: true });

const deptName = computed(() => (id) => {
    const d = departments.value.find((x) => Number(x.id) === Number(id));
    return d ? d.name : `#${id}`;
});

onMounted(load);

async function load() {
    loading.value = true;
    try {
        const [d, p] = await Promise.all([DepartmentApi.getList(), ProjectApi.getList()]);
        departments.value = listOf(d);
        projects.value = listOf(p);
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_refs_load'), detail: errorMessage(e), life: 8000 });
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    createForm.value = {
        department_id: departments.value.length ? Number(departments.value[0].id) : 0,
        key: '', name: '', description: '',
    };
    createOpen.value = true;
}
async function create() {
    if (!createForm.value.department_id || !createForm.value.key.trim() || !createForm.value.name.trim()) return;
    saving.value = true;
    try {
        await ProjectApi.create({
            department_id: createForm.value.department_id,
            key: createForm.value.key.trim(),
            name: createForm.value.name.trim(),
            description: createForm.value.description,
        });
        toast.add({ severity: 'success', summary: t('mxboard_ui_struct_created'), life: 3000 });
        createOpen.value = false;
        load();
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    } finally {
        saving.value = false;
    }
}

function openEdit(project) {
    editForm.value = {
        id: project.id, name: project.name || '', description: project.description || '',
        active: project.active !== false && project.active !== 0,
    };
    editOpen.value = true;
}
async function saveEdit() {
    saving.value = true;
    try {
        await ProjectApi.update(editForm.value.id, {
            name: editForm.value.name,
            description: editForm.value.description,
            active: editForm.value.active ? 1 : 0,
        });
        toast.add({ severity: 'success', summary: t('mxboard_ui_struct_saved'), life: 3000 });
        editOpen.value = false;
        load();
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    } finally {
        saving.value = false;
    }
}
function removeProject(event, project) {
    confirm.require({
        target: event.currentTarget,
        message: t('mxboard_ui_struct_confirm_remove_project', { name: project.name }),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('mxboard_ui_delete'),
        rejectLabel: t('mxboard_ui_cancel'),
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            try {
                await ProjectApi.remove(project.id);
                toast.add({ severity: 'success', summary: t('mxboard_ui_struct_removed'), life: 3000 });
                load();
            } catch (e) {
                toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
            }
        },
    });
}
</script>

<template>
    <div>
        <div class="mxb-toolbar">
            <Button :label="t('mxboard_ui_struct_new_project')" icon="pi pi-plus" size="small" :disabled="!departments.length" @click="openCreate" />
            <Button :label="t('mxboard_ui_refresh')" icon="pi pi-refresh" size="small" severity="secondary" outlined :loading="loading" @click="load" />
        </div>

        <DataTable :value="projects" :loading="loading" size="small" striped-rows>
            <Column field="key" :header="t('mxboard_ui_struct_key')" style="width: 160px" />
            <Column field="name" :header="t('mxboard_ui_struct_name')" />
            <Column :header="t('mxboard_ui_struct_departments')" style="width: 200px">
                <template #body="{ data }">{{ deptName(data.department_id) }}</template>
            </Column>
            <Column style="width: 110px">
                <template #body="{ data }">
                    <Button icon="pi pi-pencil" size="small" severity="secondary" text @click="openEdit(data)" />
                    <Button icon="pi pi-trash" size="small" severity="danger" text @click="removeProject($event, data)" />
                </template>
            </Column>
            <template #empty><div class="mxb-empty">{{ t('mxboard_ui_struct_empty') }}</div></template>
        </DataTable>

        <!-- Новый проект -->
        <Dialog v-model:visible="createOpen" modal :header="t('mxboard_ui_struct_new_project')" :style="{ width: '560px' }">
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_departments') }}</label>
                <Select v-model="createForm.department_id" :options="departments" option-label="name" option-value="id" fluid />
            </div>
            <div class="mxb-row">
                <div class="mxb-field mxb-col">
                    <label>{{ t('mxboard_ui_struct_key') }}</label>
                    <InputText v-model="createForm.key" fluid />
                </div>
                <div class="mxb-field mxb-col">
                    <label>{{ t('mxboard_ui_struct_name') }}</label>
                    <InputText v-model="createForm.name" fluid />
                </div>
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_description') }}</label>
                <InputText v-model="createForm.description" fluid />
            </div>
            <div class="mxb-hint">{{ t('mxboard_ui_struct_columns_from_template') }}</div>
            <template #footer>
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined @click="createOpen = false" />
                    <Button :label="t('mxboard_ui_create')" icon="pi pi-check" :loading="saving" @click="create" />
                </div>
            </template>
        </Dialog>

        <!-- Правка проекта -->
        <Dialog v-model:visible="editOpen" modal :header="t('mxboard_ui_struct_edit')" :style="{ width: '520px' }">
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_name') }}</label>
                <InputText v-model="editForm.name" fluid />
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_description') }}</label>
                <InputText v-model="editForm.description" fluid />
            </div>
            <div class="mxb-field mxb-check">
                <Checkbox v-model="editForm.active" :binary="true" input-id="proj-active" />
                <label for="proj-active">{{ t('mxboard_ui_struct_active') }}</label>
            </div>
            <template #footer>
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined @click="editOpen = false" />
                    <Button :label="t('mxboard_ui_save')" icon="pi pi-check" :loading="saving" @click="saveEdit" />
                </div>
            </template>
        </Dialog>
    </div>
</template>
