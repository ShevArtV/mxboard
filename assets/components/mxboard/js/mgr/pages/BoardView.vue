<script setup>
import { ref, onMounted } from 'vue';
import { Button, useToast } from 'primevue';
import { BoardApi, TaskApi, errorMessage, boardConfig } from '../api/connector.js';
import { normalizeBoard, normalizeTask } from '../utils/format.js';
import TaskCard from '../components/TaskCard.vue';
import TaskDialog from '../components/TaskDialog.vue';
import NewTaskDialog from '../components/NewTaskDialog.vue';

const toast = useToast();
const cfg = boardConfig();

const columns = ref([]);
const loading = ref(false);
const dragTaskId = ref(0);
const dragFromId = ref(0);
const dragOverId = ref(0);
const openTaskId = ref(0);
const detailOpen = ref(false);
const createOpen = ref(false);

async function load() {
    loading.value = true;
    const res = await BoardApi.get(cfg.board);
    loading.value = false;

    if (!res || res.success === false) {
        toast.add({ severity: 'error', summary: 'Доска не загружена', detail: errorMessage(res), life: 8000 });
        return;
    }
    columns.value = normalizeBoard(res).columns;
}

function openTask(task) {
    openTaskId.value = task.id;
    detailOpen.value = true;
}

function onDragStart(task, column, ev) {
    dragTaskId.value = task.id;
    dragFromId.value = column.id;
    if (ev.dataTransfer) {
        ev.dataTransfer.effectAllowed = 'move';
        // Firefox не начнёт перетаскивание без выставленных данных.
        ev.dataTransfer.setData('text/plain', String(task.id));
    }
}

function onDragEnd() {
    dragTaskId.value = 0;
    dragFromId.value = 0;
    dragOverId.value = 0;
}

function onDragOver(column, ev) {
    if (!dragTaskId.value) return;
    ev.preventDefault();
    if (ev.dataTransfer) ev.dataTransfer.dropEffect = 'move';
    dragOverId.value = column.id;
}

/**
 * Перемещение оптимистичное: карточка едет сразу, запрос уходит следом.
 * Сервер — единственный источник истины по правам (в done пускают только автора),
 * поэтому при отказе возвращаем карточку в исходную колонку на прежнее место.
 */
async function onDrop(column) {
    const taskId = dragTaskId.value;
    const fromId = dragFromId.value;
    onDragEnd();

    if (!taskId || !fromId || fromId === column.id) return;

    const from = columns.value.find((c) => c.id === fromId);
    if (!from) return;

    const index = from.tasks.findIndex((t) => t.id === taskId);
    if (index === -1) return;
    const task = from.tasks[index];

    // Страховка на клиенте: заведомо запрещённый переход не гоняем на сервер.
    if (column.is_final && !cfg.can_move_any && task.author_id !== Number(cfg.user_id)) {
        toast.add({
            severity: 'warn',
            summary: 'Перемещение отклонено',
            detail: 'Закрыть задачу может только её автор.',
            life: 6000,
        });
        return;
    }

    const prevColumnId = task.column_id;
    from.tasks.splice(index, 1);
    column.tasks.push(task);
    task.column_id = column.id;

    const res = await TaskApi.move(taskId, column.key);

    if (!res || res.success === false) {
        const back = column.tasks.findIndex((t) => t.id === taskId);
        if (back !== -1) column.tasks.splice(back, 1);
        task.column_id = prevColumnId;
        from.tasks.splice(index, 0, task);

        toast.add({
            severity: 'error',
            summary: 'Перемещение отклонено',
            detail: errorMessage(res, 'Сервер отклонил перемещение'),
            life: 8000,
        });
        return;
    }

    // Сервер мог поменять поля (closedon, assignee) — подтягиваем актуальное состояние.
    if (res.object) Object.assign(task, normalizeTask(res.object));
}

onMounted(load);
</script>

<template>
    <div>
        <div class="mxb-toolbar">
            <Button label="Новая задача" icon="pi pi-plus" size="small" @click="createOpen = true" />
            <Button
                label="Обновить"
                icon="pi pi-refresh"
                size="small"
                severity="secondary"
                outlined
                :loading="loading"
                @click="load"
            />
            <span class="mxb-toolbar-spacer" />
            <span class="mxb-hint">Карточки перетаскиваются мышью между колонками</span>
        </div>

        <div class="mxb-columns">
            <div
                v-for="column in columns"
                :key="column.id"
                class="mxb-column"
                :class="{ 'mxb-column--over': dragOverId === column.id }"
                @dragover="onDragOver(column, $event)"
                @dragleave="dragOverId = dragOverId === column.id ? 0 : dragOverId"
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
                        :dragging="dragTaskId === task.id"
                        @open="openTask(task)"
                        @dragstart="onDragStart(task, column, $event)"
                        @dragend="onDragEnd"
                    />
                    <div v-if="!column.tasks.length" class="mxb-empty">Пусто</div>
                </div>
            </div>

            <div v-if="!columns.length && !loading" class="mxb-empty">
                Колонок нет. Проверьте, что доска «{{ cfg.board }}» существует.
            </div>
        </div>

        <TaskDialog v-model:visible="detailOpen" :task-id="openTaskId" @changed="load" />
        <NewTaskDialog v-model:visible="createOpen" @created="load" />
    </div>
</template>
