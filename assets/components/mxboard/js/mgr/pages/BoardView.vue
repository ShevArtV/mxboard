<script setup>
import { ref, computed, onMounted } from 'vue';
import { Button, Select, SelectButton, useToast } from 'primevue';
import {
    BoardApi, TaskApi, DepartmentApi, ProjectApi, errorMessage, listOf,
} from '../api/connector.js';
import { normalizeBoard, normalizeTask } from '../utils/format.js';
import TaskCard from '../components/TaskCard.vue';
import NewTaskDialog from '../components/NewTaskDialog.vue';
import TaskPage from './TaskPage.vue';

const toast = useToast();
const cfg = window.MxBoardConfig || {};
const userId = Number(cfg.user_id) || 0;

const departments = ref([]);
const projects = ref([]);
const departmentId = ref(0);
const projectKey = ref('');

const columns = ref([]);
const loading = ref(false);

// Фильтр видимости на клиенте — «все» (менеджерский срез) / только свои роли.
const FILTERS = [
    { value: 'all', label: 'Все' },
    { value: 'author', label: 'Я автор' },
    { value: 'assignee', label: 'Я исполнитель' },
];
const filter = ref('all');

// Ручной switch «доска ↔ страница задачи» (без vue-router).
const openTaskId = ref(0);

const dragTaskKey = ref('');
const dragFromKey = ref('');
const dragOverKey = ref('');
const createOpen = ref(false);

const selectedProject = computed(() => projects.value.find((p) => p.key === projectKey.value) || null);
// Проекты выбранного отдела (у проекта есть department_id).
const projectsInDepartment = computed(
    () => projects.value.filter((p) => Number(p.department_id) === departmentId.value),
);

onMounted(init);

async function init() {
    try {
        const [d, p] = await Promise.all([DepartmentApi.getList(), ProjectApi.getList()]);
        departments.value = listOf(d);
        projects.value = listOf(p);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Справочники не загружены', detail: errorMessage(e), life: 8000 });
        return;
    }
    if (departments.value.length) {
        departmentId.value = Number(departments.value[0].id) || 0;
        pickFirstProject();
        if (projectKey.value) load();
    }
}

function pickFirstProject() {
    const first = projectsInDepartment.value[0];
    projectKey.value = first ? first.key : '';
}

function onDepartmentChange() {
    pickFirstProject();
    columns.value = [];
    if (projectKey.value) load();
}

async function load() {
    if (!projectKey.value) {
        columns.value = [];
        return;
    }
    loading.value = true;
    try {
        const res = await BoardApi.get({
            project: projectKey.value,
            mine: filter.value === 'assignee' ? 1 : 0,
            author_id: filter.value === 'author' ? userId : 0,
            assignee_id: filter.value === 'assignee' ? userId : 0,
        });
        columns.value = normalizeBoard(res).columns;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Доска не загружена', detail: errorMessage(e), life: 8000 });
    } finally {
        loading.value = false;
    }
}

function openTask(task) {
    openTaskId.value = task.id;
}

function onDragStart(task, column, ev) {
    dragTaskKey.value = String(task.id);
    dragFromKey.value = column.key;
    if (ev.dataTransfer) {
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', String(task.id));
    }
}

function onDragEnd() {
    dragTaskKey.value = '';
    dragFromKey.value = '';
    dragOverKey.value = '';
}

function onDragOver(column, ev) {
    if (!dragTaskKey.value) return;
    ev.preventDefault();
    if (ev.dataTransfer) ev.dataTransfer.dropEffect = 'move';
    dragOverKey.value = column.key;
}

/**
 * Оптимистичное перемещение: карточка едет сразу, запрос — следом. Сервер —
 * источник истины по правам перехода (в финальную пускают только автора), поэтому
 * при отказе возвращаем карточку на прежнее место.
 */
async function onDrop(column) {
    const taskId = Number(dragTaskKey.value);
    const fromKey = dragFromKey.value;
    onDragEnd();

    if (!taskId || !fromKey || fromKey === column.key) return;

    const from = columns.value.find((c) => c.key === fromKey);
    if (!from) return;
    const index = from.tasks.findIndex((t) => t.id === taskId);
    if (index === -1) return;
    const task = from.tasks[index];

    // Клиентская страховка: заведомо запрещённое закрытие не гоняем на сервер.
    if (column.is_final && !cfg.can_move_any && task.author_id !== userId) {
        toast.add({ severity: 'warn', summary: 'Закрыть задачу может только её автор', life: 6000 });
        return;
    }

    from.tasks.splice(index, 1);
    column.tasks.push(task);
    task.column_key = column.key;

    try {
        const res = await TaskApi.move(taskId, column.key);
        if (res.object) Object.assign(task, normalizeTask(res.object));
    } catch (e) {
        const back = column.tasks.findIndex((t) => t.id === taskId);
        if (back !== -1) column.tasks.splice(back, 1);
        task.column_key = fromKey;
        from.tasks.splice(index, 0, task);
        toast.add({ severity: 'error', summary: 'Перемещение отклонено', detail: errorMessage(e), life: 8000 });
    }
}
</script>

<template>
    <!-- Страница задачи заменяет доску целиком (ручной switch). -->
    <TaskPage
        v-if="openTaskId"
        :task-id="openTaskId"
        :department-id="departmentId"
        :project-key="projectKey"
        :can-move-any="!!cfg.can_move_any"
        :user-id="userId"
        @back="openTaskId = 0"
        @open-task="openTaskId = $event"
        @changed="load"
    />

    <div v-else>
        <div class="mxb-toolbar">
            <Select
                v-model="departmentId"
                :options="departments"
                option-label="name"
                option-value="id"
                placeholder="Отдел"
                @change="onDepartmentChange"
            />
            <Select
                v-model="projectKey"
                :options="projectsInDepartment"
                option-label="name"
                option-value="key"
                placeholder="Проект"
                @change="load"
            />
            <SelectButton
                v-model="filter"
                :options="FILTERS"
                option-label="label"
                option-value="value"
                :allow-empty="false"
                @change="load"
            />
            <span class="mxb-toolbar-spacer" />
            <Button
                label="Новая задача"
                icon="pi pi-plus"
                size="small"
                :disabled="!projectKey"
                @click="createOpen = true"
            />
            <Button
                label="Обновить"
                icon="pi pi-refresh"
                size="small"
                severity="secondary"
                outlined
                :loading="loading"
                @click="load"
            />
        </div>

        <div v-if="!projectKey" class="mxb-empty">
            Нет проектов в этом отделе. Создайте проект на вкладке «Структура».
        </div>

        <div v-else class="mxb-columns">
            <div
                v-for="column in columns"
                :key="column.key"
                class="mxb-column"
                :class="{ 'mxb-column--over': dragOverKey === column.key }"
                @dragover="onDragOver(column, $event)"
                @dragleave="dragOverKey = dragOverKey === column.key ? '' : dragOverKey"
                @drop.prevent="onDrop(column)"
            >
                <div class="mxb-column-head">
                    <i v-if="column.is_final" class="pi pi-check-circle" />
                    <span>{{ column.name }}</span>
                    <span class="mxb-column-count">{{ column.tasks.length }}</span>
                </div>
                <div class="mxb-column-body">
                    <TaskCard
                        v-for="task in column.tasks"
                        :key="task.id"
                        :task="task"
                        :dragging="dragTaskKey === String(task.id)"
                        @open="openTask(task)"
                        @dragstart="onDragStart(task, column, $event)"
                        @dragend="onDragEnd"
                    />
                    <div v-if="!column.tasks.length" class="mxb-empty">Пусто</div>
                </div>
            </div>
        </div>

        <NewTaskDialog
            v-model:visible="createOpen"
            :department-id="departmentId"
            :project-key="projectKey"
            @created="load"
        />
    </div>
</template>
