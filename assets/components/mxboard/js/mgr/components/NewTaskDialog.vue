<script setup>
import { ref, watch } from 'vue';
import { Dialog, Button, InputText, Select, useToast } from 'primevue';
import { TaskApi, TypeApi, DepartmentApi, errorMessage, listOf } from '../api/connector.js';
import { PRIORITIES } from '../utils/format.js';
import TypeFields from './TypeFields.vue';

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

const form = ref({ type: '', title: '', tor: '', priority: 1, deadline: '', assignee_id: 0, fields: {} });

// При открытии — сбрасываем форму и подгружаем типы отдела и его пользователей.
watch(() => props.visible, async (open) => {
    if (!open) return;
    form.value = { type: '', title: '', tor: '', priority: 1, deadline: '', assignee_id: 0, fields: {} };
    schema.value = null;
    types.value = [];
    users.value = [];
    if (!props.departmentId) return;
    try {
        const [t, u] = await Promise.all([
            TypeApi.getList(props.departmentId),
            DepartmentApi.users(props.departmentId),
        ]);
        types.value = listOf(t);
        users.value = listOf(u);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Справочники не загружены', detail: errorMessage(e), life: 8000 });
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
        toast.add({ severity: 'error', summary: 'Схема типа не загружена', detail: errorMessage(e), life: 8000 });
    } finally {
        loadingType.value = false;
    }
});

async function save() {
    if (!form.value.type) {
        toast.add({ severity: 'warn', summary: 'Не выбран тип задачи', life: 4000 });
        return;
    }
    if (!form.value.title.trim()) {
        toast.add({ severity: 'warn', summary: 'Не указан заголовок', life: 4000 });
        return;
    }
    if (!form.value.deadline) {
        toast.add({ severity: 'warn', summary: 'Не указан дедлайн', life: 4000 });
        return;
    }
    if (!form.value.assignee_id) {
        toast.add({ severity: 'warn', summary: 'Не выбран исполнитель', life: 4000 });
        return;
    }

    saving.value = true;
    try {
        await TaskApi.create({
            project: props.projectKey,
            parent_id: props.parentId || 0,
            type: form.value.type,
            title: form.value.title.trim(),
            tor: form.value.tor,
            priority: form.value.priority,
            deadline: form.value.deadline,
            assignee_id: form.value.assignee_id,
            fields: form.value.fields,
        });
        toast.add({ severity: 'success', summary: 'Задача создана', life: 3000 });
        emit('update:visible', false);
        emit('created');
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Задача не создана', detail: errorMessage(e), life: 8000 });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        :header="parentId ? 'Новая подзадача' : 'Новая задача'"
        :style="{ width: '680px' }"
        @update:visible="emit('update:visible', $event)"
    >
        <div v-if="parentId" class="mxb-parent-note">
            <i class="pi pi-sitemap" /> Подзадача для: <strong>{{ parentTitle }}</strong>
        </div>

        <div class="mxb-field">
            <label>Тип задачи</label>
            <Select
                v-model="form.type"
                :options="types"
                option-label="name"
                option-value="key"
                placeholder="Выберите тип"
                fluid
            />
            <div v-if="!types.length" class="mxb-hint">В отделе нет типов задач — создайте их на вкладке «Структура».</div>
        </div>

        <div class="mxb-field">
            <label>Заголовок</label>
            <InputText v-model="form.title" fluid autofocus />
        </div>

        <div class="mxb-row">
            <div class="mxb-field mxb-col">
                <label>Дедлайн</label>
                <input v-model="form.deadline" type="date" class="mxb-input" />
            </div>
            <div class="mxb-field mxb-col">
                <label>Приоритет</label>
                <Select v-model="form.priority" :options="PRIORITIES" option-label="label" option-value="value" fluid />
            </div>
        </div>

        <div class="mxb-field">
            <label>Исполнитель</label>
            <Select
                v-model="form.assignee_id"
                :options="users"
                option-label="username"
                option-value="id"
                placeholder="Из отдела проекта"
                filter
                fluid
            />
        </div>

        <div class="mxb-field">
            <label>Постановка (ToR, markdown)</label>
            <textarea v-model="form.tor" class="mxb-textarea" rows="8" />
        </div>

        <!-- Динамические поля выбранного типа. -->
        <div v-if="loadingType" class="mxb-empty">Загрузка полей типа…</div>
        <TypeFields
            v-else-if="schema"
            v-model="form.fields"
            :fields="schema.fields"
            :users="users"
        />

        <template #footer>
            <div class="mxb-dialog-actions">
                <Button label="Отмена" severity="secondary" outlined @click="emit('update:visible', false)" />
                <Button label="Создать" icon="pi pi-check" :loading="saving" @click="save" />
            </div>
        </template>
    </Dialog>
</template>
