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

function set(key, value) {
    emit('update:modelValue', { ...model.value, [key]: value });
}

// Варианты для select: массив строк или [{value,label}] — приводим к общему виду.
function selectOptions(field) {
    const opts = field.options;
    if (!Array.isArray(opts)) return [];
    return opts.map((o) => (typeof o === 'object' ? o : { value: o, label: String(o) }));
}
</script>

<template>
    <div v-for="field in fields" :key="field.key" class="mxb-field">
        <label>
            {{ field.label }}
            <span v-if="field.required" class="mxb-req">*</span>
        </label>

        <textarea
            v-if="field.type === 'textarea'"
            :value="model[field.key] || ''"
            class="mxb-textarea"
            rows="4"
            @input="set(field.key, $event.target.value)"
        />

        <Select
            v-else-if="field.type === 'select'"
            :model-value="model[field.key] ?? null"
            :options="selectOptions(field)"
            option-label="label"
            option-value="value"
            placeholder="—"
            fluid
            @update:model-value="set(field.key, $event)"
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

        <InputText
            v-else
            :model-value="model[field.key] ?? ''"
            :type="field.type === 'number' ? 'number' : (field.type === 'url' ? 'url' : 'text')"
            fluid
            @update:model-value="set(field.key, $event)"
        />
    </div>
</template>
