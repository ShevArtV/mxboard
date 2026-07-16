<script setup>
import { computed } from 'vue';
import { Tag } from 'primevue';
import { priorityMeta, userName, fmtDay, isOverdue } from '../utils/format.js';

const props = defineProps({
    task: { type: Object, required: true },
    dragging: { type: Boolean, default: false },
});

const emit = defineEmits(['open', 'dragstart', 'dragend']);

const priority = computed(() => priorityMeta(props.task.priority));
const assignee = computed(() => userName(props.task, 'assignee'));
const overdue = computed(() => isOverdue(props.task));
const deadline = computed(() => fmtDay(props.task.deadlineon));
</script>

<template>
    <div
        class="mxb-card"
        :class="{ 'mxb-card--dragging': dragging }"
        draggable="true"
        @dragstart="emit('dragstart', $event)"
        @dragend="emit('dragend', $event)"
        @click="emit('open')"
    >
        <div class="mxb-card-title">
            <i v-if="task.parent_id" class="pi pi-sitemap mxb-sub-icon" title="Подзадача" />
            {{ task.title }}
        </div>
        <div class="mxb-card-meta">
            <Tag :value="priority.label" :severity="priority.severity" />
            <span v-if="task.type_key" class="mxb-chip">{{ task.type_key }}</span>
            <span v-if="assignee"><i class="pi pi-wrench" />{{ assignee }}</span>
            <span
                v-if="deadline"
                :class="{ 'mxb-overdue': overdue, 'mxb-disputed': task.deadline_disputed }"
            >
                <i class="pi pi-calendar" />{{ deadline }}
                <i v-if="task.deadline_disputed" class="pi pi-flag-fill mxb-flag" title="Дедлайн оспорен" />
            </span>
        </div>
    </div>
</template>
