<script setup>
import { ref, computed, watch } from 'vue';
import { Dialog, Button, InputText, Select, Tag, useToast, useConfirm } from 'primevue';
import { TaskApi, errorMessage, boardConfig } from '../api/connector.js';
import { PRIORITIES, priorityMeta, userName, fmtDate, normalizeTask } from '../utils/format.js';
import { renderMarkdown } from '../utils/markdown.js';

const props = defineProps({
    visible: { type: Boolean, default: false },
    taskId: { type: Number, default: 0 },
});
const emit = defineEmits(['update:visible', 'changed']);

const toast = useToast();
const confirm = useConfirm();
const cfg = boardConfig();

const loading = ref(false);
const busy = ref(false);
const task = ref(null);
const comments = ref([]);
const logs = ref([]);
const comment = ref('');
const editing = ref(false);
const form = ref({ title: '', tor: '', priority: 0 });

const userId = Number(cfg.user_id) || 0;
const isAuthor = computed(() => !!task.value && task.value.author_id === userId);
const isAssignee = computed(() => !!task.value && task.value.assignee_id === userId);
const isFree = computed(() => !!task.value && !task.value.assignee_id);
const priority = computed(() => priorityMeta(task.value?.priority));
const torHtml = computed(() => renderMarkdown(task.value?.tor));

// Действия журнала — человеческие названия.
const ACTIONS = {
    create: 'создана',
    take: 'взята в работу',
    release: 'отпущена',
    move: 'перемещена',
    comment: 'комментарий',
    close: 'закрыта',
    reopen: 'переоткрыта',
};

watch(() => [props.visible, props.taskId], ([open, id]) => {
    if (open && id) load(id);
});

async function load(id) {
    loading.value = true;
    const res = await TaskApi.get(id);
    loading.value = false;

    if (!res || res.success === false) {
        toast.add({ severity: 'error', summary: 'Задача не загружена', detail: errorMessage(res), life: 8000 });
        emit('update:visible', false);
        return;
    }

    // Форма ответа Task\Get может быть как {task, comments, logs}, так и плоской.
    const root = res.object ?? res;
    const raw = root.task ?? root;
    task.value = normalizeTask(raw);
    comments.value = root.comments ?? raw.comments ?? [];
    logs.value = root.logs ?? root.log ?? raw.logs ?? [];
    if (!Array.isArray(comments.value)) comments.value = [];
    if (!Array.isArray(logs.value)) logs.value = [];
    comment.value = '';
    editing.value = false;
}

function reload() {
    if (props.taskId) load(props.taskId);
    emit('changed');
}

async function run(fn, okMessage) {
    busy.value = true;
    const res = await fn();
    busy.value = false;

    if (!res || res.success === false) {
        toast.add({ severity: 'error', summary: 'Отказано', detail: errorMessage(res), life: 8000 });
        return false;
    }
    if (okMessage) toast.add({ severity: 'success', summary: okMessage, life: 3000 });
    return true;
}

const take = () => run(() => TaskApi.take(props.taskId), 'Задача взята').then((ok) => ok && reload());
const release = () => run(() => TaskApi.release(props.taskId), 'Задача отпущена').then((ok) => ok && reload());

async function addComment() {
    if (!comment.value.trim()) return;
    const ok = await run(() => TaskApi.comment(props.taskId, comment.value.trim()), 'Комментарий добавлен');
    if (ok) reload();
}

function startEdit() {
    form.value = {
        title: task.value.title || '',
        tor: task.value.tor || '',
        priority: Number(task.value.priority) || 0,
    };
    editing.value = true;
}

async function saveEdit() {
    const ok = await run(() => TaskApi.update({
        id: props.taskId,
        title: form.value.title,
        tor: form.value.tor,
        priority: form.value.priority,
    }), 'Сохранено');
    if (ok) reload();
}

function removeTask(event) {
    confirm.require({
        target: event.currentTarget,
        message: 'Удалить задачу вместе с комментариями и журналом?',
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Удалить',
        rejectLabel: 'Отмена',
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            const ok = await run(() => TaskApi.remove(props.taskId), 'Задача удалена');
            if (ok) {
                emit('update:visible', false);
                emit('changed');
            }
        },
    });
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        :header="task ? task.title : 'Задача'"
        :style="{ width: '760px' }"
        @update:visible="emit('update:visible', $event)"
    >
        <div v-if="loading" class="mxb-empty">Загрузка…</div>

        <div v-else-if="task">
            <div class="mxb-card-meta" style="margin-bottom: 12px">
                <Tag :value="priority.label" :severity="priority.severity" />
                <span><i class="pi pi-user" />Автор: {{ userName(task, 'author') || '—' }}</span>
                <span v-if="task.assignee_id"><i class="pi pi-wrench" />Исполнитель: {{ userName(task, 'assignee') }}</span>
                <span v-else class="mxb-free"><i class="pi pi-inbox" />Свободна</span>
                <span v-if="task.createdon"><i class="pi pi-calendar" />{{ fmtDate(task.createdon) }}</span>
            </div>

            <div class="mxb-toolbar">
                <Button
                    v-if="isFree"
                    label="Взять"
                    icon="pi pi-hand-point-up"
                    size="small"
                    :loading="busy"
                    @click="take"
                />
                <Button
                    v-if="!isFree && (isAssignee || cfg.can_move_any)"
                    label="Отпустить"
                    icon="pi pi-undo"
                    size="small"
                    severity="secondary"
                    outlined
                    :loading="busy"
                    @click="release"
                />
                <Button
                    v-if="!editing"
                    label="Редактировать"
                    icon="pi pi-pencil"
                    size="small"
                    severity="secondary"
                    outlined
                    @click="startEdit"
                />
                <span class="mxb-toolbar-spacer" />
                <Button
                    v-if="isAuthor || cfg.can_move_any"
                    label="Удалить"
                    icon="pi pi-trash"
                    size="small"
                    severity="danger"
                    outlined
                    @click="removeTask"
                />
            </div>

            <!-- Режим правки -->
            <div v-if="editing">
                <div class="mxb-field">
                    <label>Заголовок</label>
                    <InputText v-model="form.title" fluid />
                </div>
                <div class="mxb-field">
                    <label>Постановка (ToR, markdown)</label>
                    <textarea v-model="form.tor" class="mxb-textarea" rows="14" />
                </div>
                <div class="mxb-field">
                    <label>Приоритет</label>
                    <Select v-model="form.priority" :options="PRIORITIES" option-label="label" option-value="value" fluid />
                </div>
                <div class="mxb-dialog-actions">
                    <Button label="Отмена" severity="secondary" outlined size="small" @click="editing = false" />
                    <Button label="Сохранить" icon="pi pi-check" size="small" :loading="busy" @click="saveEdit" />
                </div>
            </div>

            <!-- Просмотр -->
            <div v-else>
                <div class="mxb-section">
                    <div class="mxb-section-title"><i class="pi pi-file" />Постановка</div>
                    <!-- ToR рендерится своим мини-markdown; HTML внутри экранирован при рендере. -->
                    <div v-if="task.tor" class="mxb-md" v-html="torHtml" />
                    <div v-else class="mxb-empty">Постановка не заполнена</div>
                </div>

                <div class="mxb-section">
                    <div class="mxb-section-title">
                        <i class="pi pi-comments" />Комментарии
                        <span class="mxb-column-count">{{ comments.length }}</span>
                    </div>

                    <div v-for="c in comments" :key="c.id" class="mxb-comment">
                        <div class="mxb-comment-head">
                            <strong>{{ userName(c, 'user') || '—' }}</strong>
                            <span>{{ fmtDate(c.createdon) }}</span>
                        </div>
                        <div class="mxb-md" v-html="renderMarkdown(c.content)" />
                    </div>
                    <div v-if="!comments.length" class="mxb-empty">Комментариев нет</div>

                    <div class="mxb-field" style="margin-top: 8px">
                        <textarea v-model="comment" class="mxb-textarea" rows="3" placeholder="Комментарий…" />
                    </div>
                    <div class="mxb-dialog-actions">
                        <Button
                            label="Отправить"
                            icon="pi pi-send"
                            size="small"
                            :disabled="!comment.trim()"
                            :loading="busy"
                            @click="addComment"
                        />
                    </div>
                </div>

                <div class="mxb-section">
                    <div class="mxb-section-title"><i class="pi pi-history" />Журнал переходов</div>
                    <div v-for="l in logs" :key="l.id" class="mxb-log">
                        <span class="mxb-log-time">{{ fmtDate(l.createdon) }}</span>
                        <span><strong>{{ userName(l, 'user') || '—' }}</strong></span>
                        <span>
                            {{ ACTIONS[l.action] || l.action }}
                            <template v-if="l.from_column || l.to_column">
                                ({{ l.from_column || '—' }} → {{ l.to_column || '—' }})
                            </template>
                            <template v-if="l.channel"> · {{ l.channel }}</template>
                        </span>
                    </div>
                    <div v-if="!logs.length" class="mxb-empty">Записей нет</div>
                </div>
            </div>
        </div>
    </Dialog>
</template>
