<script setup>
import { computed } from 'vue';
import { Tag } from 'primevue';
import { priorityMeta, userName, commentsCount } from '../utils/format.js';

const props = defineProps({
    task: { type: Object, required: true },
    dragging: { type: Boolean, default: false },
});

const emit = defineEmits(['open', 'dragstart', 'dragend']);

const priority = computed(() => priorityMeta(props.task.priority));
const author = computed(() => userName(props.task, 'author'));
const assignee = computed(() => userName(props.task, 'assignee'));
const comments = computed(() => commentsCount(props.task));
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
        <div class="mxb-card-title">{{ task.title }}</div>
        <div class="mxb-card-meta">
            <Tag :value="priority.label" :severity="priority.severity" />
            <span v-if="author"><i class="pi pi-user" />{{ author }}</span>
            <span v-if="task.assignee_id"><i class="pi pi-wrench" />{{ assignee }}</span>
            <span v-else class="mxb-free"><i class="pi pi-inbox" />Свободна</span>
            <span v-if="comments"><i class="pi pi-comments" />{{ comments }}</span>
        </div>
    </div>
</template>
