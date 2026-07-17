<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import {
    DataTable, Column, Button, InputText, Dialog, Select, Checkbox, Tag, useToast, useConfirm,
} from 'primevue';
import {
    ProjectApi, ColumnApi, errorMessage, listOf,
} from '../../api/connector.js';
import { t } from '../../utils/i18n.js';

const toast = useToast();
const confirm = useConfirm();

const projects = ref([]);
const projectId = ref(null);
const columns = ref([]);
const loading = ref(false);
const saving = ref(false);

const createOpen = ref(false);
const createForm = ref({ key: '', name: '', move_roles: '', stage_key: '', color: '#6c757d' });
const editOpen = ref(false);
const editForm = ref({ id: 0, name: '', move_roles: '', stage_key: '', color: '#6c757d', position: 0, is_initial: false, is_final: false });

// Опции селектора: «шаблон новых проектов» (project_id=0) + реальные проекты.
const projectOptions = computed(() => [
    { id: 0, name: t('mxboard_ui_struct_template') },
    ...projects.value.map((p) => ({ id: Number(p.id), name: p.name })),
]);

onMounted(async () => {
    try {
        projects.value = listOf(await ProjectApi.getList());
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_refs_load'), detail: errorMessage(e), life: 8000 });
    }
    projectId.value = 0;
});

watch(projectId, load);

async function load() {
    if (projectId.value === null) { columns.value = []; return; }
    loading.value = true;
    try {
        columns.value = listOf(await ColumnApi.getList(projectId.value));
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_refs_load'), detail: errorMessage(e), life: 8000 });
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    createForm.value = { key: '', name: '', move_roles: '', stage_key: '', color: '#6c757d' };
    createOpen.value = true;
}
async function create() {
    if (!createForm.value.key.trim() || !createForm.value.name.trim()) return;
    saving.value = true;
    try {
        await ColumnApi.create({
            project_id: projectId.value,
            key: createForm.value.key.trim(),
            name: createForm.value.name.trim(),
            move_roles: createForm.value.move_roles,
            stage_key: createForm.value.stage_key,
            color: createForm.value.color,
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

function openEdit(col) {
    editForm.value = {
        id: col.id, name: col.name || '', move_roles: col.move_roles || '', stage_key: col.stage_key || '',
        color: col.color || '#6c757d',
        position: Number(col.position) || 0,
        is_initial: col.is_initial === true || col.is_initial === 1,
        is_final: col.is_final === true || col.is_final === 1,
    };
    editOpen.value = true;
}
async function saveEdit() {
    saving.value = true;
    try {
        // is_initial/is_final = 1 переносит флаг; снять в 0 напрямую нельзя (сервер игнорит falsy).
        const data = {
            name: editForm.value.name,
            move_roles: editForm.value.move_roles,
            stage_key: editForm.value.stage_key,
            color: editForm.value.color,
            position: editForm.value.position,
        };
        if (editForm.value.is_initial) data.is_initial = 1;
        if (editForm.value.is_final) data.is_final = 1;
        await ColumnApi.update(editForm.value.id, data);
        toast.add({ severity: 'success', summary: t('mxboard_ui_struct_saved'), life: 3000 });
        editOpen.value = false;
        load();
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    } finally {
        saving.value = false;
    }
}
function removeColumn(event, col) {
    confirm.require({
        target: event.currentTarget,
        message: t('mxboard_ui_struct_confirm_remove_column', { name: col.name }),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('mxboard_ui_delete'),
        rejectLabel: t('mxboard_ui_cancel'),
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            try {
                await ColumnApi.remove(col.id);
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
            <Select v-model="projectId" :options="projectOptions" option-label="name" option-value="id" :placeholder="t('mxboard_ui_struct_pick_project')" />
            <Button :label="t('mxboard_ui_struct_new_column')" icon="pi pi-plus" size="small" :disabled="projectId === null" @click="openCreate" />
            <Button :label="t('mxboard_ui_refresh')" icon="pi pi-refresh" size="small" severity="secondary" outlined :loading="loading" @click="load" />
        </div>

        <div class="mxb-hint" style="margin-bottom: 8px">{{ t('mxboard_ui_struct_flag_transfer') }}</div>

        <DataTable :value="columns" :loading="loading" size="small" striped-rows>
            <Column field="position" :header="t('mxboard_ui_struct_position')" style="width: 90px" />
            <Column field="key" :header="t('mxboard_ui_struct_key')" style="width: 150px" />
            <Column field="name" :header="t('mxboard_ui_struct_name')" />
            <Column field="move_roles" :header="t('mxboard_ui_struct_move_roles')" style="width: 180px" />
            <Column field="color" :header="t('mxboard_ui_struct_color')" style="width: 80px">
                <template #body="{ data }">
                    <span :style="{ display: 'inline-block', width: '24px', height: '24px', borderRadius: '4px', backgroundColor: data.color || '#6c757d', verticalAlign: 'middle' }" />
                </template>
            </Column>
            <Column style="width: 130px">
                <template #body="{ data }">
                    <Tag v-if="data.is_initial" :value="t('mxboard_ui_struct_is_initial')" severity="info" />
                    <Tag v-if="data.is_final" :value="t('mxboard_ui_struct_is_final')" severity="success" />
                </template>
            </Column>
            <Column style="width: 110px">
                <template #body="{ data }">
                    <Button icon="pi pi-pencil" size="small" severity="secondary" text @click="openEdit(data)" />
                    <Button icon="pi pi-trash" size="small" severity="danger" text @click="removeColumn($event, data)" />
                </template>
            </Column>
            <template #empty><div class="mxb-empty">{{ t('mxboard_ui_struct_empty') }}</div></template>
        </DataTable>

        <!-- Новая колонка -->
        <Dialog v-model:visible="createOpen" modal :header="t('mxboard_ui_struct_new_column')" :style="{ width: '560px' }">
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
                <label>{{ t('mxboard_ui_struct_move_roles') }}</label>
                <InputText v-model="createForm.move_roles" fluid placeholder="author,assignee" />
                <div class="mxb-hint">{{ t('mxboard_ui_struct_move_roles_hint') }}</div>
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_stage_key') }}</label>
                <InputText v-model="createForm.stage_key" fluid />
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_color') }}</label>
                <div style="display: flex; align-items: center; gap: 8px">
                    <input type="color" v-model="createForm.color" style="width: 40px; height: 32px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer" />
                    <InputText v-model="createForm.color" fluid style="flex: 1" />
                </div>
            </div>
            <template #footer>
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined @click="createOpen = false" />
                    <Button :label="t('mxboard_ui_create')" icon="pi pi-check" :loading="saving" @click="create" />
                </div>
            </template>
        </Dialog>

        <!-- Правка колонки -->
        <Dialog v-model:visible="editOpen" modal :header="t('mxboard_ui_struct_edit')" :style="{ width: '560px' }">
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_name') }}</label>
                <InputText v-model="editForm.name" fluid />
            </div>
            <div class="mxb-row">
                <div class="mxb-field mxb-col">
                    <label>{{ t('mxboard_ui_struct_move_roles') }}</label>
                    <InputText v-model="editForm.move_roles" fluid />
                </div>
                <div class="mxb-field mxb-col">
                    <label>{{ t('mxboard_ui_struct_stage_key') }}</label>
                    <InputText v-model="editForm.stage_key" fluid />
                </div>
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_position') }}</label>
                <InputText v-model="editForm.position" fluid />
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_color') }}</label>
                <div style="display: flex; align-items: center; gap: 8px">
                    <input type="color" v-model="editForm.color" style="width: 40px; height: 32px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer" />
                    <InputText v-model="editForm.color" fluid style="flex: 1" />
                </div>
            </div>
            <div class="mxb-field mxb-check">
                <Checkbox v-model="editForm.is_initial" :binary="true" input-id="col-initial" />
                <label for="col-initial">{{ t('mxboard_ui_struct_is_initial') }}</label>
            </div>
            <div class="mxb-field mxb-check">
                <Checkbox v-model="editForm.is_final" :binary="true" input-id="col-final" />
                <label for="col-final">{{ t('mxboard_ui_struct_is_final') }}</label>
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
