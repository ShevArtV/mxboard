<script setup>
import { ref, computed, watch } from 'vue';
import { Button, InputText, Select, Tag, useToast, useConfirm } from 'primevue';
import {
    TaskApi, TypeApi, ColumnApi, DepartmentApi, errorMessage, listOf,
} from '../api/connector.js';
import {
    PRIORITIES, priorityMeta, userName, fmtDate, fmtDay, toDateInput, isOverdue, normalizeTask,
} from '../utils/format.js';
import { t } from '../utils/i18n.js';
import { renderMarkdown } from '../utils/markdown.js';
import TypeFields from '../components/TypeFields.vue';
import NewTaskDialog from '../components/NewTaskDialog.vue';

// Страница задачи — отдельная вью (ручной switch в BoardView, без vue-router).
// departmentId/projectKey постоянны при навигации родитель↔подзадача (тот же проект).
const props = defineProps({
    taskId: { type: Number, required: true },
    departmentId: { type: Number, default: 0 },
    projectKey: { type: String, default: '' },
    canMoveAny: { type: Boolean, default: false },
    userId: { type: Number, default: 0 },
});
const emit = defineEmits(['back', 'open-task', 'changed']);

const toast = useToast();
const confirm = useConfirm();

const loading = ref(false);
const busy = ref(false);
const task = ref(null);
const detail = ref({ subtasks: [], comments: [], log: [], parent: null });
const schema = ref(null);
const columns = ref([]);
const users = ref([]);

const comment = ref('');
const editing = ref(false);
const form = ref({ title: '', tor: '', priority: 0, deadline: '', assignee_id: 0, fields: {} });

const disputeOpen = ref(false);
const dispute = ref({ date: '', reason: '' });
const subtaskOpen = ref(false);

const isAuthor = computed(() => !!task.value && task.value.author_id === props.userId);
const isAssignee = computed(() => !!task.value && task.value.assignee_id === props.userId);
const canManage = computed(() => isAuthor.value || props.canMoveAny);
const priority = computed(() => priorityMeta(task.value?.priority));
const torHtml = computed(() => renderMarkdown(task.value?.tor));
const overdue = computed(() => isOverdue(task.value));

// fields задачи, размеченные лейблами из схемы типа (для читаемого показа).
const fieldRows = computed(() => {
    const values = task.value?.fields || {};
    const defs = schema.value?.fields || [];
    return defs
        .filter((f) => values[f.key] !== undefined && values[f.key] !== '' && values[f.key] !== null)
        .map((f) => ({ key: f.key, label: f.label, value: values[f.key] }));
});

// Человеческое название действия журнала (ключ mxboard_act_<action>), иначе — как есть.
function actionLabel(action) {
    const key = `mxboard_act_${action}`;
    const label = t(key);
    return label === key ? action : label;
}

watch(() => props.taskId, (id) => {
    if (id) load(id);
}, { immediate: true });

async function load(id) {
    loading.value = true;
    try {
        const res = await TaskApi.get(id);
        const obj = res.object ?? {};
        task.value = normalizeTask(obj);
        detail.value = {
            parent: obj.parent ?? null,
            subtasks: Array.isArray(obj.subtasks) ? obj.subtasks : [],
            comments: Array.isArray(obj.comments) ? obj.comments : [],
            log: Array.isArray(obj.log) ? obj.log : [],
        };
        comment.value = '';
        editing.value = false;
        disputeOpen.value = false;
        loadContext();
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_task_load'), detail: errorMessage(e), life: 8000 });
        emit('back');
    } finally {
        loading.value = false;
    }
}

// Схема типа (лейблы полей) и колонки проекта (смена стадии) — вспомогательное,
// молча игнорируем сбой: без них страница всё равно читаема.
async function loadContext() {
    const cur = task.value;
    if (!cur) return;
    try {
        const res = await TypeApi.schema({ project_id: cur.project_id, type: cur.type_key });
        schema.value = res.object ?? null;
    } catch { schema.value = null; }
    try {
        const res = await ColumnApi.getList(cur.project_id);
        columns.value = listOf(res);
    } catch { columns.value = []; }
}

async function ensureUsers() {
    if (users.value.length || !props.departmentId) return;
    try {
        users.value = listOf(await DepartmentApi.users(props.departmentId));
    } catch { users.value = []; }
}

function reload() {
    load(props.taskId);
    emit('changed');
}

// Обёртка действия: сервер — источник истины по правам, отказ показываем тостом.
async function act(fn, okMessage) {
    busy.value = true;
    try {
        await fn();
        if (okMessage) toast.add({ severity: 'success', summary: okMessage, life: 3000 });
        return true;
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
        return false;
    } finally {
        busy.value = false;
    }
}

async function moveTo(columnKey) {
    if (!columnKey || columnKey === task.value.column_key) return;
    const ok = await act(() => TaskApi.move(props.taskId, columnKey), t('mxboard_msg_stage_changed'));
    if (ok) reload();
}

async function addComment() {
    if (!comment.value.trim()) return;
    const ok = await act(() => TaskApi.comment(props.taskId, comment.value.trim()), t('mxboard_msg_comment_added'));
    if (ok) reload();
}

async function startEdit() {
    await ensureUsers();
    form.value = {
        title: task.value.title || '',
        tor: task.value.tor || '',
        priority: Number(task.value.priority) || 0,
        deadline: toDateInput(task.value.deadlineon),
        assignee_id: Number(task.value.assignee_id) || 0,
        fields: { ...(task.value.fields || {}) },
    };
    editing.value = true;
}

async function saveEdit() {
    const ok = await act(() => TaskApi.update({
        id: props.taskId,
        title: form.value.title,
        tor: form.value.tor,
        priority: form.value.priority,
        deadline: form.value.deadline,
        assignee_id: form.value.assignee_id,
        fields: form.value.fields,
    }), t('mxboard_msg_saved'));
    if (ok) reload();
}

function openDispute() {
    dispute.value = { date: toDateInput(task.value.deadlineon), reason: '' };
    disputeOpen.value = true;
}

async function sendDispute() {
    if (!dispute.value.date) {
        toast.add({ severity: 'warn', summary: t('mxboard_msg_warn_proposed_date'), life: 4000 });
        return;
    }
    const ok = await act(
        () => TaskApi.disputeDeadline(props.taskId, dispute.value.date, dispute.value.reason),
        t('mxboard_msg_deadline_disputed'),
    );
    if (ok) reload();
}

async function resolve(accept) {
    const ok = await act(
        () => TaskApi.resolveDeadline(props.taskId, accept),
        accept ? t('mxboard_msg_deadline_accepted') : t('mxboard_msg_deadline_rejected'),
    );
    if (ok) reload();
}

function removeTask(event) {
    confirm.require({
        target: event.currentTarget,
        message: t('mxboard_msg_confirm_delete_task'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('mxboard_ui_delete'),
        rejectLabel: t('mxboard_ui_cancel'),
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            const ok = await act(() => TaskApi.remove(props.taskId), t('mxboard_msg_task_deleted'));
            if (ok) {
                emit('changed');
                emit('back');
            }
        },
    });
}
</script>

<template>
    <div class="mxb-taskpage">
        <div class="mxb-toolbar">
            <Button :label="t('mxboard_ui_to_board')" icon="pi pi-arrow-left" size="small" severity="secondary" outlined @click="emit('back')" />
            <span class="mxb-toolbar-spacer" />
            <Button
                v-if="canManage"
                :label="editing ? t('mxboard_ui_cancel_edit') : t('mxboard_ui_edit')"
                :icon="editing ? 'pi pi-times' : 'pi pi-pencil'"
                size="small"
                severity="secondary"
                outlined
                @click="editing ? (editing = false) : startEdit()"
            />
            <Button
                v-if="canManage"
                :label="t('mxboard_ui_delete')"
                icon="pi pi-trash"
                size="small"
                severity="danger"
                outlined
                @click="removeTask"
            />
        </div>

        <div v-if="loading" class="mxb-empty">{{ t('mxboard_ui_loading') }}</div>

        <div v-else-if="task">
            <!-- Родитель -->
            <div v-if="detail.parent" class="mxb-parent-link" @click="emit('open-task', detail.parent.id)">
                <i class="pi pi-arrow-up-right" /> {{ t('mxboard_ui_parent') }}: <strong>{{ detail.parent.title }}</strong>
            </div>

            <h2 class="mxb-task-title">{{ task.title }}</h2>

            <div class="mxb-card-meta" style="margin-bottom: 14px">
                <Tag :value="priority.label" :severity="priority.severity" />
                <span v-if="task.type_key" class="mxb-chip">{{ task.type_key }}</span>
                <span><i class="pi pi-user" />{{ t('mxboard_ui_author_label') }}: {{ userName(task, 'author') || '—' }}</span>
                <span><i class="pi pi-wrench" />{{ t('mxboard_ui_assignee_label') }}: {{ userName(task, 'assignee') || '—' }}</span>
                <span v-if="task.column_key" class="mxb-chip">{{ task.column_key }}</span>
            </div>

            <!-- Дедлайн + оспаривание -->
            <div class="mxb-deadline" :class="{ 'mxb-overdue': overdue }">
                <i class="pi pi-calendar" />
                <span>{{ t('mxboard_ui_deadline_label') }}: <strong>{{ fmtDay(task.deadlineon) || '—' }}</strong></span>
                <span v-if="overdue" class="mxb-overdue-badge">{{ t('mxboard_ui_overdue') }}</span>

                <template v-if="task.deadline_disputed">
                    <span class="mxb-disputed-badge">
                        <i class="pi pi-flag-fill" /> {{ t('mxboard_ui_disputed_to') }} → {{ fmtDay(task.deadline_proposed) }}
                    </span>
                    <template v-if="canManage">
                        <Button :label="t('mxboard_ui_accept')" icon="pi pi-check" size="small" :loading="busy" @click="resolve(true)" />
                        <Button :label="t('mxboard_ui_reject')" icon="pi pi-times" size="small" severity="secondary" outlined :loading="busy" @click="resolve(false)" />
                    </template>
                </template>
                <Button
                    v-else-if="isAssignee"
                    :label="t('mxboard_ui_dispute')"
                    icon="pi pi-flag"
                    size="small"
                    severity="secondary"
                    outlined
                    @click="openDispute"
                />
            </div>

            <!-- Форма оспаривания -->
            <div v-if="disputeOpen" class="mxb-inline-form">
                <div class="mxb-row">
                    <div class="mxb-field mxb-col">
                        <label>{{ t('mxboard_ui_proposed_date') }}</label>
                        <input v-model="dispute.date" type="date" class="mxb-input" />
                    </div>
                    <div class="mxb-field mxb-col mxb-col-2">
                        <label>{{ t('mxboard_ui_reason') }}</label>
                        <InputText v-model="dispute.reason" fluid :placeholder="t('mxboard_ui_reason_placeholder')" />
                    </div>
                </div>
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined size="small" @click="disputeOpen = false" />
                    <Button :label="t('mxboard_ui_send')" icon="pi pi-send" size="small" :loading="busy" @click="sendDispute" />
                </div>
            </div>

            <!-- Смена стадии (сервер проверит право перехода) -->
            <div v-if="columns.length" class="mxb-field" style="max-width: 320px">
                <label>{{ t('mxboard_ui_stage') }}</label>
                <Select
                    :model-value="task.column_key"
                    :options="columns"
                    option-label="name"
                    option-value="key"
                    :loading="busy"
                    fluid
                    @update:model-value="moveTo"
                />
            </div>

            <!-- РЕЖИМ ПРАВКИ -->
            <div v-if="editing" class="mxb-section">
                <div class="mxb-field">
                    <label>{{ t('mxboard_ui_title') }}</label>
                    <InputText v-model="form.title" fluid />
                </div>
                <div class="mxb-row">
                    <div class="mxb-field mxb-col">
                        <label>{{ t('mxboard_ui_deadline') }}</label>
                        <input v-model="form.deadline" type="date" class="mxb-input" />
                    </div>
                    <div class="mxb-field mxb-col">
                        <label>{{ t('mxboard_ui_priority') }}</label>
                        <Select v-model="form.priority" :options="PRIORITIES" option-label="label" option-value="value" fluid />
                    </div>
                </div>
                <div class="mxb-field">
                    <label>{{ t('mxboard_ui_assignee') }}</label>
                    <Select v-model="form.assignee_id" :options="users" option-label="username" option-value="id" filter fluid />
                </div>
                <div class="mxb-field">
                    <label>{{ t('mxboard_ui_tor') }}</label>
                    <textarea v-model="form.tor" class="mxb-textarea" rows="12" />
                </div>
                <TypeFields v-if="schema" v-model="form.fields" :fields="schema.fields" :users="users" />
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined size="small" @click="editing = false" />
                    <Button :label="t('mxboard_ui_save')" icon="pi pi-check" size="small" :loading="busy" @click="saveEdit" />
                </div>
            </div>

            <!-- ПРОСМОТР -->
            <div v-else>
                <div class="mxb-section">
                    <div class="mxb-section-title"><i class="pi pi-file" />{{ t('mxboard_ui_tor_section') }}</div>
                    <div v-if="task.tor" class="mxb-md" v-html="torHtml" />
                    <div v-else class="mxb-empty">{{ t('mxboard_ui_tor_empty') }}</div>
                </div>

                <div v-if="fieldRows.length" class="mxb-section">
                    <div class="mxb-section-title"><i class="pi pi-list" />{{ t('mxboard_ui_type_fields') }}</div>
                    <div v-for="f in fieldRows" :key="f.key" class="mxb-fieldrow">
                        <span class="mxb-fieldrow-label">{{ f.label }}</span>
                        <span class="mxb-fieldrow-value">{{ f.value }}</span>
                    </div>
                </div>

                <!-- Подзадачи -->
                <div class="mxb-section">
                    <div class="mxb-section-title">
                        <i class="pi pi-sitemap" />{{ t('mxboard_ui_subtasks') }}
                        <span class="mxb-column-count">{{ detail.subtasks.length }}</span>
                        <span class="mxb-toolbar-spacer" />
                        <Button :label="t('mxboard_ui_subtask')" icon="pi pi-plus" size="small" severity="secondary" outlined @click="subtaskOpen = true" />
                    </div>
                    <div
                        v-for="s in detail.subtasks"
                        :key="s.id"
                        class="mxb-subtask"
                        @click="emit('open-task', s.id)"
                    >
                        <i :class="s.closed ? 'pi pi-check-circle mxb-done' : 'pi pi-circle'" />
                        <span class="mxb-subtask-title">{{ s.title }}</span>
                        <span v-if="s.assignee" class="mxb-subtask-assignee"><i class="pi pi-wrench" />{{ s.assignee }}</span>
                    </div>
                    <div v-if="!detail.subtasks.length" class="mxb-empty">{{ t('mxboard_ui_no_subtasks') }}</div>
                </div>

                <!-- Комментарии -->
                <div class="mxb-section">
                    <div class="mxb-section-title">
                        <i class="pi pi-comments" />{{ t('mxboard_ui_comments') }}
                        <span class="mxb-column-count">{{ detail.comments.length }}</span>
                    </div>
                    <div v-for="(c, i) in detail.comments" :key="i" class="mxb-comment">
                        <div class="mxb-comment-head">
                            <strong>{{ userName(c, 'user') || '—' }}</strong>
                            <span>{{ fmtDate(c.createdon) }}</span>
                        </div>
                        <div class="mxb-md" v-html="renderMarkdown(c.content)" />
                    </div>
                    <div v-if="!detail.comments.length" class="mxb-empty">{{ t('mxboard_ui_no_comments') }}</div>

                    <div class="mxb-field" style="margin-top: 8px">
                        <textarea v-model="comment" class="mxb-textarea" rows="3" :placeholder="t('mxboard_ui_comment_placeholder')" />
                    </div>
                    <div class="mxb-dialog-actions">
                        <Button :label="t('mxboard_ui_send')" icon="pi pi-send" size="small" :disabled="!comment.trim()" :loading="busy" @click="addComment" />
                    </div>
                </div>

                <!-- Журнал -->
                <div class="mxb-section">
                    <div class="mxb-section-title"><i class="pi pi-history" />{{ t('mxboard_ui_log') }}</div>
                    <div v-for="(l, i) in detail.log" :key="i" class="mxb-log">
                        <span class="mxb-log-time">{{ fmtDate(l.createdon) }}</span>
                        <span><strong>{{ userName(l, 'user') || '—' }}</strong></span>
                        <span>
                            {{ actionLabel(l.action) }}
                            <template v-if="l.from_column || l.to_column">
                                ({{ l.from_column || '—' }} → {{ l.to_column || '—' }})
                            </template>
                            <template v-if="l.channel"> · {{ l.channel }}</template>
                        </span>
                    </div>
                    <div v-if="!detail.log.length" class="mxb-empty">{{ t('mxboard_ui_no_log') }}</div>
                </div>
            </div>
        </div>

        <NewTaskDialog
            v-model:visible="subtaskOpen"
            :department-id="departmentId"
            :project-key="projectKey"
            :parent-id="taskId"
            :parent-title="task ? task.title : ''"
            @created="reload"
        />
    </div>
</template>
