<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
// ВНИМАНИЕ: сборка PrimeVue из Import Map пакета VueTools содержит НЕ все компоненты —
// Accordion* в ней нет вовсе (проверено на стенде: 105 экспортов, ни одного Accordion).
// Аккордеон очередей собран из Panel toggleable: поведение то же, зависимость доступна.
import { Button, Select, Panel, Dialog, useToast, useConfirm } from 'primevue';
import {
    BoardApi, TaskApi, DepartmentApi, ProjectApi, QueueApi, errorMessage, listOf,
} from '../api/connector.js';
import { normalizeBoard, normalizeTask, PRIORITIES } from '../utils/format.js';
import { liveEvents, revisions } from '../utils/bus.js';
import { t } from '../utils/i18n.js';
import TaskCard from '../components/TaskCard.vue';
import NewTaskDialog from '../components/NewTaskDialog.vue';
import TaskPage from './TaskPage.vue';

const toast = useToast();
const confirm = useConfirm();
const cfg = window.MxBoardConfig || {};
const userId = Number(cfg.user_id) || 0;

const departments = ref([]);
const projects = ref([]);
const departmentId = ref(0);
const projectKey = ref('');

const columns = ref([]);
const loading = ref(false);

// Фильтр видимости на клиенте — «все» (менеджерский срез) / только свои роли.
const FILTERS = computed(() => [
    { value: 'all', label: t('mxboard_ui_filter_all') },
    { value: 'author', label: t('mxboard_ui_filter_author') },
    { value: 'assignee', label: t('mxboard_ui_filter_assignee') },
]);
const filter = ref('all');

// Фильтр по приоритету: -1 = все, 0-3 = конкретный.
const PRIORITY_FILTERS = [{ value: -1, label: t('mxboard_ui_filter_all_priorities') }, ...PRIORITIES];
const priorityFilter = ref(-1);

// Фильтры доски переживают перезагрузку: пишем в localStorage и восстанавливаем в init().
// Ключ привязан к пользователю — на общем браузере фильтры не «протекают» между аккаунтами.
const FILTERS_KEY = `mxb_board_filters_${userId}`;
function saveFilters() {
    try {
        localStorage.setItem(FILTERS_KEY, JSON.stringify({
            departmentId: departmentId.value,
            projectKey: projectKey.value,
            filter: filter.value,
            priorityFilter: priorityFilter.value,
        }));
    } catch (e) { /* localStorage недоступен — не критично */ }
}
function readSavedFilters() {
    try {
        return JSON.parse(localStorage.getItem(FILTERS_KEY) || 'null');
    } catch (e) { return null; }
}
watch([departmentId, projectKey, filter, priorityFilter], saveFilters);

// Сброс фильтров: роль/приоритет в дефолт, отдел/проект — на первый.
function resetFilters() {
    filter.value = 'all';
    priorityFilter.value = -1;
    departmentId.value = departments.value.length ? (Number(departments.value[0].id) || 0) : 0;
    pickFirstProject();
    columns.value = [];
    if (projectKey.value) load();
}

// Колонки с учётом фильтра по приоритету (клиентская фильтрация).
const filteredColumns = computed(() => {
    if (priorityFilter.value === -1) return columns.value;
    return columns.value.map((col) => ({
        ...col,
        tasks: col.tasks.filter((t) => Number(t.priority) === priorityFilter.value),
    }));
});

// Цвет стадии для тонировки шапки колонки. Если у колонки не задан свой цвет
// (BoardQuery отдаёт дефолтный серый #6c757d), берём из палитры по позиции —
// чисто презентационный фолбэк, чтобы стадии читались цветом, как в референсе.
// Реальный заданный цвет (из «Структуры») уважаем как есть.
const STAGE_PALETTE = ['#6366f1', '#0ea5e9', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6'];
function columnColor(column, index) {
    const c = String(column.color || '').toLowerCase();
    if (c && c !== '#6c757d') return column.color;
    if (column.is_final) return '#10b981';
    if (column.is_initial) return '#64748b';
    return STAGE_PALETTE[index % STAGE_PALETTE.length];
}

// Ручной switch «доска ↔ страница задачи» (без vue-router).
// Синхронизирован с URL-хэшем (#task-<id>), чтобы перезагрузка страницы на
// открытой карточке не выкидывала на доску. TaskPage грузит задачу по ID сам.
function taskIdFromHash() {
    const m = String(window.location.hash || '').match(/task-(\d+)/);
    return m ? Number(m[1]) : 0;
}
const openTaskId = ref(taskIdFromHash());

// Открытие/закрытие задачи → хэш. Закрытие чистим через replaceState, чтобы не
// плодить пустую запись в истории и не прыгать по несуществующему якорю.
watch(openTaskId, (id) => {
    const hash = id ? `#task-${id}` : '';
    if (hash) {
        if (window.location.hash !== hash) window.location.hash = hash;
    } else if (window.location.hash) {
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
    }
});

// Кнопки браузера «назад/вперёд» — подтягиваем состояние из хэша.
onMounted(() => {
    window.addEventListener('hashchange', () => {
        openTaskId.value = taskIdFromHash();
    });
});

const dragTaskKey = ref('');
const dragFromKey = ref('');
const dragOverKey = ref('');
const createOpen = ref(false);

// Очереди проекта. Панель показывается по кнопке и только когда очереди непустые:
// пустая очередь на доске — шум, управлять её составом надо из карточки задачи.
const queues = ref([]);
const queuesOpen = ref(false);
// Счётчик открытий окна очередей: входит в :key панелей, чтобы каждое открытие
// начиналось со свёрнутых очередей, а не с того, что пользователь раскрыл в прошлый раз.
const queuesSeq = ref(0);
const queueDrag = ref({ queueId: 0, taskId: 0, overId: 0 });
// Предупреждение «задача не первая в очереди» — модальный диалог, а не ConfirmPopup:
// попап цепляется к элементу-якорю, а при drop надёжного якоря нет (currentTarget к
// моменту показа уже сброшен), и подтверждение уезжало в угол экрана.
const queueStart = ref({ open: false, task: null, column: null, from: null, index: -1, queue: null });
const nonEmptyQueues = computed(() => queues.value.filter((q) => (q.tasks || []).length > 0));
const hasQueues = computed(() => nonEmptyQueues.value.length > 0);

/**
 * Индекс задачи, которая поедет в работу следующей: первая по порядку из тех, что ещё
 * стоят в начальной стадии. Задачу очереди, уже уехавшую в работу, помечать «следующей»
 * нельзя — она уже стартовала.
 */
function nextIndex(queue) {
    return (queue.tasks || []).findIndex((task) => task.is_initial);
}

/** Кнопка «Очереди»: открывает окно со свёрнутыми очередями либо закрывает его. */
function toggleQueues() {
    if (!queuesOpen.value) queuesSeq.value += 1;
    queuesOpen.value = !queuesOpen.value;
}

/** Очередь задачи по её queue_id — нужна, чтобы понять, первая ли она в очереди. */
function queueOf(task) {
    const id = Number(task?.queue_id) || 0;
    return id ? queues.value.find((q) => Number(q.id) === id) || null : null;
}

// Проекты выбранного отдела (у проекта есть department_id).
const projectsInDepartment = computed(
    () => projects.value.filter((p) => Number(p.department_id) === departmentId.value),
);

onMounted(init);
onUnmounted(() => {
    if (liveReloadTimer) window.clearTimeout(liveReloadTimer);
});

async function init() {
    try {
        const [d, p] = await Promise.all([DepartmentApi.getList(), ProjectApi.getList()]);
        departments.value = listOf(d);
        projects.value = listOf(p);
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_refs_load'), detail: errorMessage(e), life: 8000 });
        return;
    }
    // Задача восстановлена по хэшу — контекст доски задаст её project_id (см.
    // onTaskLoaded), первый проект не навязываем, иначе перезатрём проект задачи.
    if (openTaskId.value) {
        if (pendingProjectId.value && applyTaskContext(pendingProjectId.value)) {
            pendingProjectId.value = 0;
        }
        return;
    }
    if (departments.value.length) {
        const saved = readSavedFilters();
        const validDept = saved && departments.value.some((d) => Number(d.id) === Number(saved.departmentId));
        departmentId.value = validDept ? Number(saved.departmentId) : (Number(departments.value[0].id) || 0);
        if (saved) {
            if (['all', 'author', 'assignee'].includes(saved.filter)) filter.value = saved.filter;
            if (Number.isInteger(saved.priorityFilter)) priorityFilter.value = saved.priorityFilter;
        }
        // Проект восстанавливаем, только если он есть в выбранном отделе; иначе — первый.
        const validProj = saved && projectsInDepartment.value.some((p) => p.key === saved.projectKey);
        if (validProj) projectKey.value = saved.projectKey;
        else pickFirstProject();
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

async function load(options = {}) {
    if (!projectKey.value) {
        columns.value = [];
        return;
    }
    const silent = !!options.silent;
    if (!silent) loading.value = true;
    try {
        const res = await BoardApi.get({
            project: projectKey.value,
            mine: filter.value === 'assignee' ? 1 : 0,
            author_id: filter.value === 'author' ? userId : 0,
            assignee_id: filter.value === 'assignee' ? userId : 0,
        });
        columns.value = normalizeBoard(res).columns;
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_board_load'), detail: errorMessage(e), life: 8000 });
    } finally {
        if (!silent) loading.value = false;
    }
    loadQueues();
}

// Очереди грузим отдельно от доски: их отсутствие не должно мешать показу карточек,
// поэтому сбой здесь только гасит панель, а не рушит экран.
async function loadQueues() {
    const project = projects.value.find((p) => p.key === projectKey.value);
    if (!project) {
        queues.value = [];
        return;
    }
    try {
        const res = await QueueApi.getList(project.id, true);
        queues.value = listOf(res);
    } catch (e) {
        queues.value = [];
    }
    if (!hasQueues.value) queuesOpen.value = false;
}

// Реактивная синхронизация со «Структурой» (без перезагрузки страницы):
// новый/изменённый проект — освежаем селектор; изменение колонок (состав/порядок/
// цвет/копирование) — перечитываем доску текущего проекта.
async function reloadProjects() {
    try {
        projects.value = listOf(await ProjectApi.getList());
    } catch (e) { /* тихо: освежится при следующем действии */ }
}
watch(() => revisions.projects, reloadProjects);
watch(() => revisions.columns, () => {
    if (projectKey.value) load();
});

let liveReloadTimer = 0;
function scheduleLiveReload() {
    if (liveReloadTimer) window.clearTimeout(liveReloadTimer);
    liveReloadTimer = window.setTimeout(() => {
        liveReloadTimer = 0;
        load({ silent: true });
    }, 250);
}

watch(() => liveEvents.seq, () => {
    const event = liveEvents.last;
    if (!event || !projectKey.value || event.project_key !== projectKey.value) return;
    scheduleLiveReload();
});

function openTask(task) {
    openTaskId.value = task.id;
}

/** Переход к задаче из окна очередей: сначала закрываем окно, иначе карточка откроется под ним. */
function openQueueTask(task) {
    queuesOpen.value = false;
    openTask(task);
}

// Удаление задачи прямо с доски (иконка в углу карточки). Права проверяет сервер
// (TaskService::delete), на клиенте иконка уже скрыта для не-автора/не-менеджера.
function deleteTask(task, anchorEl) {
    confirm.require({
        target: anchorEl,
        message: t('mxboard_msg_confirm_delete_task'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('mxboard_ui_delete'),
        rejectLabel: t('mxboard_ui_cancel'),
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            try {
                await TaskApi.remove(task.id);
                toast.add({ severity: 'success', summary: t('mxboard_msg_task_deleted'), life: 3000 });
                if (openTaskId.value === task.id) openTaskId.value = 0;
                await load();
            } catch (e) {
                toast.add({ severity: 'error', summary: t('mxboard_err_remove_failed'), detail: errorMessage(e), life: 8000 });
            }
        },
    });
}

// Задача открыта напрямую по хэшу (перезагрузка) — доска ещё не выбрала проект/отдел.
// Восстанавливаем их из project_id задачи, чтобы работали список исполнителей и
// создание подзадачи. Список проектов грузится асинхронно — если ещё пуст, запомним
// project_id и применим после init().
const pendingProjectId = ref(0);

function applyTaskContext(projectId) {
    const proj = projects.value.find((p) => Number(p.id) === projectId);
    if (!proj) return false;
    projectKey.value = proj.key;
    departmentId.value = Number(proj.department_id) || 0;
    // Догружаем доску под задачей, чтобы возврат «назад» не показал пустой экран.
    load();
    return true;
}

function onTaskLoaded({ project_id: projectId }) {
    const pid = Number(projectId) || 0;
    if (!pid || projectKey.value) return; // проект уже выбран — доска в норме
    if (!applyTaskContext(pid)) pendingProjectId.value = pid;
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
    const index = from.tasks.findIndex((task) => task.id === taskId);
    if (index === -1) return;
    const task = from.tasks[index];

    // Клиентская страховка: заведомо запрещённое закрытие не гоняем на сервер.
    if (column.is_final && !cfg.can_move_any && task.author_id !== userId) {
        toast.add({ severity: 'warn', summary: t('mxboard_err_close_author_only'), life: 6000 });
        return;
    }

    // Старт очереди не с первой задачи меняет её порядок — об этом предупреждаем и
    // ждём подтверждения. Перетаскивание в любую другую стадию очереди не касается.
    const queue = column.is_start ? queueOf(task) : null;
    const first = queue ? (queue.tasks || [])[0] : null;
    if (queue && first && Number(first.id) !== taskId) {
        queueStart.value = { open: true, task, column, from, index, queue };
        return;
    }

    await moveTask(task, column, from, index);
}

/**
 * Подтверждение старта очереди не с первой задачи: продолжаем — карточка становится
 * первой, остальные сдвигаются, и только потом идёт сам перевод в стартовую стадию.
 */
async function confirmQueueStart() {
    const { task, column, from, index } = queueStart.value;
    queueStart.value = { open: false, task: null, column: null, from: null, index: -1, queue: null };
    if (!task || !column || !from) return;

    try {
        await QueueApi.promote(task.id);
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
        return;
    }
    await moveTask(task, column, from, index);
    loadQueues();
}

/** Само перемещение с оптимистичным откатом — общая часть обычного drop и старта очереди. */
async function moveTask(task, column, from, index) {
    from.tasks.splice(index, 1);
    column.tasks.push(task);
    task.column_key = column.key;

    try {
        const res = await TaskApi.move(task.id, column.key);
        if (res.object) Object.assign(task, normalizeTask(res.object));
        // Закрытие задачи очереди тянет следующую в работу — доску и панель надо
        // перечитать, иначе автозапуск станет виден только после ручного обновления.
        if (task.queue_id) {
            await load({ silent: true });
        } else {
            loadQueues();
        }
    } catch (e) {
        const back = column.tasks.findIndex((x) => x.id === task.id);
        if (back !== -1) column.tasks.splice(back, 1);
        task.column_key = from.key;
        from.tasks.splice(index, 0, task);
        toast.add({ severity: 'error', summary: t('mxboard_msg_move_rejected'), detail: errorMessage(e), life: 8000 });
    }
}

// --- Порядок внутри очереди: свой drag-and-drop, отдельный от DnD колонок ---

function onQueueDragStart(queue, task, ev) {
    queueDrag.value = { queueId: Number(queue.id), taskId: Number(task.id), overId: 0 };
    if (ev.dataTransfer) {
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', String(task.id));
    }
}

function onQueueDragOver(queue, task, ev) {
    if (!queueDrag.value.taskId || queueDrag.value.queueId !== Number(queue.id)) return;
    ev.preventDefault();
    if (ev.dataTransfer) ev.dataTransfer.dropEffect = 'move';
    queueDrag.value.overId = Number(task.id);
}

function onQueueDragEnd() {
    queueDrag.value = { queueId: 0, taskId: 0, overId: 0 };
}

/**
 * Клавиатура для строки очереди: Enter/Пробел открывают задачу, Alt+↑/↓ двигают её
 * по очереди. Drag-and-drop мышью — не единственный способ задать порядок.
 */
function onQueueKeydown(queue, index, ev) {
    const task = (queue.tasks || [])[index];
    if (!task) return;

    if (ev.key === 'Enter' || ev.key === ' ' || ev.key === 'Spacebar') {
        ev.preventDefault();
        openQueueTask(task);
        return;
    }
    if (!ev.altKey || (ev.key !== 'ArrowUp' && ev.key !== 'ArrowDown')) return;

    ev.preventDefault();
    const to = ev.key === 'ArrowUp' ? index - 1 : index + 1;
    moveInQueue(queue, index, to, ev.currentTarget);
}

/** Переставить задачу очереди с позиции на позицию и сохранить новый порядок. */
async function moveInQueue(queue, from, to, focusEl) {
    const tasks = [...(queue.tasks || [])];
    if (to < 0 || to >= tasks.length) return;

    const before = queue.tasks;
    const [moved] = tasks.splice(from, 1);
    tasks.splice(to, 0, moved);
    queue.tasks = tasks;

    // Строка уехала на новое место — возвращаем на неё фокус, иначе после первого
    // же нажатия клавиатура «теряет» карточку и повторить сдвиг нечем.
    await nextTick();
    const rows = focusEl?.parentElement?.children;
    if (rows && rows[to]) rows[to].focus();

    try {
        await QueueApi.reorder(queue.id, tasks.map((x) => Number(x.id)));
    } catch (e) {
        queue.tasks = before;
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    }
}

/**
 * Бросили карточку очереди на другую: переставляем её на место цели и шлём ПОЛНЫЙ
 * порядок — процессор принимает только полную перестановку, чтобы не оставлять дыр.
 */
async function onQueueDrop(queue, target) {
    const dragged = queueDrag.value.taskId;
    onQueueDragEnd();

    if (!dragged || Number(target.id) === dragged) return;

    const tasks = [...(queue.tasks || [])];
    const fromIndex = tasks.findIndex((x) => Number(x.id) === dragged);
    const toIndex = tasks.findIndex((x) => Number(x.id) === Number(target.id));
    if (fromIndex === -1 || toIndex === -1) return;

    const before = queue.tasks;
    const [moved] = tasks.splice(fromIndex, 1);
    tasks.splice(toIndex, 0, moved);
    queue.tasks = tasks;

    try {
        await QueueApi.reorder(queue.id, tasks.map((x) => Number(x.id)));
        toast.add({ severity: 'success', summary: t('mxboard_ui_queue_reordered'), life: 2000 });
    } catch (e) {
        queue.tasks = before;
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
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
        @loaded="onTaskLoaded"
    />

    <div v-else>
        <div class="mxb-toolbar">
            <Select
                v-model="departmentId"
                :options="departments"
                option-label="name"
                option-value="id"
                :placeholder="t('mxboard_ui_department')"
                @change="onDepartmentChange"
            />
            <Select
                v-model="projectKey"
                :options="projectsInDepartment"
                option-label="name"
                option-value="key"
                :placeholder="t('mxboard_ui_project')"
                @change="load"
            />
            <Select
                v-model="filter"
                :options="FILTERS"
                option-label="label"
                option-value="value"
                @change="load"
                style="max-width: 170px"
            />
            <Select
                v-model="priorityFilter"
                :options="PRIORITY_FILTERS"
                option-label="label"
                option-value="value"
                :placeholder="t('mxboard_ui_priority')"
                style="max-width: 160px"
            />
            <Button
                :label="t('mxboard_ui_reset_filters')"
                icon="pi pi-filter-slash"
                size="small"
                severity="secondary"
                text
                @click="resetFilters"
            />
            <!-- Кнопка появляется, только когда у выбранного проекта есть непустые очереди. -->
            <Button
                v-if="hasQueues"
                :label="t('mxboard_ui_queues')"
                icon="pi pi-list"
                size="small"
                :severity="queuesOpen ? 'primary' : 'secondary'"
                :outlined="!queuesOpen"
                :badge="String(nonEmptyQueues.length)"
                @click="toggleQueues"
            />
            <span class="mxb-toolbar-spacer" />
            <Button
                :label="t('mxboard_ui_new_task')"
                icon="pi pi-plus"
                size="small"
                :disabled="!projectKey"
                @click="createOpen = true"
            />
            <Button
                :label="t('mxboard_ui_refresh')"
                icon="pi pi-refresh"
                size="small"
                severity="secondary"
                outlined
                :loading="loading"
                @click="load"
            />
        </div>

        <!-- Очереди — в модальном окне: доска остаётся целиком видимой, а список
             очередей не оттесняет колонки вниз. Внутри — аккордеон, в раскрытой
             очереди её задачи (номер + заголовок), порядок меняется перетаскиванием. -->
        <!-- append-to=".vueApp": PrimeIcons подключены правилами `.vueApp .pi*`, а по
             умолчанию модалка уезжает в <body> — вне скоупа, и иконки в ней пропадают. -->
        <Dialog
            v-model:visible="queuesOpen"
            modal
            append-to=".vueApp"
            :header="t('mxboard_ui_queues')"
            :style="{ width: '720px' }"
            :breakpoints="{ '900px': '95vw' }"
        >
            <div class="mxb-queues">
                <Panel
                    v-for="queue in nonEmptyQueues"
                    :key="`${queuesSeq}-${queue.id}`"
                    toggleable
                    :collapsed="true"
                >
                    <template #header>
                        <span class="mxb-queue-head">
                            {{ queue.name }}
                            <span class="mxb-queue-count">{{ (queue.tasks || []).length }}</span>
                        </span>
                    </template>
                    <ol class="mxb-queue-list">
                        <li
                            v-for="(task, index) in queue.tasks"
                            :key="task.id"
                            class="mxb-queue-item"
                            :class="{
                                'mxb-queue-item--drag': queueDrag.taskId === Number(task.id),
                                'mxb-queue-item--over': queueDrag.overId === Number(task.id),
                                'mxb-queue-item--next': index === nextIndex(queue),
                            }"
                            draggable="true"
                            tabindex="0"
                            @dragstart="onQueueDragStart(queue, task, $event)"
                            @dragend="onQueueDragEnd"
                            @dragover="onQueueDragOver(queue, task, $event)"
                            @drop.prevent="onQueueDrop(queue, task)"
                            @click="openQueueTask(task)"
                            @keydown="onQueueKeydown(queue, index, $event)"
                        >
                            <i class="pi pi-bars mxb-queue-grip" aria-hidden="true" />
                            <span class="mxb-queue-pos">{{ index + 1 }}</span>
                            <span class="mxb-queue-num">{{ task.num || `#${task.id}` }}</span>
                            <span class="mxb-queue-title">{{ task.title }}</span>
                            <span v-if="index === nextIndex(queue)" class="mxb-queue-next">{{ t('mxboard_ui_queue_next') }}</span>
                            <span v-else-if="!task.is_initial" class="mxb-queue-stage">{{ task.column_name || task.column_key }}</span>
                        </li>
                    </ol>
                    <div v-if="!(queue.tasks || []).length" class="mxb-empty">{{ t('mxboard_ui_queue_empty') }}</div>
                </Panel>
            </div>
            <div v-if="!hasQueues" class="mxb-empty">{{ t('mxboard_ui_queue_empty') }}</div>
            <p class="mxb-queue-hint">{{ t('mxboard_ui_queue_hint') }}</p>
        </Dialog>

        <div v-if="!projectKey" class="mxb-empty">{{ t('mxboard_ui_no_projects') }}</div>

        <div v-else class="mxb-columns">
            <div
                v-for="(column, ci) in filteredColumns"
                :key="column.key"
                class="mxb-column"
                :class="{ 'mxb-column--over': dragOverKey === column.key, 'mxb-column--final': column.is_final }"
                :style="{ '--col-color': columnColor(column, ci) }"
                @dragover="onDragOver(column, $event)"
                @dragleave="dragOverKey = dragOverKey === column.key ? '' : dragOverKey"
                @drop.prevent="onDrop(column)"
            >
                <div class="mxb-column-head">
                    <span class="mxb-column-dot" />
                    <i v-if="column.is_final" class="pi pi-check-circle" />
                    <span class="mxb-column-name">{{ column.name }}</span>
                    <span class="mxb-column-count">{{ column.tasks.length }}</span>
                </div>
                <div class="mxb-column-body">
                    <TaskCard
                        v-for="task in column.tasks"
                        :key="task.id"
                        :task="task"
                        :dragging="dragTaskKey === String(task.id)"
                        @open="openTask(task)"
                        @delete="deleteTask(task, $event)"
                        @dragstart="onDragStart(task, column, $event)"
                        @dragend="onDragEnd"
                    />
                    <div v-if="!column.tasks.length" class="mxb-empty mxb-empty--drop">
                        <i class="pi pi-inbox" />
                        <span>{{ t('mxboard_ui_empty') }}</span>
                    </div>
                </div>
            </div>
        </div>

        <Dialog
            v-model:visible="queueStart.open"
            modal
            append-to=".vueApp"
            :header="queueStart.queue?.name || t('mxboard_ui_queue')"
            :style="{ width: '480px' }"
        >
            <p class="mxb-queue-warn">{{ t('mxboard_ui_queue_not_first') }}</p>
            <template #footer>
                <div class="mxb-dialog-actions">
                    <Button
                        :label="t('mxboard_ui_queue_cancel')"
                        severity="secondary"
                        outlined
                        @click="queueStart.open = false"
                    />
                    <Button :label="t('mxboard_ui_queue_continue')" icon="pi pi-check" @click="confirmQueueStart" />
                </div>
            </template>
        </Dialog>

        <NewTaskDialog
            v-model:visible="createOpen"
            :department-id="departmentId"
            :project-key="projectKey"
            @created="load"
        />
    </div>
</template>
