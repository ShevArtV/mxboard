<script setup>
import { computed } from 'vue';
import { InputText, Select } from 'primevue';

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
});
const emit = defineEmits(['update:modelValue']);

const model = computed(() => props.modelValue || {});
// Поля типа `files` в общей форме не рендерим — их зону рисуют TaskPage/NewTaskDialog.
const renderable = computed(() => (props.fields || []).filter((f) => f.type !== 'files'));

function set(key, value) {
    emit('update:modelValue', { ...model.value, [key]: value });
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

        <InputText
            v-else
            :model-value="model[field.key] ?? ''"
            :type="field.type === 'number' ? 'number' : (field.type === 'url' ? 'url' : 'text')"
            fluid
            @update:model-value="set(field.key, $event)"
        />
    </div>
</template>
