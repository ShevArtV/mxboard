<script setup>
import { computed, ref } from 'vue';
import { InputText, Select, Button, useToast } from 'primevue';
import { AttachmentApi, errorMessage } from '../api/connector.js';
import { fmtSize } from '../utils/format.js';
import { t } from '../utils/i18n.js';

/**
 * Рендер полей типа по его схеме (mxboard_field). Значения — плоский объект
 * {field_key: value}, ровно как task.fields в БД. Двусторонняя связь через v-model.
 */
const props = defineProps({
    // { key: value } — текущие значения полей.
    modelValue: { type: Object, default: () => ({}) },
    // Схема полей типа: [{ key, label, type, required, options }].
    fields: { type: Array, default: () => [] },
    // Для полей type=user — кандидаты (члены отдела).
    users: { type: Array, default: () => [] },
    // Задача, к которой грузятся файлы полей типа file (0 — при создании: загрузка недоступна).
    taskId: { type: Number, default: 0 },
});
const emit = defineEmits(['update:modelValue']);

const toast = useToast();
const model = computed(() => props.modelValue || {});
const uploadingKey = ref('');
// Поля типа `files` в общей форме не рендерим — их зону рисуют TaskPage/NewTaskDialog.
const renderable = computed(() => (props.fields || []).filter((f) => f.type !== 'files'));

function set(key, value) {
    emit('update:modelValue', { ...model.value, [key]: value });
}

// Загрузка файла поля типа file: грузим в задачу (comment_id=0), в значение поля
// кладём URL вложения. При создании (taskId=0) загрузка недоступна — задачи ещё нет.
async function onFieldFile(key, event) {
    const file = (event.target.files || [])[0];
    event.target.value = '';
    if (!file) return;
    if (!props.taskId) {
        toast.add({ severity: 'warn', summary: t('mxboard_ui_file_after_save'), life: 5000 });
        return;
    }
    uploadingKey.value = key;
    try {
        const up = await AttachmentApi.upload(props.taskId, 0, [file]);
        const url = up?.object?.attachments?.[0]?.url || '';
        if (url) set(key, url);
        else toast.add({ severity: 'error', summary: t('mxboard_err_upload_failed'), life: 6000 });
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_err_upload_failed'), detail: errorMessage(e), life: 8000 });
    } finally {
        uploadingKey.value = '';
    }
}
</script>

<template>
    <div v-for="field in renderable" :key="field.key" class="mxb-field">
        <label>
            {{ field.label }}
            <span v-if="field.required" class="mxb-req">*</span>
        </label>

        <textarea
            v-if="field.type === 'textarea' || field.type === 'text'"
            :value="model[field.key] || ''"
            class="mxb-textarea"
            rows="4"
            @input="set(field.key, $event.target.value)"
        />

        <Select
            v-else-if="field.type === 'user'"
            :model-value="model[field.key] ?? null"
            :options="users"
            option-label="username"
            option-value="id"
            placeholder="—"
            filter
            fluid
            @update:model-value="set(field.key, $event)"
        />

        <input
            v-else-if="field.type === 'date'"
            :value="model[field.key] || ''"
            type="date"
            class="mxb-input"
            @input="set(field.key, $event.target.value)"
        />

        <!-- Список: варианты заданы на поле типа (options). Без них выбирать не из чего,
             поэтому пустой список падает в обычный ввод ниже. -->
        <Select
            v-else-if="field.type === 'select' && Array.isArray(field.options) && field.options.length"
            :model-value="model[field.key] ?? ''"
            :options="field.options"
            :placeholder="field.label"
            show-clear
            fluid
            @update:model-value="set(field.key, $event)"
        />

        <!-- Файл: загрузка в задачу (значение поля — URL вложения). -->
        <div v-else-if="field.type === 'file'" class="mxb-filefield">
            <a v-if="model[field.key]" :href="model[field.key]" target="_blank" rel="noopener" download class="mxb-fieldrow-link mxb-filefield-current">
                <i class="pi pi-paperclip" /> {{ t('mxboard_ui_download') }}
            </a>
            <label class="mxb-filefield-btn">
                <i :class="uploadingKey === field.key ? 'pi pi-spin pi-spinner' : 'pi pi-upload'" />
                {{ model[field.key] ? t('mxboard_ui_file_replace') : t('mxboard_ui_attach_file') }}
                <input type="file" class="mxb-file-hidden" :disabled="uploadingKey === field.key" @change="onFieldFile(field.key, $event)" />
            </label>
            <Button v-if="model[field.key]" icon="pi pi-times" size="small" severity="secondary" text :title="t('mxboard_ui_clear')" @click="set(field.key, '')" />
            <span v-if="!taskId" class="mxb-filefield-hint">{{ t('mxboard_ui_file_after_save') }}</span>
        </div>

        <InputText
            v-else
            :model-value="model[field.key] ?? ''"
            :type="field.type === 'number' ? 'number' : (field.type === 'url' ? 'url' : 'text')"
            fluid
            @update:model-value="set(field.key, $event)"
        />
    </div>
</template>
