<script setup>
import { ref, computed, onMounted } from 'vue';
import {
    DataTable, Column, Button, InputText, Dialog, Tag, Message, useToast, useConfirm,
} from 'primevue';
import { PriorityApi, errorMessage, listOf } from '../../api/connector.js';
import { t } from '../../utils/i18n.js';

const toast = useToast();
const confirm = useConfirm();

// Справочник ГЛОБАЛЬНЫЙ (инвариант 1): проектной привязки нет, поэтому — в отличие от
// колонок и очередей — селектора проекта на вкладке нет.
const priorities = ref([]);
const loading = ref(false);
const saving = ref(false);

const createOpen = ref(false);
const createForm = ref({ name: '', value: 0, color: '#6c757d' });
const editOpen = ref(false);
const editForm = ref({ id: 0, name: '', value: 0, color: '#6c757d' });

// Удалять можно, только пока приоритетов больше одного (инвариант 2 — сервер тоже
// отклонит, кнопку прячем, чтобы не предлагать заведомо запрещённое).
const canDelete = computed(() => priorities.value.length > 1);

onMounted(load);

async function load() {
    loading.value = true;
    try {
        priorities.value = listOf(await PriorityApi.getList());
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_refs_load'), detail: errorMessage(e), life: 8000 });
    } finally {
        loading.value = false;
    }
}

// Подсказать следующее свободное числовое значение — максимум + 1 (0 у пустого списка).
function nextValue() {
    if (!priorities.value.length) return 0;
    return Math.max(...priorities.value.map((p) => Number(p.value) || 0)) + 1;
}

function openCreate() {
    createForm.value = { name: '', value: nextValue(), color: '#6c757d' };
    createOpen.value = true;
}
async function create() {
    if (!createForm.value.name.trim()) return;
    saving.value = true;
    try {
        await PriorityApi.create({
            name: createForm.value.name.trim(),
            value: Number(createForm.value.value),
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

function openEdit(row) {
    editForm.value = {
        id: row.id,
        name: row.name || '',
        value: Number(row.value) || 0,
        color: row.color || '#6c757d',
    };
    editOpen.value = true;
}
async function saveEdit() {
    if (!editForm.value.name.trim()) return;
    saving.value = true;
    try {
        await PriorityApi.update(editForm.value.id, {
            name: editForm.value.name.trim(),
            value: Number(editForm.value.value),
            color: editForm.value.color,
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

function removePriority(event, row) {
    confirm.require({
        target: event.currentTarget,
        message: t('mxboard_ui_struct_confirm_remove_priority', { name: row.name }),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('mxboard_ui_delete'),
        rejectLabel: t('mxboard_ui_cancel'),
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            try {
                await PriorityApi.remove(row.id);
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
            <Button :label="t('mxboard_ui_struct_new_priority')" icon="pi pi-plus" size="small" @click="openCreate" />
            <Button :label="t('mxboard_ui_refresh')" icon="pi pi-refresh" size="small" severity="secondary" outlined :loading="loading" @click="load" />
        </div>

        <div class="mxb-hint" style="margin-bottom: 8px">{{ t('mxboard_ui_struct_priorities_hint') }}</div>

        <DataTable :value="priorities" :loading="loading" size="small" striped-rows>
            <Column field="value" :header="t('mxboard_ui_struct_priority_value')" style="width: 120px" />
            <Column field="name" :header="t('mxboard_ui_struct_name')">
                <template #body="{ data }">
                    <Tag :value="data.name" :style="{ backgroundColor: data.color || '#6c757d', color: '#fff', border: 'none' }" />
                </template>
            </Column>
            <Column field="color" :header="t('mxboard_ui_struct_color')" style="width: 120px">
                <template #body="{ data }">
                    <span :style="{ display: 'inline-block', width: '24px', height: '24px', borderRadius: '4px', backgroundColor: data.color || '#6c757d', verticalAlign: 'middle' }" />
                    <span class="mxb-muted" style="margin-left: 8px">{{ data.color }}</span>
                </template>
            </Column>
            <Column style="width: 110px">
                <template #body="{ data }">
                    <Button icon="pi pi-pencil" size="small" severity="secondary" text @click="openEdit(data)" />
                    <Button icon="pi pi-trash" size="small" severity="danger" text :disabled="!canDelete" :title="canDelete ? '' : t('mxboard_err_priority_last')" @click="removePriority($event, data)" />
                </template>
            </Column>
            <template #empty><div class="mxb-empty">{{ t('mxboard_ui_struct_empty') }}</div></template>
        </DataTable>

        <!-- Новый приоритет -->
        <Dialog v-model:visible="createOpen" modal dismissable-mask :header="t('mxboard_ui_struct_new_priority')" :style="{ width: '480px' }">
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_name') }}</label>
                <InputText v-model="createForm.name" fluid />
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_priority_value') }}</label>
                <InputText v-model="createForm.value" type="number" min="0" step="1" fluid />
                <div class="mxb-hint">{{ t('mxboard_ui_struct_priority_value_hint') }}</div>
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

        <!-- Правка приоритета -->
        <Dialog v-model:visible="editOpen" modal dismissable-mask :header="t('mxboard_ui_struct_edit')" :style="{ width: '480px' }">
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_name') }}</label>
                <InputText v-model="editForm.name" fluid />
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_priority_value') }}</label>
                <InputText v-model="editForm.value" type="number" min="0" step="1" fluid />
                <div class="mxb-hint">{{ t('mxboard_ui_struct_priority_value_hint') }}</div>
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_color') }}</label>
                <div style="display: flex; align-items: center; gap: 8px">
                    <input type="color" v-model="editForm.color" style="width: 40px; height: 32px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer" />
                    <InputText v-model="editForm.color" fluid style="flex: 1" />
                </div>
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
