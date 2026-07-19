<script setup>
import { computed } from 'vue';
import { Tag } from 'primevue';
import {
    priorityMeta, userName, fmtRelativeDay, fmtDay, deadlineTone, initials, avatarStyle, isOverdue,
} from '../utils/format.js';
import { t } from '../utils/i18n.js';

const props = defineProps({
    task: { type: Object, required: true },
    dragging: { type: Boolean, default: false },
});

const emit = defineEmits(['open', 'dragstart', 'dragend', 'delete']);

// Кто может удалить карточку прямо с доски: автор или менеджер (can_move_any).
// Права дублируют серверную проверку в TaskService::delete — иконку прячем,
// чтобы не предлагать заведомо запрещённое действие.
const cfg = window.MxBoardConfig || {};
const userId = Number(cfg.user_id) || 0;
const canDelete = computed(() => !!cfg.can_move_any || props.task.author_id === userId);

const priority = computed(() => priorityMeta(props.task.priority));
const assignee = computed(() => userName(props.task, 'assignee'));
const overdue = computed(() => isOverdue(props.task));
const deadlineRel = computed(() => fmtRelativeDay(props.task.deadlineon));
const deadlineAbs = computed(() => fmtDay(props.task.deadlineon));
const deadlineToneClass = computed(() => `mxb-deadline-chip--${deadlineTone(props.task)}`);

// Клик по карточке открывает задачу, но клик по иконке удаления — нет. Не глушим
// событие через stop: PrimeVue ConfirmPopup делает первичное позиционирование в
// обработчике клика на document (isTargetClicked → alignOverlay), и stopPropagation
// оставил бы попап неспозиционированным в углу экрана. Поэтому просто отсеиваем клик
// по кнопке здесь, а всплытие до document сохраняем.
function onCardClick(event) {
    if (event.target.closest('.mxb-card-del')) return;
    emit('open');
}
</script>

<template>
    <div
        class="mxb-card"
        :class="{ 'mxb-card--dragging': dragging }"
        draggable="true"
        tabindex="0"
        role="button"
        @dragstart="emit('dragstart', $event)"
        @dragend="emit('dragend', $event)"
        @click="onCardClick"
        @keydown.enter.prevent="emit('open')"
        @keydown.space.prevent="emit('open')"
    >
        <button
            v-if="canDelete"
            type="button"
            class="mxb-card-del"
            :title="t('mxboard_ui_delete')"
            :aria-label="t('mxboard_ui_delete')"
            draggable="false"
            @click="emit('delete', $event.currentTarget)"
            @keydown.enter.stop
            @keydown.space.stop
        >
            <i class="pi pi-trash" />
        </button>

        <div class="mxb-card-title">
            <i v-if="task.parent_id" class="pi pi-sitemap mxb-sub-icon" :title="t('mxboard_ui_subtask')" />
            {{ task.title }}
        </div>

        <div class="mxb-card-tags">
            <span v-if="task.num" class="mxb-chip mxb-chip--num">{{ task.num }}</span>
            <Tag :value="priority.label" :severity="priority.severity" />
            <span v-if="task.type_key" class="mxb-chip mxb-chip--type">{{ task.type_key }}</span>
        </div>

        <div v-if="assignee || deadlineRel" class="mxb-card-foot">
            <span v-if="assignee" class="mxb-card-assignee">
                <span class="mxb-avatar mxb-avatar--sm" :style="avatarStyle(task.assignee_id)">{{ initials(assignee) }}</span>
                <span class="mxb-card-assignee-name">{{ assignee }}</span>
            </span>
            <span class="mxb-toolbar-spacer" />
            <span
                v-if="deadlineRel"
                class="mxb-deadline-chip"
                :class="deadlineToneClass"
                :title="deadlineAbs"
            >
                <i class="pi pi-calendar" />
                {{ deadlineRel }}
                <i v-if="task.deadline_disputed" class="pi pi-flag-fill mxb-flag" :title="t('mxboard_ui_deadline_disputed_hint')" />
            </span>
        </div>
    </div>
</template>
