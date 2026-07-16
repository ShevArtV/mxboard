<script setup>
import { ref, onMounted } from 'vue';
import {
    DataTable, Column, Button, InputText, Dialog, Select, Checkbox, useToast, useConfirm,
} from 'primevue';
import { DepartmentApi, errorMessage, listOf } from '../../api/connector.js';
import { t } from '../../utils/i18n.js';

const toast = useToast();
const confirm = useConfirm();

const rows = ref([]);
const groups = ref([]);
const loading = ref(false);
const saving = ref(false);

const registerOpen = ref(false);
const editOpen = ref(false);
const regForm = ref({ usergroup_id: 0, name: '' });
const editForm = ref({ id: 0, name: '', active: true, position: 0 });

onMounted(load);

async function load() {
    loading.value = true;
    try {
        const [d, g] = await Promise.all([DepartmentApi.getList(), DepartmentApi.groups()]);
        rows.value = listOf(d);
        groups.value = listOf(g);
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_refs_load'), detail: errorMessage(e), life: 8000 });
    } finally {
        loading.value = false;
    }
}

function openRegister() {
    regForm.value = { usergroup_id: 0, name: '' };
    registerOpen.value = true;
}

async function register() {
    if (!regForm.value.usergroup_id) return;
    saving.value = true;
    try {
        await DepartmentApi.register({ usergroup_id: regForm.value.usergroup_id, name: regForm.value.name });
        toast.add({ severity: 'success', summary: t('mxboard_ui_struct_created'), life: 3000 });
        registerOpen.value = false;
        load();
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    } finally {
        saving.value = false;
    }
}

function openEdit(row) {
    editForm.value = {
        id: row.id, name: row.name || '', active: row.active !== false && row.active !== 0, position: Number(row.position) || 0,
    };
    editOpen.value = true;
}

async function saveEdit() {
    saving.value = true;
    try {
        await DepartmentApi.update(editForm.value.id, {
            name: editForm.value.name,
            active: editForm.value.active ? 1 : 0,
            position: editForm.value.position,
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

function removeRow(event, row) {
    confirm.require({
        target: event.currentTarget,
        message: t('mxboard_ui_struct_confirm_remove_dept', { name: row.name }),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('mxboard_ui_delete'),
        rejectLabel: t('mxboard_ui_cancel'),
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            try {
                await DepartmentApi.remove(row.id);
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
            <Button :label="t('mxboard_ui_struct_register_dept')" icon="pi pi-plus" size="small" @click="openRegister" />
            <Button :label="t('mxboard_ui_refresh')" icon="pi pi-refresh" size="small" severity="secondary" outlined :loading="loading" @click="load" />
        </div>

        <DataTable :value="rows" :loading="loading" size="small" striped-rows>
            <Column field="name" :header="t('mxboard_ui_struct_name')" />
            <Column field="usergroup_id" :header="t('mxboard_ui_struct_usergroup')" style="width: 220px" />
            <Column style="width: 110px">
                <template #body="{ data }">
                    <Button icon="pi pi-pencil" size="small" severity="secondary" text @click="openEdit(data)" />
                    <Button icon="pi pi-trash" size="small" severity="danger" text @click="removeRow($event, data)" />
                </template>
            </Column>
            <template #empty><div class="mxb-empty">{{ t('mxboard_ui_struct_empty') }}</div></template>
        </DataTable>

        <!-- Регистрация отдела -->
        <Dialog v-model:visible="registerOpen" modal :header="t('mxboard_ui_struct_register_dept')" :style="{ width: '520px' }">
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_usergroup') }}</label>
                <Select
                    v-model="regForm.usergroup_id"
                    :options="groups"
                    option-label="name"
                    option-value="id"
                    :option-disabled="(g) => g.registered"
                    filter
                    fluid
                >
                    <template #option="{ option }">
                        {{ option.name }}
                        <span v-if="option.registered" class="mxb-hint"> — {{ t('mxboard_ui_struct_already_dept') }}</span>
                    </template>
                </Select>
                <div class="mxb-hint">{{ t('mxboard_ui_struct_usergroup_hint') }}</div>
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_name') }}</label>
                <InputText v-model="regForm.name" fluid />
            </div>
            <template #footer>
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined @click="registerOpen = false" />
                    <Button :label="t('mxboard_ui_create')" icon="pi pi-check" :disabled="!regForm.usergroup_id" :loading="saving" @click="register" />
                </div>
            </template>
        </Dialog>

        <!-- Правка отдела -->
        <Dialog v-model:visible="editOpen" modal :header="t('mxboard_ui_struct_edit')" :style="{ width: '460px' }">
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_name') }}</label>
                <InputText v-model="editForm.name" fluid />
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_position') }}</label>
                <InputText v-model="editForm.position" fluid />
            </div>
            <div class="mxb-field mxb-check">
                <Checkbox v-model="editForm.active" :binary="true" input-id="dept-active" />
                <label for="dept-active">{{ t('mxboard_ui_struct_active') }}</label>
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
