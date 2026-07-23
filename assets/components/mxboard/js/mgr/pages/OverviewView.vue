<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { DataTable, Column, Select, MultiSelect, Button, useToast } from 'primevue';
import { OverviewApi, errorMessage } from '../api/connector.js';
import {
    PRIORITIES, priorityMeta, stageColor, fmtDay, deadlineTone, factHours, factRunning,
} from '../utils/format.js';
import { t } from '../utils/i18n.js';
import TaskPage from './TaskPage.vue';

// Обзор отдела: плоская таблица задач ВСЕХ его проектов. Отличие от доски — не «одна
// колонка = одна стадия», а срез руководителя: пять множественных фильтров и сортировка
// по любому столбцу. Выборку и права считает сервер (Overview\GetList), здесь только
// представление.
const toast = useToast();
const cfg = window.MxBoardConfig || {};
const userId = Number(cfg.user_id) || 0;

const departments = ref([]);
const departmentId = ref(0);
const projects = ref([]);
const users = ref([]);
const stages = ref([]);

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const metaLoaded = ref(false);

// Множественные фильтры: пустой массив = «любой», как и на сервере.
const filters = ref({ priority: [], project_id: [], author_id: [], assignee_id: [], stage: [] });

// Страница, её размер и сортировка — состояние ЗАПРОСА, а не таблицы: в браузер приезжает
// одна страница, поэтому и листание, и порядок строк считает сервер.
const PAGE_SIZES = [25, 50, 100];
const page = ref(1);
const perPage = ref(PAGE_SIZES[0]);
const sortBy = ref('');
const sortDir = ref('DESC');

const first = computed(() => (page.value - 1) * perPage.value);
// null, а не 'priority': пока пользователь не сортировал сам, у таблицы не должно быть
// стрелки на колонке — порядок по умолчанию задаёт сервер.
const sortOrder = computed(() => (sortBy.value ? (sortDir.value === 'ASC' ? 1 : -1) : null));

const priorityOptions = computed(() => [...PRIORITIES].sort((a, b) => b.value - a.value));

// Фильтры переживают перезагрузку; ключ на пользователя — на общем браузере
// выбор не «протекает» между аккаунтами (та же схема, что у доски).
const STATE_KEY = `mxb_overview_filters_${userId}`;
function saveState() {
    try {
        localStorage.setItem(STATE_KEY, JSON.stringify({
            departmentId: departmentId.value,
            filters: filters.value,
            // Размер страницы и порядок — та же настройка рабочего места, что и фильтры:
            // возвращаться каждый раз к 25 строкам «важное сверху» пришлось бы вручную.
            perPage: perPage.value,
            sortBy: sortBy.value,
            sortDir: sortDir.value,
        }));
    } catch (e) { /* localStorage недоступен — не критично */ }
}
function readState() {
    try {
        return JSON.parse(localStorage.getItem(STATE_KEY) || 'null');
    } catch (e) { return null; }
}

// Клик по фильтру = запрос; MultiSelect отдаёт change на каждую галочку, поэтому
// перезагрузку схлопываем таймером — иначе выбор трёх исполнителей это три выборки.
let reloadTimer = 0;
function scheduleLoad() {
    saveState();
    window.clearTimeout(reloadTimer);
    // Сузили фильтр — возвращаемся на первую страницу: остаться на пятой значит увидеть
    // пустую таблицу там, где результаты есть.
    reloadTimer = window.setTimeout(() => load(true), 250);
}

async function loadMeta(withDepartment = true) {
    try {
        const res = await OverviewApi.meta(withDepartment ? departmentId.value : 0);
        const data = res.object || {};
        departments.value = Array.isArray(data.departments) ? data.departments : [];
        projects.value = Array.isArray(data.projects) ? data.projects : [];
        users.value = Array.isArray(data.users) ? data.users : [];
        stages.value = Array.isArray(data.stages) ? data.stages : [];
    } catch (e) {
        toast.add({ severity: 'error', detail: errorMessage(e), life: 6000 });
    } finally {
        metaLoaded.value = true;
    }
}

async function load(resetPage = false) {
    if (resetPage) page.value = 1;
    if (!departmentId.value) {
        rows.value = [];
        total.value = 0;
        return;
    }
    loading.value = true;
    try {
        const res = await OverviewApi.getList(departmentId.value, filters.value, {
            page: page.value,
            per_page: perPage.value,
            sort_by: sortBy.value,
            sort_dir: sortDir.value,
        });
        const data = res.object || {};
        rows.value = Array.isArray(data.tasks) ? data.tasks : [];
        total.value = Number(data.total) || 0;
        // Номер страницы берём из ответа: сервер поджимает его к последней существующей,
        // и рассинхрон дал бы листалку, показывающую страницу, которой уже нет.
        page.value = Number(data.page) || 1;
    } catch (e) {
        rows.value = [];
        total.value = 0;
        toast.add({ severity: 'error', detail: errorMessage(e), life: 6000 });
    } finally {
        loading.value = false;
    }
}

// Листалка и сортировка: оба обработчика меняют параметры ЗАПРОСА и идут на сервер —
// в lazy-режиме DataTable сама ничего не режет и не сортирует.
function onPage(event) {
    perPage.value = Number(event.rows) || PAGE_SIZES[0];
    page.value = Math.floor((Number(event.first) || 0) / perPage.value) + 1;
    saveState();
    load();
}

function onSort(event) {
    // removable-sort третьим кликом снимает сортировку (sortField = null) — возвращаемся
    // к порядку по умолчанию, который задаёт сервер.
    sortBy.value = event.sortField || '';
    sortDir.value = Number(event.sortOrder) === 1 ? 'ASC' : 'DESC';
    saveState();
    load(true);
}

// Смена отдела обнуляет фильтры: проекты, участники и стадии у другого отдела свои,
// и сохранённый выбор дал бы пустую выдачу по несуществующим id.
async function onDepartmentChange() {
    filters.value = { priority: [], project_id: [], author_id: [], assignee_id: [], stage: [] };
    saveState();
    await loadMeta();
    await load(true);
}

function resetFilters() {
    filters.value = { priority: [], project_id: [], author_id: [], assignee_id: [], stage: [] };
    saveState();
    load(true);
}

const hasFilters = computed(() => Object.values(filters.value).some((v) => v && v.length));

// Ручной switch «таблица ↔ карточка», как на доске. Хэш здесь НЕ трогаем: `#task-<id>`
// слушает BoardView на соседней вкладке, и запись в хэш заставила бы её параллельно
// грузить ту же задачу. Плата — открытая карточка не переживает перезагрузку страницы.
const openTask = ref(null);

function openRow(event) {
    const row = event?.data;
    if (row?.id) openTask.value = row;
}

function closeTask() {
    openTask.value = null;
    load();
}

// Цвет стадии — общий помощник доски: у стадии без своего цвета берётся фолбэк по
// позиции, иначе «Готово» и «Бэклог» слились бы в один серый на всю таблицу.
function stageTone(row) {
    return stageColor(
        { color: row.column_color, is_final: row.column_final, is_initial: row.column_initial },
        row.column_position,
    );
}

function priorityHint(value) {
    return t('mxboard_ui_overview_priority_hint', { name: priorityMeta(value).label });
}

function deadlineText(row) {
    return row.deadlineon ? fmtDay(row.deadlineon) : t('mxboard_ui_overview_no_deadline');
}

function planText(row) {
    const h = Number(row.plan_hours) || 0;
    return h > 0 ? `${h} ${t('mxboard_ui_hours_short')}` : t('mxboard_ui_overview_no_plan');
}

function factText(row) {
    if (!Number(row.startedon)) return t('mxboard_ui_overview_no_plan');
    return `${factHours(row)} ${t('mxboard_ui_hours_short')}`;
}

onMounted(async () => {
    const saved = readState();
    await loadMeta(false);
    const savedId = Number(saved?.departmentId) || 0;
    const exists = departments.value.some((d) => Number(d.id) === savedId);
    departmentId.value = exists ? savedId : (Number(departments.value[0]?.id) || 0);
    if (exists && saved?.filters) {
        filters.value = { ...filters.value, ...saved.filters };
    }
    // Размер страницы и сортировку восстанавливаем всегда: они не привязаны к отделу,
    // в отличие от фильтров с их id проектов и участников. Значения валидируем — в
    // localStorage мог остаться размер из прошлой версии интерфейса.
    if (PAGE_SIZES.includes(Number(saved?.perPage))) perPage.value = Number(saved.perPage);
    if (typeof saved?.sortBy === 'string') sortBy.value = saved.sortBy;
    if (saved?.sortDir === 'ASC' || saved?.sortDir === 'DESC') sortDir.value = saved.sortDir;
    if (departmentId.value) {
        await loadMeta();
        await load();
    }
});

watch(() => filters.value, scheduleLoad, { deep: true });
</script>

<template>
    <!-- Карточка задачи заменяет таблицу целиком (ручной switch, как на доске). -->
    <TaskPage
        v-if="openTask"
        :task-id="openTask.id"
        :department-id="departmentId"
        :project-key="openTask.project_key"
        :can-move-any="!!cfg.can_move_any"
        :user-id="userId"
        :back-label="t('mxboard_ui_overview_back')"
        @back="closeTask"
        @open-task="openTask = { id: $event, project_key: openTask.project_key }"
        @changed="load"
    />

    <div v-else class="mxb-ov">
        <div v-if="metaLoaded && !departments.length" class="mxb-ov-empty">
            <i class="pi pi-lock" /> {{ t('mxboard_ui_overview_no_departments') }}
        </div>

        <template v-else>
            <div class="mxb-toolbar mxb-ov-toolbar">
                <Select
                    v-model="departmentId"
                    :options="departments"
                    option-label="name"
                    option-value="id"
                    :placeholder="t('mxboard_ui_department')"
                    @change="onDepartmentChange"
                />
                <MultiSelect
                    v-model="filters.priority"
                    :options="priorityOptions"
                    option-label="label"
                    option-value="value"
                    :show-toggle-all="false"
                    :placeholder="t('mxboard_ui_overview_filter_priority')"
                    class="mxb-ov-filter"
                />
                <MultiSelect
                    v-model="filters.project_id"
                    :options="projects"
                    option-label="name"
                    option-value="id"
                    filter
                    :placeholder="t('mxboard_ui_overview_filter_project')"
                    class="mxb-ov-filter"
                />
                <MultiSelect
                    v-model="filters.author_id"
                    :options="users"
                    option-label="username"
                    option-value="id"
                    filter
                    :placeholder="t('mxboard_ui_overview_filter_author')"
                    class="mxb-ov-filter"
                />
                <MultiSelect
                    v-model="filters.assignee_id"
                    :options="users"
                    option-label="username"
                    option-value="id"
                    filter
                    :placeholder="t('mxboard_ui_overview_filter_assignee')"
                    class="mxb-ov-filter"
                />
                <MultiSelect
                    v-model="filters.stage"
                    :options="stages"
                    option-label="name"
                    option-value="key"
                    :placeholder="t('mxboard_ui_overview_filter_stage')"
                    class="mxb-ov-filter"
                />
                <Button
                    v-if="hasFilters"
                    :label="t('mxboard_ui_reset_filters')"
                    icon="pi pi-filter-slash"
                    size="small"
                    severity="secondary"
                    text
                    @click="resetFilters"
                />
                <span class="mxb-toolbar-spacer" />
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

            <div class="mxb-ov-status">
                <span>{{ t('mxboard_ui_overview_count', { total }) }}</span>
            </div>

            <!-- lazy: страницу, порядок и общее количество считает сервер. Без него
                 таблица листала бы и сортировала только загруженную пачку. -->
            <DataTable
                :value="rows"
                :loading="loading"
                data-key="id"
                size="small"
                striped-rows
                lazy
                paginator
                :first="first"
                :rows="perPage"
                :total-records="total"
                :rows-per-page-options="PAGE_SIZES"
                :sort-field="sortBy || null"
                :sort-order="sortOrder"
                removable-sort
                row-hover
                scrollable
                class="mxb-ov-table"
                @page="onPage"
                @sort="onSort"
                @row-click="openRow"
            >
                <!-- Пустое состояние разное: «фильтры отсекли всё» лечится кнопкой сброса,
                     «в отделе вообще нет задач» — нет, и предлагать сброс там бессмысленно. -->
                <template #empty>
                    <div class="mxb-ov-empty">
                        <template v-if="hasFilters">
                            <span>{{ t('mxboard_ui_overview_empty') }}</span>
                            <Button
                                :label="t('mxboard_ui_reset_filters')"
                                icon="pi pi-filter-slash"
                                size="small"
                                severity="secondary"
                                outlined
                                @click="resetFilters"
                            />
                        </template>
                        <span v-else>{{ t('mxboard_ui_overview_empty_department') }}</span>
                    </div>
                </template>

                <!-- Маркер приоритета. Цвет не единственный носитель смысла: название
                     приоритета есть в title и в подписи для скринридера. -->
                <Column field="priority" :sortable="true" style="width: 26px">
                    <template #header><span class="mxb-ov-sr">{{ t('mxboard_ui_overview_filter_priority') }}</span></template>
                    <template #body="{ data }">
                        <span
                            class="mxb-ov-prio"
                            :style="{ background: priorityMeta(data.priority).color || 'var(--mxb-ink-muted)' }"
                            :title="priorityHint(data.priority)"
                        />
                        <span class="mxb-ov-sr">{{ priorityHint(data.priority) }}</span>
                    </template>
                </Column>

                <!-- Номер — настоящая кнопка: клик по строке мышью удобен, но карточку
                     нужно уметь открыть и с клавиатуры (строка таблицы не фокусируется). -->
                <Column field="num" :header="t('mxboard_ui_overview_col_num')" :sortable="true" style="width: 110px">
                    <template #body="{ data }">
                        <button type="button" class="mxb-ov-num" @click.stop="openTask = data">{{ data.num }}</button>
                    </template>
                </Column>

                <Column field="title" :header="t('mxboard_ui_overview_col_title')" :sortable="true" style="min-width: 280px">
                    <template #body="{ data }">
                        <span class="mxb-ov-title" :title="data.title">{{ data.title }}</span>
                        <span class="mxb-ov-project">{{ data.project_name }}</span>
                    </template>
                </Column>

                <Column field="column_name" :header="t('mxboard_ui_overview_col_stage')" :sortable="true" style="width: 160px">
                    <template #body="{ data }">
                        <span class="mxb-ov-stage" :style="{ '--stage-color': stageTone(data) }">{{ data.column_name }}</span>
                    </template>
                </Column>

                <Column field="type_name" :header="t('mxboard_ui_overview_col_type')" :sortable="true" style="width: 130px">
                    <template #body="{ data }"><span class="mxb-ov-muted">{{ data.type_name || data.type_key }}</span></template>
                </Column>

                <Column field="author" :header="t('mxboard_ui_overview_col_author')" :sortable="true" style="width: 140px" />
                <Column field="assignee" :header="t('mxboard_ui_overview_col_assignee')" :sortable="true" style="width: 140px" />

                <Column field="deadlineon" :header="t('mxboard_ui_overview_col_deadline')" :sortable="true" style="width: 130px">
                    <template #body="{ data }">
                        <span class="mxb-ov-deadline" :data-tone="deadlineTone(data)">{{ deadlineText(data) }}</span>
                    </template>
                </Column>

                <Column
                    field="fact_hours"
                    :header="t('mxboard_ui_overview_col_time')"
                    :sortable="true"
                    style="width: 130px"
                >
                    <template #body="{ data }">
                        <span class="mxb-ov-time">
                            <span class="mxb-ov-muted">{{ planText(data) }}</span>
                            <span class="mxb-ov-sep">/</span>
                            <span :class="{ 'mxb-ov-running': factRunning(data) }">
                                {{ factText(data) }}
                                <i v-if="factRunning(data)" class="pi pi-clock" :title="t('mxboard_ui_overview_fact_running')" />
                            </span>
                        </span>
                    </template>
                </Column>
            </DataTable>
        </template>
    </div>
</template>
