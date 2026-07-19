<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import {
    DataTable, Column, Button, InputText, Dialog, Select, Checkbox, useToast, useConfirm,
} from 'primevue';
import {
    DepartmentApi, TypeApi, FieldApi, errorMessage, listOf,
} from '../../api/connector.js';
import { t } from '../../utils/i18n.js';

const toast = useToast();
const confirm = useConfirm();

// Тип `files` — файловая зона задачи (drag-n-drop вложения в левой колонке).
const FIELD_TYPES = ['text', 'textarea', 'url', 'number', 'date', 'select', 'user', 'files'];
// Человеческие названия типов для выпадашки (ключ в БД остаётся из FIELD_TYPES).
const FIELD_TYPE_OPTIONS = FIELD_TYPES.map((key) => ({ value: key, label: t(`mxboard_ft_${key}`) }));

const departments = ref([]);
const departmentId = ref(0);
const types = ref([]);
const loading = ref(false);
const saving = ref(false);

// Развёрнутый тип → его поля. Раскрыт всегда не более одного типа: поля лежат в
// общем `fields`, поэтому две открытые строки показывали бы один и тот же список.
const expandedType = ref(0);
const fields = ref([]);

// При заданном data-key DataTable ждёт expandedRows ОБЪЕКТОМ `{ <id>: true }`
// (массив он читает только без data-key — DataTable.vue::toggleRow). Выводим его из
// `expandedType`, чтобы источник правды остался один: на нём завязаны диалоги полей.
const expandedRows = computed(() => (
    expandedType.value ? { [expandedType.value]: true } : {}
));

const createOpen = ref(false);
const createForm = ref({ key: '', name: '', description: '', ai_check: false, ai_prompt: '', fields: [] });

const editOpen = ref(false);
const editForm = ref({ id: 0, name: '', description: '', active: true, ai_check: false, ai_prompt: '' });

const fieldOpen = ref(false);
const fieldForm = ref({ id: 0, task_type_id: 0, key: '', label: '', type: 'text', required: false, options: '' });

// Варианты select'а редактируются одной строкой через `|` — и в диалоге поля, и в
// строке-конструкторе при создании типа. Разделитель один на оба места, чтобы не
// заводить два формата ввода для одного и того же поля модели.
const OPTIONS_SEPARATOR = '|';
function optionsToText(value) {
    return Array.isArray(value) ? value.join(OPTIONS_SEPARATOR) : '';
}
function optionsToList(text, type) {
    if (type !== 'select') return null;
    const list = String(text || '').split(OPTIONS_SEPARATOR).map((s) => s.trim()).filter(Boolean);
    return list.length ? list : null;
}

onMounted(async () => {
    try {
        departments.value = listOf(await DepartmentApi.getList());
        if (departments.value.length) {
            departmentId.value = Number(departments.value[0].id) || 0;
        }
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_refs_load'), detail: errorMessage(e), life: 8000 });
    }
});

watch(departmentId, loadTypes, { immediate: false });
watch(departmentId, () => { expandedType.value = 0; });

async function loadTypes() {
    if (!departmentId.value) { types.value = []; return; }
    loading.value = true;
    try {
        types.value = listOf(await TypeApi.getList(departmentId.value));
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_refs_load'), detail: errorMessage(e), life: 8000 });
    } finally {
        loading.value = false;
    }
}

async function onRowExpand(event) {
    const type = event.data;
    expandedType.value = Number(type.id) || 0;
    fields.value = [];
    try {
        fields.value = listOf(await FieldApi.getList(type.id));
    } catch (e) {
        fields.value = [];
        toast.add({ severity: 'error', summary: t('mxboard_msg_refs_load'), detail: errorMessage(e), life: 8000 });
    }
}
function onRowCollapse() {
    expandedType.value = 0;
    fields.value = [];
}

// --- Тип ---
function openCreate() {
    createForm.value = {
        key: '', name: '', description: '', ai_check: false, ai_prompt: '',
        fields: [{ key: '', label: '', type: 'text', required: true }],
    };
    createOpen.value = true;
}
function addCreateField() {
    createForm.value.fields.push({ key: '', label: '', type: 'text', required: false, options: '' });
}
function removeCreateField(i) {
    createForm.value.fields.splice(i, 1);
}
async function create() {
    if (!createForm.value.key.trim() || !createForm.value.name.trim()) return;
    if (!createForm.value.fields.length) return;
    saving.value = true;
    try {
        await TypeApi.create({
            department_id: departmentId.value,
            key: createForm.value.key.trim(),
            name: createForm.value.name.trim(),
            description: createForm.value.description,
            ai_check: createForm.value.ai_check ? 1 : 0,
            ai_prompt: createForm.value.ai_prompt,
            fields: createForm.value.fields.map((f) => ({ ...f, options: optionsToList(f.options, f.type) })),
        });
        toast.add({ severity: 'success', summary: t('mxboard_ui_struct_created'), life: 3000 });
        createOpen.value = false;
        loadTypes();
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    } finally {
        saving.value = false;
    }
}

function openEdit(type) {
    editForm.value = {
        id: type.id, name: type.name || '', description: type.description || '',
        active: type.active !== false && type.active !== 0,
        ai_check: type.ai_check === true || type.ai_check === 1,
        ai_prompt: type.ai_prompt || '',
    };
    editOpen.value = true;
}
async function saveEdit() {
    saving.value = true;
    try {
        await TypeApi.update(editForm.value.id, {
            name: editForm.value.name,
            description: editForm.value.description,
            active: editForm.value.active ? 1 : 0,
            ai_check: editForm.value.ai_check ? 1 : 0,
            ai_prompt: editForm.value.ai_prompt,
        });
        toast.add({ severity: 'success', summary: t('mxboard_ui_struct_saved'), life: 3000 });
        editOpen.value = false;
        loadTypes();
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    } finally {
        saving.value = false;
    }
}
function removeType(event, type) {
    confirm.require({
        target: event.currentTarget,
        message: t('mxboard_ui_struct_confirm_remove_type', { name: type.name }),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('mxboard_ui_delete'),
        rejectLabel: t('mxboard_ui_cancel'),
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            try {
                await TypeApi.remove(type.id);
                toast.add({ severity: 'success', summary: t('mxboard_ui_struct_removed'), life: 3000 });
                loadTypes();
            } catch (e) {
                toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
            }
        },
    });
}

// --- Поле существующего типа ---
function openAddField(typeId) {
    fieldForm.value = { id: 0, task_type_id: typeId, key: '', label: '', type: 'text', required: false, options: '' };
    fieldOpen.value = true;
}
function openEditField(field) {
    fieldForm.value = {
        id: field.id, task_type_id: expandedType.value, key: field.key,
        label: field.label || '', type: field.type || 'text',
        required: field.required === true || field.required === 1,
        options: optionsToText(field.options),
    };
    fieldOpen.value = true;
}
async function saveField() {
    if (!fieldForm.value.label.trim()) return;
    saving.value = true;
    try {
        if (fieldForm.value.id) {
            await FieldApi.update(fieldForm.value.id, {
                label: fieldForm.value.label, type: fieldForm.value.type, required: fieldForm.value.required ? 1 : 0,
                options: optionsToList(fieldForm.value.options, fieldForm.value.type),
            });
        } else {
            if (!fieldForm.value.key.trim()) { saving.value = false; return; }
            await FieldApi.create({
                task_type_id: fieldForm.value.task_type_id, key: fieldForm.value.key.trim(),
                label: fieldForm.value.label.trim(), type: fieldForm.value.type, required: fieldForm.value.required ? 1 : 0,
                options: optionsToList(fieldForm.value.options, fieldForm.value.type),
            });
        }
        toast.add({ severity: 'success', summary: t('mxboard_ui_struct_saved'), life: 3000 });
        fieldOpen.value = false;
        fields.value = listOf(await FieldApi.getList(fieldForm.value.task_type_id));
        loadTypes();
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    } finally {
        saving.value = false;
    }
}
function removeField(event, field) {
    confirm.require({
        target: event.currentTarget,
        message: t('mxboard_ui_struct_confirm_remove_field', { name: field.label || field.key }),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('mxboard_ui_delete'),
        rejectLabel: t('mxboard_ui_cancel'),
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            try {
                await FieldApi.remove(field.id);
                toast.add({ severity: 'success', summary: t('mxboard_ui_struct_removed'), life: 3000 });
                fields.value = listOf(await FieldApi.getList(expandedType.value));
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
            <Select v-model="departmentId" :options="departments" option-label="name" option-value="id" :placeholder="t('mxboard_ui_struct_pick_department')" />
            <Button :label="t('mxboard_ui_struct_new_type')" icon="pi pi-plus" size="small" :disabled="!departmentId" @click="openCreate" />
            <Button :label="t('mxboard_ui_refresh')" icon="pi pi-refresh" size="small" severity="secondary" outlined :loading="loading" @click="loadTypes" />
        </div>

        <!-- Поля раскрываются строкой-расширением ПОД своим типом: общий блок под
             таблицей терял привязку списка к конкретному типу. -->
        <DataTable
            :value="types"
            :loading="loading"
            size="small"
            striped-rows
            data-key="id"
            :expanded-rows="expandedRows"
            @row-expand="onRowExpand"
            @row-collapse="onRowCollapse"
        >
            <Column expander style="width: 48px" />
            <Column field="key" :header="t('mxboard_ui_struct_key')" style="width: 160px" />
            <Column field="name" :header="t('mxboard_ui_struct_name')" />
            <Column style="width: 110px">
                <template #body="{ data }">
                    <Button icon="pi pi-pencil" size="small" severity="secondary" text @click="openEdit(data)" />
                    <Button icon="pi pi-trash" size="small" severity="danger" text @click="removeType($event, data)" />
                </template>
            </Column>
            <template #expansion="{ data }">
                <div class="mxb-fields-panel">
                    <div class="mxb-section-title">
                        <i class="pi pi-list" />{{ t('mxboard_ui_struct_type_fields') }}
                        <span class="mxb-toolbar-spacer" />
                        <Button :label="t('mxboard_ui_struct_add_field')" icon="pi pi-plus" size="small" severity="secondary" outlined @click="openAddField(data.id)" />
                    </div>
                    <div v-for="f in fields" :key="f.id" class="mxb-fieldrow">
                        <span class="mxb-fieldrow-label">{{ f.label }} <code>{{ f.key }}</code></span>
                        <span class="mxb-chip">{{ f.type }}</span>
                        <span v-if="f.required" class="mxb-req">*</span>
                        <span class="mxb-toolbar-spacer" />
                        <Button icon="pi pi-pencil" size="small" severity="secondary" text @click="openEditField(f)" />
                        <Button icon="pi pi-trash" size="small" severity="danger" text @click="removeField($event, f)" />
                    </div>
                    <div v-if="!fields.length" class="mxb-empty">{{ t('mxboard_ui_struct_empty') }}</div>
                </div>
            </template>
            <template #empty><div class="mxb-empty">{{ t('mxboard_ui_struct_empty') }}</div></template>
        </DataTable>

        <!-- Новый тип -->
        <Dialog v-model:visible="createOpen" modal :header="t('mxboard_ui_struct_new_type')" :style="{ width: '680px' }">
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
            <div class="mxb-field mxb-check">
                <Checkbox v-model="createForm.ai_check" :binary="true" input-id="create-ai-check" />
                <label for="create-ai-check">{{ t('mxboard_ui_struct_ai_check') }}</label>
            </div>
            <div v-if="createForm.ai_check" class="mxb-field">
                <label>{{ t('mxboard_ui_struct_ai_prompt') }}</label>
                <textarea v-model="createForm.ai_prompt" class="mxb-textarea" rows="4" :placeholder="t('mxboard_ui_struct_ai_prompt_hint')" />
            </div>

            <div class="mxb-section-title">
                <i class="pi pi-list" />{{ t('mxboard_ui_struct_type_fields') }}
                <span class="mxb-toolbar-spacer" />
                <Button :label="t('mxboard_ui_struct_add_field')" icon="pi pi-plus" size="small" severity="secondary" outlined @click="addCreateField" />
            </div>
            <template v-for="(f, i) in createForm.fields" :key="i">
                <div class="mxb-field-editrow">
                    <InputText v-model="f.key" :placeholder="t('mxboard_ui_struct_field_key')" />
                    <InputText v-model="f.label" :placeholder="t('mxboard_ui_struct_field_label')" />
                    <Select v-model="f.type" :options="FIELD_TYPE_OPTIONS" option-label="label" option-value="value" />
                    <label class="mxb-check"><Checkbox v-model="f.required" :binary="true" /> {{ t('mxboard_ui_struct_field_required') }}</label>
                    <Button icon="pi pi-times" size="small" severity="danger" text @click="removeCreateField(i)" />
                </div>
                <InputText v-if="f.type === 'select'" v-model="f.options" :placeholder="t('mxboard_ui_struct_field_options_hint')" fluid />
            </template>

            <template #footer>
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined @click="createOpen = false" />
                    <Button :label="t('mxboard_ui_create')" icon="pi pi-check" :loading="saving" @click="create" />
                </div>
            </template>
        </Dialog>

        <!-- Правка типа -->
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
                <Checkbox v-model="editForm.active" :binary="true" input-id="type-active" />
                <label for="type-active">{{ t('mxboard_ui_struct_active') }}</label>
            </div>
            <div class="mxb-field mxb-check">
                <Checkbox v-model="editForm.ai_check" :binary="true" input-id="type-ai-check" />
                <label for="type-ai-check">{{ t('mxboard_ui_struct_ai_check') }}</label>
            </div>
            <div v-if="editForm.ai_check" class="mxb-field">
                <label>{{ t('mxboard_ui_struct_ai_prompt') }}</label>
                <textarea v-model="editForm.ai_prompt" class="mxb-textarea" rows="4" :placeholder="t('mxboard_ui_struct_ai_prompt_hint')" />
            </div>
            <template #footer>
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined @click="editOpen = false" />
                    <Button :label="t('mxboard_ui_save')" icon="pi pi-check" :loading="saving" @click="saveEdit" />
                </div>
            </template>
        </Dialog>

        <!-- Поле (добавить/править) -->
        <Dialog v-model:visible="fieldOpen" modal :header="fieldForm.id ? t('mxboard_ui_struct_edit') : t('mxboard_ui_struct_add_field')" :style="{ width: '480px' }">
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_field_key') }}</label>
                <InputText v-model="fieldForm.key" :disabled="!!fieldForm.id" fluid />
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_field_label') }}</label>
                <InputText v-model="fieldForm.label" fluid />
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_field_type') }}</label>
                <Select v-model="fieldForm.type" :options="FIELD_TYPE_OPTIONS" option-label="label" option-value="value" fluid />
            </div>
            <div v-if="fieldForm.type === 'select'" class="mxb-field">
                <label>{{ t('mxboard_ui_struct_field_options') }}</label>
                <InputText v-model="fieldForm.options" :placeholder="t('mxboard_ui_struct_field_options_hint')" fluid />
            </div>
            <div class="mxb-field mxb-check">
                <Checkbox v-model="fieldForm.required" :binary="true" input-id="field-required" />
                <label for="field-required">{{ t('mxboard_ui_struct_field_required') }}</label>
            </div>
            <template #footer>
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined @click="fieldOpen = false" />
                    <Button :label="t('mxboard_ui_save')" icon="pi pi-check" :loading="saving" @click="saveField" />
                </div>
            </template>
        </Dialog>
    </div>
</template>
