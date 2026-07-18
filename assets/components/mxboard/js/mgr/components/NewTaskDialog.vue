<script setup>
import { ref, computed, watch } from 'vue';
import { Dialog, Button, InputText, Select, useToast } from 'primevue';
import { TaskApi, TypeApi, DepartmentApi, AttachmentApi, errorMessage, listOf } from '../api/connector.js';
import { PRIORITIES, fmtSize } from '../utils/format.js';
import { t } from '../utils/i18n.js';
import TypeFields from './TypeFields.vue';
import FileDrop from './FileDrop.vue';

const props = defineProps({
    visible: { type: Boolean, default: false },
    departmentId: { type: Number, default: 0 },
    projectKey: { type: String, default: '' },
    // >0 — создаём подзадачу указанной задачи (тот же проект).
    parentId: { type: Number, default: 0 },
    parentTitle: { type: String, default: '' },
});
const emit = defineEmits(['update:visible', 'created']);

const toast = useToast();

const types = ref([]);
const users = ref([]);
const schema = ref(null);
const loadingType = ref(false);
const saving = ref(false);

// Вердикт ИИ-проверки полноты (когда сервер отклонил постановку). В soft-режиме
// показываем кнопку «всё равно создать» (canOverride), в strict — только чего не хватает.
const aiVerdict = ref(null);
const aiCanOverride = ref(false);

const form = ref({ type: '', title: '', tor: '', priority: 1, deadline: '', assignee_id: 0, fields: {} });
// Файлы, приложенные ДО создания задачи: копятся в памяти, грузятся после успешного create.
const pendingFiles = ref([]);
// Файловая зона показывается, только если у выбранного типа есть поле `files` (лейбл = его заголовок).
const filesField = computed(() => (schema.value?.fields || []).find((f) => f.type === 'files') || null);

// При открытии — сбрасываем форму и подгружаем типы отдела и его пользователей.
watch(() => props.visible, async (open) => {
    if (!open) return;
    form.value = { type: '', title: '', tor: '', priority: 1, deadline: '', assignee_id: 0, fields: {} };
    pendingFiles.value = [];
    aiVerdict.value = null;
    aiCanOverride.value = false;
    schema.value = null;
    types.value = [];
    users.value = [];
    if (!props.departmentId) return;
    try {
        const [ty, u] = await Promise.all([
            TypeApi.getList(props.departmentId),
            DepartmentApi.users(props.departmentId),
        ]);
        types.value = listOf(ty);
        users.value = listOf(u);
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_refs_load'), detail: errorMessage(e), life: 8000 });
    }
});

// Смена типа → тянем схему (builtin + поля) под текущий проект и чистим значения полей.
watch(() => form.value.type, async (typeKey) => {
    schema.value = null;
    form.value.fields = {};
    if (!typeKey || !props.projectKey) return;
    loadingType.value = true;
    try {
        const res = await TypeApi.schema({ project: props.projectKey, type: typeKey });
        schema.value = res.object ?? null;
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_schema_load'), detail: errorMessage(e), life: 8000 });
    } finally {
        loadingType.value = false;
    }
});

// override=true — повтор после «неполной» оценки в soft-режиме (создать в обход ИИ).
async function save(override = false) {
    if (!form.value.type) {
        toast.add({ severity: 'warn', summary: t('mxboard_msg_warn_no_type'), life: 4000 });
        return;
    }
    if (!form.value.title.trim()) {
        toast.add({ severity: 'warn', summary: t('mxboard_msg_warn_no_title'), life: 4000 });
        return;
    }
    if (!form.value.deadline) {
        toast.add({ severity: 'warn', summary: t('mxboard_msg_warn_no_deadline'), life: 4000 });
        return;
    }
    if (!form.value.assignee_id) {
        toast.add({ severity: 'warn', summary: t('mxboard_msg_warn_no_assignee'), life: 4000 });
        return;
    }

    if (!override) {
        aiVerdict.value = null;
        aiCanOverride.value = false;
    }

    saving.value = true;
    try {
        const res = await TaskApi.create({
            project: props.projectKey,
            parent_id: props.parentId || 0,
            type: form.value.type,
            title: form.value.title.trim(),
            tor: form.value.tor,
            priority: form.value.priority,
            deadline: form.value.deadline,
            assignee_id: form.value.assignee_id,
            fields: form.value.fields,
            ai_override: override ? 1 : 0,
        });
        // Задача создана — теперь есть task_id, грузим приложенные заранее файлы (best-effort).
        const newId = Number(res?.object?.id) || 0;
        if (newId && pendingFiles.value.length) {
            try {
                const up = await AttachmentApi.upload(newId, 0, pendingFiles.value);
                if (up?.message) {
                    toast.add({ severity: 'warn', summary: t('mxboard_msg_upload_partial'), detail: up.message, life: 8000 });
                }
            } catch (upErr) {
                // Задача уже создана — файл не критичен, не откатываем, только предупреждаем.
                toast.add({ severity: 'warn', summary: t('mxboard_err_upload_failed'), detail: errorMessage(upErr), life: 8000 });
            }
        }
        toast.add({ severity: 'success', summary: t('mxboard_msg_task_created'), life: 3000 });
        emit('update:visible', false);
        emit('created');
    } catch (e) {
        // ИИ-проверка отклонила постановку: показываем чего не хватает прямо в форме.
        const info = e?.data?.object;
        if (info && info.ai_incomplete) {
            aiVerdict.value = info.verdict || null;
            aiCanOverride.value = !!info.can_override;
        } else {
            toast.add({ severity: 'error', summary: t('mxboard_msg_task_not_created'), detail: errorMessage(e), life: 8000 });
        }
    } finally {
        saving.value = false;
    }
}

// Файлы приходят из FileDrop уже обрезанными по лимиту — просто копим.
function addStagedFiles(files) {
    if (files && files.length) pendingFiles.value = pendingFiles.value.concat(files);
}

function removeStaged(idx) {
    pendingFiles.value = pendingFiles.value.filter((_, i) => i !== idx);
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        :header="parentId ? t('mxboard_ui_new_subtask') : t('mxboard_ui_new_task')"
        :style="{ width: '680px' }"
        @update:visible="emit('update:visible', $event)"
    >
        <div v-if="parentId" class="mxb-parent-note">
            <i class="pi pi-sitemap" /> {{ t('mxboard_ui_subtask_for') }}: <strong>{{ parentTitle }}</strong>
        </div>

        <div class="mxb-field">
            <label>{{ t('mxboard_ui_task_type') }}</label>
            <Select
                v-model="form.type"
                :options="types"
                option-label="name"
                option-value="key"
                :placeholder="t('mxboard_ui_select_type')"
                fluid
            />
            <div v-if="!types.length" class="mxb-hint">{{ t('mxboard_ui_no_types') }}</div>
        </div>

        <div class="mxb-field">
            <label>{{ t('mxboard_ui_title') }}</label>
            <InputText v-model="form.title" fluid autofocus />
        </div>

        <div class="mxb-row">
            <div class="mxb-field mxb-col">
                <label>{{ t('mxboard_ui_deadline') }}</label>
                <input v-model="form.deadline" type="date" class="mxb-input" />
            </div>
            <div class="mxb-field mxb-col">
                <label>{{ t('mxboard_ui_priority') }}</label>
                <Select v-model="form.priority" :options="PRIORITIES" option-label="label" option-value="value" fluid />
            </div>
        </div>

        <div class="mxb-field">
            <label>{{ t('mxboard_ui_assignee') }}</label>
            <Select
                v-model="form.assignee_id"
                :options="users"
                option-label="username"
                option-value="id"
                :placeholder="t('mxboard_ui_assignee_placeholder')"
                filter
                fluid
            />
        </div>

        <!-- Динамические поля выбранного типа. -->
        <div v-if="loadingType" class="mxb-empty">{{ t('mxboard_ui_loading_fields') }}</div>
        <TypeFields
            v-else-if="schema"
            v-model="form.fields"
            :fields="schema.fields"
            :users="users"
        />

        <!-- Файловая зона типа `files`: копится до создания, грузится после сохранения. -->
        <div v-if="filesField" class="mxb-field">
            <label>{{ filesField.label || t('mxboard_ui_task_files') }}</label>
            <div v-if="pendingFiles.length" class="mxb-composer-files mxb-staged-files">
                <span v-for="(f, i) in pendingFiles" :key="i" class="mxb-composer-file">
                    <i class="pi pi-file" />
                    <span class="mxb-composer-file-name" :title="f.name">{{ f.name }}</span>
                    <span class="mxb-composer-file-size">{{ fmtSize(f.size) }}</span>
                    <button type="button" class="mxb-composer-file-x" :title="t('mxboard_ui_cancel')" @click="removeStaged(i)"><i class="pi pi-times" /></button>
                </span>
            </div>
            <FileDrop :busy="saving" :already="pendingFiles.length" @files="addStagedFiles" />
        </div>

        <!-- Вердикт ИИ-проверки полноты постановки -->
        <div v-if="aiVerdict" class="mxb-ai-verdict">
            <div class="mxb-ai-verdict-head">
                <i class="pi pi-sparkles" /> {{ t('mxboard_ui_ai_incomplete') }}
                <span v-if="typeof aiVerdict.score === 'number'" class="mxb-chip">{{ aiVerdict.score }}/100</span>
            </div>
            <div v-if="aiVerdict.summary" class="mxb-ai-verdict-summary">{{ aiVerdict.summary }}</div>
            <ul v-if="aiVerdict.missing && aiVerdict.missing.length" class="mxb-ai-verdict-missing">
                <li v-for="(m, i) in aiVerdict.missing" :key="i">{{ m }}</li>
            </ul>
        </div>

        <template #footer>
            <div class="mxb-dialog-actions">
                <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined @click="emit('update:visible', false)" />
                <Button
                    v-if="aiVerdict && aiCanOverride"
                    :label="t('mxboard_ui_ai_create_anyway')"
                    icon="pi pi-exclamation-triangle"
                    severity="warn"
                    outlined
                    :loading="saving"
                    @click="save(true)"
                />
                <Button :label="t('mxboard_ui_create')" icon="pi pi-check" :loading="saving" @click="save(false)" />
            </div>
        </template>
    </Dialog>
</template>
