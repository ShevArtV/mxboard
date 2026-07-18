<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { Button, InputText, Select, Tag, useToast, useConfirm } from 'primevue';
import {
    TaskApi, TypeApi, ColumnApi, DepartmentApi, AttachmentApi, errorMessage, listOf,
} from '../api/connector.js';
import {
    PRIORITIES, priorityMeta, userName, fmtDate, fmtDay, fmtTime, fmtSize, toDateInput, isOverdue, normalizeTask,
} from '../utils/format.js';
import { t } from '../utils/i18n.js';
import { renderMarkdown } from '../utils/markdown.js';
import { capFiles } from '../utils/upload.js';
import TypeFields from '../components/TypeFields.vue';
import Attachments from '../components/Attachments.vue';
import FileDrop from '../components/FileDrop.vue';
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
const pendingFiles = ref([]); // выбранные, но ещё не отправленные файлы композера
const composerFileInput = ref(null);
const composerOver = ref(false); // подсветка drop-зоны композера
const editingCommentId = ref(0);
const editingCommentText = ref('');
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

// Вложения уровня задачи (comment_id=0) — приходят в task.attachments из taskDetail.
const taskAttachments = computed(() => task.value?.attachments || []);
// Поле типа `files` в схеме типа: если есть — показываем файловую зону задачи (её
// заголовок = лейбл поля). Нет поля — нет файловой зоны (файлы задачи только через него).
const filesField = computed(() => (schema.value?.fields || []).find((f) => f.type === 'files') || null);
// Композер можно отправить, если есть текст ИЛИ выбраны файлы.
const canSend = computed(() => !!comment.value.trim() || pendingFiles.value.length > 0);

// Название текущей стадии по ключу колонки (для мета-строки, когда список стадий загружен).
const stageName = computed(() => {
    const key = task.value?.column_key;
    const col = columns.value.find((c) => c.key === key);
    return col?.name || key || '';
});

// Комментарии как лента чата: «свои»/«чужие» + группировка подряд идущих сообщений
// одного автора в пределах 5 минут (шапка с именем/аватаром — только у первого в группе).
const chatMessages = computed(() => {
    const list = detail.value.comments || [];
    return list.map((c, i) => {
        const prev = list[i - 1];
        const sameAuthor = !!prev && prev.user_id === c.user_id;
        const closeInTime = !!prev
            && Math.abs((Number(c.createdon) || 0) - (Number(prev.createdon) || 0)) < 300;
        return {
            ...c,
            own: c.user_id === props.userId,
            firstOfGroup: !(sameAuthor && closeInTime),
        };
    });
});

// Инициалы для аватара (до двух слов имени).
function initials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return (parts[0][0] + (parts[1] ? parts[1][0] : '')).toUpperCase();
}

// Стабильный оттенок аватара из id пользователя — чтобы собеседники визуально различались.
function avatarStyle(userId) {
    const hue = ((Number(userId) || 0) * 47) % 360;
    return { background: `hsl(${hue}, 55%, 45%)` };
}

const chatScroll = ref(null);
function scrollChatToBottom() {
    nextTick(() => {
        const el = chatScroll.value;
        if (el) el.scrollTop = el.scrollHeight;
    });
}

async function copyId() {
    try {
        await navigator.clipboard.writeText(String(props.taskId));
        toast.add({ severity: 'success', summary: t('mxboard_msg_id_copied'), life: 2000 });
    } catch {
        toast.add({ severity: 'warn', summary: t('mxboard_msg_rejected'), life: 3000 });
    }
}

// fields задачи, размеченные лейблами и типами из схемы типа.
const fieldRows = computed(() => {
    const values = task.value?.fields || {};
    const defs = schema.value?.fields || [];
    return defs
        .filter((f) => values[f.key] !== undefined && values[f.key] !== '' && values[f.key] !== null)
        .map((f) => ({ key: f.key, label: f.label, type: f.type || 'textarea', value: values[f.key] }));
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
        pendingFiles.value = [];
        editing.value = false;
        disputeOpen.value = false;
        scrollChatToBottom();
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

// Отправка сообщения чата. Если приложены файлы — сперва создаём коммент (чтобы
// получить его id), затем грузим файлы к нему. Сообщение без текста, но с файлами
// допустимо: в теле — плейсхолдер, содержательное — сами вложения.
async function addComment() {
    if (!canSend.value) return;
    const text = comment.value.trim();
    const files = pendingFiles.value.slice();
    busy.value = true;
    try {
        const content = text || t('mxboard_ui_files_message');
        const res = await TaskApi.comment(props.taskId, content);
        const commentId = Number(res?.object?.id) || 0;
        if (files.length && commentId) {
            const up = await AttachmentApi.upload(props.taskId, commentId, files);
            // Частичный успех загрузки: коммент создан, но часть файлов не легла — предупредим.
            if (up?.message) {
                toast.add({ severity: 'warn', summary: t('mxboard_msg_upload_partial'), detail: up.message, life: 8000 });
            }
        }
        comment.value = '';
        pendingFiles.value = [];
        toast.add({ severity: 'success', summary: t('mxboard_msg_comment_added'), life: 3000 });
        reload();
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    } finally {
        busy.value = false;
    }
}

// Докинуть файлы в композер (из выбора или drop) с капом числа «за раз» с учётом уже выбранных.
function addComposerFiles(fileList) {
    const { files, dropped, max } = capFiles(fileList, pendingFiles.value.length);
    if (dropped > 0) {
        toast.add({ severity: 'warn', summary: t('mxboard_ui_too_many_files', { max }), life: 5000 });
    }
    if (files.length) pendingFiles.value = pendingFiles.value.concat(files);
}

function onComposerFiles(event) {
    addComposerFiles(event.target.files);
    event.target.value = ''; // сброс для повторного выбора того же файла
}

function onComposerDrop(event) {
    composerOver.value = false;
    const dt = event.dataTransfer;
    if (dt && dt.files && dt.files.length) addComposerFiles(dt.files);
}

function removePendingFile(idx) {
    pendingFiles.value = pendingFiles.value.filter((_, i) => i !== idx);
}

// Загрузка файлов прямо к задаче (comment_id=0) — блок «Файлы задачи» в левой колонке.
// files приходит из FileDrop уже обрезанным по лимиту.
async function uploadTaskFiles(files) {
    if (!files || !files.length) return;
    const ok = await act(async () => {
        const up = await AttachmentApi.upload(props.taskId, 0, files);
        if (up?.message) {
            toast.add({ severity: 'warn', summary: t('mxboard_msg_upload_partial'), detail: up.message, life: 8000 });
        }
    }, t('mxboard_msg_file_uploaded'));
    if (ok) reload();
}

function removeAttachment(att, event) {
    confirm.require({
        target: event?.currentTarget,
        message: t('mxboard_msg_confirm_delete_file'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('mxboard_ui_delete'),
        rejectLabel: t('mxboard_ui_cancel'),
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            const ok = await act(() => AttachmentApi.remove(att.id), t('mxboard_msg_file_deleted'));
            if (ok) reload();
        },
    });
}

function startEditComment(c) {
    editingCommentId.value = c.id || 0;
    editingCommentText.value = c.content || '';
}

function cancelEditComment() {
    editingCommentId.value = 0;
    editingCommentText.value = '';
}

async function saveEditComment() {
    if (!editingCommentText.value.trim()) return;
    const ok = await act(
        () => TaskApi.updateComment(props.taskId, editingCommentId.value, editingCommentText.value.trim()),
        t('mxboard_msg_comment_updated'),
    );
    if (ok) reload();
}

function removeComment(event, c) {
    confirm.require({
        target: event.currentTarget,
        message: t('mxboard_msg_confirm_delete_comment'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('mxboard_ui_delete'),
        rejectLabel: t('mxboard_ui_cancel'),
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            const ok = await act(() => TaskApi.deleteComment(props.taskId, c.id), t('mxboard_msg_comment_deleted'));
            if (ok) reload();
        },
    });
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

        <div v-else-if="task" class="mxb-task-body">
            <!-- ЛЕВАЯ КОЛОНКА: описание + мета (свой скролл) -->
            <div class="mxb-task-left">
                <!-- Родитель -->
                <div v-if="detail.parent" class="mxb-parent-link" @click="emit('open-task', detail.parent.id)">
                    <i class="pi pi-arrow-up-right" /> {{ t('mxboard_ui_parent') }}: <strong>{{ detail.parent.title }}</strong>
                </div>

                <h2 class="mxb-task-title">{{ task.title }}</h2>

                <!-- Мета-карточка -->
                <div class="mxb-meta-card">
                    <div class="mxb-meta-row">
                        <span class="mxb-meta-label">{{ t('mxboard_ui_priority') }}</span>
                        <span class="mxb-meta-value">
                            <Tag :value="priority.label" :severity="priority.severity" />
                            <span v-if="task.type_key" class="mxb-chip">{{ task.type_key }}</span>
                        </span>
                    </div>
                    <div class="mxb-meta-row">
                        <span class="mxb-meta-label">{{ t('mxboard_ui_setter') }}</span>
                        <span class="mxb-meta-value">{{ userName(task, 'author') || '—' }}</span>
                    </div>
                    <div class="mxb-meta-row">
                        <span class="mxb-meta-label">{{ t('mxboard_ui_assignee_label') }}</span>
                        <span class="mxb-meta-value mxb-meta-assignee">{{ userName(task, 'assignee') || '—' }}</span>
                    </div>
                    <div class="mxb-meta-row" :class="{ 'mxb-overdue': overdue }">
                        <span class="mxb-meta-label">{{ t('mxboard_ui_deadline_label') }}</span>
                        <span class="mxb-meta-value mxb-meta-deadline">
                            <strong>{{ fmtDay(task.deadlineon) || '—' }}</strong>
                            <span v-if="overdue" class="mxb-overdue-badge">{{ t('mxboard_ui_overdue') }}</span>
                            <span v-if="task.deadline_disputed" class="mxb-disputed-badge">
                                <i class="pi pi-flag-fill" /> {{ t('mxboard_ui_disputed_to') }} → {{ fmtDay(task.deadline_proposed) }}
                            </span>
                        </span>
                    </div>
                    <!-- Действия по дедлайну: разрешение оспаривания / оспорить -->
                    <div v-if="(task.deadline_disputed && canManage) || (!task.deadline_disputed && isAssignee)" class="mxb-meta-row">
                        <span class="mxb-meta-label" />
                        <span class="mxb-meta-value mxb-meta-deadline-actions">
                            <template v-if="task.deadline_disputed && canManage">
                                <Button :label="t('mxboard_ui_accept')" icon="pi pi-check" size="small" :loading="busy" @click="resolve(true)" />
                                <Button :label="t('mxboard_ui_reject')" icon="pi pi-times" size="small" severity="secondary" outlined :loading="busy" @click="resolve(false)" />
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
                        </span>
                    </div>
                    <div class="mxb-meta-row">
                        <span class="mxb-meta-label">{{ t('mxboard_ui_stage') }}</span>
                        <span class="mxb-meta-value">
                            <Select
                                v-if="columns.length"
                                :model-value="task.column_key"
                                :options="columns"
                                option-label="name"
                                option-value="key"
                                :loading="busy"
                                fluid
                                @update:model-value="moveTo"
                            />
                            <span v-else class="mxb-chip">{{ stageName || '—' }}</span>
                        </span>
                    </div>
                    <div v-if="projectKey" class="mxb-meta-row">
                        <span class="mxb-meta-label">{{ t('mxboard_ui_project') }}</span>
                        <span class="mxb-meta-value"><span class="mxb-chip">{{ projectKey }}</span></span>
                    </div>
                    <div v-if="task.createdon" class="mxb-meta-row">
                        <span class="mxb-meta-label">{{ t('mxboard_ui_created') }}</span>
                        <span class="mxb-meta-value">{{ fmtDate(task.createdon) }}</span>
                    </div>
                    <div class="mxb-meta-row">
                        <span class="mxb-meta-label">{{ t('mxboard_ui_task_id') }}</span>
                        <span class="mxb-meta-value mxb-meta-id">
                            <code>#{{ task.id }}</code>
                            <Button icon="pi pi-copy" size="small" severity="secondary" text v-tooltip="t('mxboard_ui_copy')" @click="copyId" />
                        </span>
                    </div>
                </div>

                <!-- Форма оспаривания -->
                <div v-if="disputeOpen" class="mxb-inline-form">
                    <div class="mxb-field">
                        <label>{{ t('mxboard_ui_proposed_date') }}</label>
                        <input v-model="dispute.date" type="date" class="mxb-input" />
                    </div>
                    <div class="mxb-field">
                        <label>{{ t('mxboard_ui_reason') }}</label>
                        <InputText v-model="dispute.reason" fluid :placeholder="t('mxboard_ui_reason_placeholder')" />
                    </div>
                    <div class="mxb-dialog-actions">
                        <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined size="small" @click="disputeOpen = false" />
                        <Button :label="t('mxboard_ui_send')" icon="pi pi-send" size="small" :loading="busy" @click="sendDispute" />
                    </div>
                </div>

                <!-- Файлы задачи — только если у типа есть поле `files` (его лейбл = заголовок).
                     Видно и в просмотре, и в правке; файлы = вложения задачи (comment_id=0). -->
                <div v-if="filesField" class="mxb-section">
                    <div class="mxb-section-title">
                        <i class="pi pi-paperclip" />{{ filesField.label || t('mxboard_ui_task_files') }}
                        <span class="mxb-column-count">{{ taskAttachments.length }}</span>
                    </div>
                    <Attachments
                        :items="taskAttachments"
                        :user-id="userId"
                        :can-manage="canManage"
                        @remove="removeAttachment"
                    />
                    <div v-if="!taskAttachments.length" class="mxb-empty">{{ t('mxboard_ui_no_files') }}</div>
                    <FileDrop :busy="busy" @files="uploadTaskFiles" />
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
                    <TypeFields v-if="schema" v-model="form.fields" :fields="schema.fields" :users="users" :task-id="taskId" />
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

                    <!-- Вердикт ИИ-проверки полноты (если задача его получила при создании) -->
                    <div v-if="task.ai_verdict" class="mxb-section">
                        <div class="mxb-section-title">
                            <i class="pi pi-sparkles" />{{ t('mxboard_ui_ai_verdict') }}
                            <span
                                class="mxb-chip"
                                :class="task.ai_verdict.complete ? 'mxb-ai-ok' : 'mxb-ai-bad'"
                            >{{ task.ai_verdict.complete ? t('mxboard_ui_ai_ok') : t('mxboard_ui_ai_incomplete_short') }}</span>
                            <span v-if="typeof task.ai_verdict.score === 'number'" class="mxb-chip">{{ task.ai_verdict.score }}/100</span>
                            <span v-if="task.ai_verdict.overridden" class="mxb-chip mxb-ai-bad">{{ t('mxboard_ui_ai_overridden') }}</span>
                        </div>
                        <div v-if="task.ai_verdict.summary" class="mxb-md">{{ task.ai_verdict.summary }}</div>
                        <ul v-if="task.ai_verdict.missing && task.ai_verdict.missing.length" class="mxb-ai-verdict-missing">
                            <li v-for="(m, i) in task.ai_verdict.missing" :key="i">{{ m }}</li>
                        </ul>
                    </div>

                    <div v-if="fieldRows.length" class="mxb-section">
                        <div class="mxb-section-title"><i class="pi pi-list" />{{ t('mxboard_ui_type_fields') }}</div>
                        <div v-for="f in fieldRows" :key="f.key" class="mxb-fieldrow">
                            <!-- URL: подпись + ссылка -->
                            <template v-if="f.type === 'url'">
                                <span class="mxb-fieldrow-label">{{ f.label }}:</span>
                                <a :href="f.value" target="_blank" rel="noopener" class="mxb-fieldrow-link">{{ f.value }}</a>
                            </template>
                            <!-- File: подпись + ссылка на файл со скачиванием -->
                            <template v-else-if="f.type === 'file'">
                                <span class="mxb-fieldrow-label">{{ f.label }}:</span>
                                <a :href="f.value" target="_blank" rel="noopener" download class="mxb-fieldrow-link"><i class="pi pi-paperclip" /> {{ t('mxboard_ui_download') }}</a>
                            </template>
                            <!-- Date/number/user: inline через двоеточие -->
                            <template v-else-if="f.type === 'date' || f.type === 'number' || f.type === 'user'">
                                <span class="mxb-fieldrow-label">{{ f.label }}:</span>
                                <span class="mxb-fieldrow-value">{{ f.value }}</span>
                            </template>
                            <!-- Textarea/text: подпись над блоком markdown -->
                            <template v-else>
                                <div class="mxb-fieldrow-label">{{ f.label }}</div>
                                <div class="mxb-md" v-html="renderMarkdown(String(f.value))" />
                            </template>
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

                    <!-- Журнал (сворачиваемый) -->
                    <details class="mxb-section mxb-log-section">
                        <summary class="mxb-section-title">
                            <i class="pi pi-history" />{{ t('mxboard_ui_log') }}
                            <span class="mxb-column-count">{{ detail.log.length }}</span>
                        </summary>
                        <div class="mxb-log-list">
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
                    </details>
                </div>
            </div>

            <!-- ПРАВАЯ КОЛОНКА: чат задачи (на всю высоту, свой скролл) -->
            <div class="mxb-task-chat">
                <div class="mxb-chat-head">
                    <i class="pi pi-comments" />
                    <span class="mxb-chat-head-title">{{ t('mxboard_ui_chat') }}</span>
                    <span class="mxb-column-count">{{ detail.comments.length }}</span>
                </div>

                <div ref="chatScroll" class="mxb-chat-scroll">
                    <div v-if="!chatMessages.length" class="mxb-empty">{{ t('mxboard_ui_no_comments') }}</div>
                    <div
                        v-for="c in chatMessages"
                        :key="c.id"
                        class="mxb-chat-msg"
                        :class="{ 'mxb-chat-msg--own': c.own, 'mxb-chat-msg--grouped': !c.firstOfGroup }"
                    >
                        <div class="mxb-chat-avatar-slot">
                            <span v-if="c.firstOfGroup" class="mxb-chat-avatar" :style="avatarStyle(c.user_id)">{{ initials(userName(c, 'user')) }}</span>
                        </div>
                        <div class="mxb-chat-bubble-wrap">
                            <div v-if="c.firstOfGroup && !c.own" class="mxb-chat-author">{{ userName(c, 'user') || '—' }}</div>
                            <div class="mxb-chat-bubble">
                                <template v-if="editingCommentId === c.id">
                                    <textarea v-model="editingCommentText" class="mxb-textarea" rows="3" />
                                    <div class="mxb-dialog-actions">
                                        <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined size="small" @click="cancelEditComment" />
                                        <Button :label="t('mxboard_ui_save')" icon="pi pi-check" size="small" :loading="busy" @click="saveEditComment" />
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="mxb-md" v-html="renderMarkdown(c.content)" />
                                    <Attachments
                                        v-if="c.attachments && c.attachments.length"
                                        :items="c.attachments"
                                        :user-id="userId"
                                        :can-manage="canManage"
                                        @remove="removeAttachment"
                                    />
                                    <div class="mxb-chat-meta">
                                        <span class="mxb-chat-time">{{ fmtTime(c.createdon) }}</span>
                                        <span v-if="c.updatedon" class="mxb-comment-edited">{{ t('mxboard_ui_comment_edited') }}</span>
                                    </div>
                                    <div v-if="c.user_id === userId" class="mxb-chat-actions">
                                        <Button icon="pi pi-pencil" size="small" severity="secondary" text @click="startEditComment(c)" />
                                        <Button icon="pi pi-trash" size="small" severity="danger" text @click="removeComment($event, c)" />
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Композер: текст + прикрепление файлов к сообщению (drag-n-drop + мультивыбор) -->
                <div
                    class="mxb-chat-composer-wrap"
                    :class="{ 'mxb-composer-over': composerOver }"
                    @dragover.prevent="composerOver = true"
                    @dragenter.prevent="composerOver = true"
                    @dragleave.prevent="composerOver = false"
                    @drop.prevent="onComposerDrop"
                >
                    <!-- Выбранные, но ещё не отправленные файлы -->
                    <div v-if="pendingFiles.length" class="mxb-composer-files">
                        <span v-for="(f, i) in pendingFiles" :key="i" class="mxb-composer-file">
                            <i class="pi pi-file" />
                            <span class="mxb-composer-file-name" :title="f.name">{{ f.name }}</span>
                            <span class="mxb-composer-file-size">{{ fmtSize(f.size) }}</span>
                            <button type="button" class="mxb-composer-file-x" :title="t('mxboard_ui_cancel')" @click="removePendingFile(i)"><i class="pi pi-times" /></button>
                        </span>
                    </div>
                    <div class="mxb-chat-composer">
                        <Button
                            icon="pi pi-paperclip"
                            size="small"
                            severity="secondary"
                            text
                            v-tooltip.top="t('mxboard_ui_attach_file')"
                            class="mxb-chat-attach"
                            @click="composerFileInput?.click()"
                        />
                        <input ref="composerFileInput" type="file" multiple class="mxb-file-hidden" @change="onComposerFiles" />
                        <textarea
                            v-model="comment"
                            class="mxb-chat-input"
                            rows="1"
                            :placeholder="t('mxboard_ui_comment_placeholder')"
                            @keydown.enter.exact.prevent="addComment"
                        />
                        <Button
                            icon="pi pi-send"
                            size="small"
                            rounded
                            :disabled="!canSend"
                            :loading="busy"
                            v-tooltip.top="t('mxboard_ui_send')"
                            @click="addComment"
                        />
                    </div>
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
