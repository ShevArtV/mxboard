<script setup>
import { ref } from 'vue';
import { Toast, ConfirmPopup, Tabs, TabList, Tab, TabPanels, TabPanel, Popover, Button, Badge } from 'primevue';
import BoardView from './BoardView.vue';
import TokensView from './TokensView.vue';
import StructureView from './StructureView.vue';
import { t } from '../utils/i18n.js';
import { useNotifications } from '../utils/useNotifications.js';
import { fmtDate } from '../utils/format.js';

// Гейты вкладок (UI лишь прячет; финальную проверку делают процессоры/плагин):
// «Структура» — менеджеру (sudo/супер отдела); «Токены агентов» — только sudo.
const cfg = window.MxBoardConfig || {};
const isManager = !!cfg.is_manager;
const isSudo = !!cfg.is_sudo;
const tab = ref('board');

// Уведомления: живой SSE-поток + тосты внутри useNotifications, здесь — колокольчик и список.
const { state: notif, markAllSeen, summary, detail, typeLabel } = useNotifications();
const notifPanel = ref(null);

function toggleNotif(e) {
    notifPanel.value?.toggle(e);
}

// Открытие панели = «прочитано»: сбрасываем счётчик непрочитанных.
function onNotifShow() {
    markAllSeen();
}

// Навигация к задаче — через hash, как в BoardView (он слушает hashchange).
function openNotif(n) {
    if (n.task_id) window.location.hash = '#task-' + n.task_id;
    notifPanel.value?.hide();
}
</script>

<template>
    <div class="mxb">
        <Toast position="top-right" />
        <ConfirmPopup />

        <Popover ref="notifPanel" @show="onNotifShow">
            <div class="mxb-notif">
                <div class="mxb-notif-head">{{ t('mxboard_notify_title') }}</div>
                <div v-if="!notif.items.length" class="mxb-notif-empty">
                    <i class="pi pi-inbox" /> {{ t('mxboard_notify_empty') }}
                </div>
                <ul v-else class="mxb-notif-list">
                    <li
                        v-for="n in notif.items"
                        :key="n.id"
                        class="mxb-notif-item"
                        :class="{ 'mxb-notif-item--unseen': !n.seen }"
                        @click="openNotif(n)"
                    >
                        <span class="mxb-notif-type">{{ typeLabel(n) }}</span>
                        <span class="mxb-notif-summary">{{ summary(n) }}</span>
                        <span class="mxb-notif-detail">{{ detail(n) }}</span>
                        <span class="mxb-notif-time">{{ fmtDate(n.createdon) }}</span>
                    </li>
                </ul>
            </div>
        </Popover>

        <Tabs v-model:value="tab">
            <div class="mxb-tabbar">
                <TabList>
                    <Tab value="board"><i class="pi pi-th-large mxb-tab-icon" /> {{ t('mxboard_ui_board') }}</Tab>
                    <Tab v-if="isManager" value="structure"><i class="pi pi-sitemap mxb-tab-icon" /> {{ t('mxboard_ui_structure') }}</Tab>
                    <Tab v-if="isSudo" value="tokens"><i class="pi pi-key mxb-tab-icon" /> {{ t('mxboard_ui_tokens') }}</Tab>
                </TabList>
                <button
                    type="button"
                    class="mxb-bell"
                    :aria-label="t('mxboard_notify_title')"
                    @click="toggleNotif"
                >
                    <i class="pi pi-bell" />
                    <Badge
                        v-if="notif.unseen > 0"
                        :value="notif.unseen > 99 ? '99+' : notif.unseen"
                        severity="danger"
                        class="mxb-bell-badge"
                    />
                </button>
            </div>
            <TabPanels>
                <TabPanel value="board">
                    <BoardView />
                </TabPanel>
                <TabPanel v-if="isManager" value="structure">
                    <StructureView />
                </TabPanel>
                <TabPanel v-if="isSudo" value="tokens">
                    <TokensView />
                </TabPanel>
            </TabPanels>
        </Tabs>
    </div>
</template>

<style>
.mxb {
    font-size: 14px;
}

/* Токены объявлены и на .mxb, и на .vueApp. Модалки/попапы PrimeVue телепортируются
   из .mxb в корень приложения, и переменные, объявленные только на .mxb, там уже не
   видны: приглушённый текст, радиусы и тени молча схлопывались в дефолты, отчего
   содержимое окна выглядело плоским. */
.mxb,
.vueApp {
    /* Единая шкала радиусов/теней/отступов — чтобы поверхности читались одной рукой. */
    --mxb-radius-sm: 8px;
    --mxb-radius-md: 12px;
    --mxb-radius-lg: 16px;
    --mxb-radius-pill: 999px;
    --mxb-shadow-1: 0 1px 2px rgba(15, 23, 42, 0.06), 0 1px 3px rgba(15, 23, 42, 0.04);
    --mxb-shadow-2: 0 6px 16px rgba(15, 23, 42, 0.10);
    --mxb-space-1: 4px;
    --mxb-space-2: 8px;
    --mxb-space-3: 12px;
    --mxb-space-4: 16px;
    --mxb-space-5: 20px;
    /* Приглушённый, но проходящий по контрасту текст (не полупрозрачный серый). */
    --mxb-ink-muted: var(--p-text-color-secondary, #5b6472);
    --mxb-focus: 0 0 0 2px var(--p-surface-0, #fff), 0 0 0 4px var(--p-primary-400, #34d399);
}

/* Фокус-ринг для клавиатуры на наших кликабельных элементах (у PrimeVue-компонентов
   свой фокус — их не трогаем). Раньше focus-состояний не было нигде. */
.mxb-card:focus-visible,
.mxb-subtask:focus-visible,
.mxb-parent-link:focus-visible,
.mxb-filedrop:focus-visible,
.mxb-att:focus-visible,
.mxb-att-body:focus-visible,
.mxb-filefield-btn:focus-visible,
.mxb-composer-file-x:focus-visible,
.mxb-att-remove:focus-visible {
    outline: none;
    box-shadow: var(--mxb-focus);
}

/* Аватар пользователя (инициалы) — общий для доски, меты и чата. */
.mxb-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: none;
    border-radius: 50%;
    color: #fff;
    font-weight: 600;
    line-height: 1;
    user-select: none;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.25);
}

.mxb-avatar--sm {
    width: 22px;
    height: 22px;
    font-size: 10px;
}

.mxb-tab-icon {
    margin-right: 6px;
}

/* Общая панель: строка с табами слева и колокольчиком уведомлений справа.
   Фон/граница живут на всём ряду, а не только под табами, — иначе колокольчик
   справа «выпадает» из белой полосы TabList на серый фон страницы. */
.mxb-tabbar {
    display: flex;
    align-items: flex-end;
    gap: var(--mxb-space-3);
    background: var(--p-surface-0, #fff);
    border-bottom: 1px solid var(--p-content-border-color, #e2e5e9);
    padding-right: var(--mxb-space-2);
}

.mxb-tabbar > .p-tabs-tablist,
.mxb-tabbar > .p-tablist {
    flex: 1 1 auto;
    min-width: 0;
    /* Граница теперь у всего ряда — убираем собственную у TabList, чтобы линия
       шла под всей полосой, включая зону колокольчика, и не задваивалась. */
    border-bottom: none;
}

.mxb-tabbar > .mxb-bell {
    flex: none;
    align-self: center;
}

.mxb-bell {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: var(--mxb-radius-pill);
    background: var(--p-surface-0, #fff);
    color: var(--mxb-ink-muted);
    cursor: pointer;
    transition: color 0.14s ease, border-color 0.14s ease, box-shadow 0.14s ease;
}

.mxb-bell:hover {
    color: var(--p-primary-color, #10b981);
    border-color: color-mix(in srgb, var(--p-primary-400, #34d399) 55%, var(--p-content-border-color, #e2e5e9));
    box-shadow: var(--mxb-shadow-1);
}

.mxb-bell:focus-visible {
    outline: none;
    box-shadow: var(--mxb-focus);
}

.mxb-bell .pi-bell {
    font-size: 17px;
}

.mxb-bell-badge {
    position: absolute;
    top: -4px;
    right: -4px;
}

/* Панель уведомлений (Popover). */
.mxb-notif {
    width: 340px;
    max-width: 88vw;
}

.mxb-notif-head {
    font-weight: 700;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--mxb-ink-muted);
    padding: 2px 4px 10px;
}

.mxb-notif-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 22px 12px;
    color: var(--mxb-ink-muted);
    font-size: 13px;
}

.mxb-notif-empty .pi {
    font-size: 24px;
    color: var(--p-surface-300, #cbd5e1);
}

.mxb-notif-list {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 60vh;
    overflow-y: auto;
}

.mxb-notif-item {
    display: grid;
    grid-template-columns: auto 1fr auto;
    grid-template-areas:
        'type summary time'
        'detail detail detail';
    gap: 2px 8px;
    align-items: baseline;
    padding: 9px 8px;
    border-radius: var(--mxb-radius-sm);
    cursor: pointer;
    transition: background 0.12s ease;
}

.mxb-notif-item:hover {
    background: var(--p-surface-50, #f6f7f9);
}

/* Непрочитанное — мягкий фон + точка слева, без «фирменной» толстой боковой границы. */
.mxb-notif-item--unseen {
    background: color-mix(in srgb, var(--p-primary-500, #10b981) 7%, transparent);
}

.mxb-notif-item--unseen .mxb-notif-type::before {
    content: '';
    display: inline-block;
    width: 6px;
    height: 6px;
    margin-right: 6px;
    border-radius: 50%;
    background: var(--p-primary-500, #10b981);
    vertical-align: middle;
}

.mxb-notif-type {
    grid-area: type;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: var(--p-primary-700, #047857);
    white-space: nowrap;
}

.mxb-notif-summary {
    grid-area: summary;
    font-weight: 600;
    font-size: 13px;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--p-text-color, #1f2733);
}

.mxb-notif-detail {
    grid-area: detail;
    font-size: 12px;
    color: var(--mxb-ink-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.mxb-notif-time {
    grid-area: time;
    font-size: 11px;
    color: var(--mxb-ink-muted);
    white-space: nowrap;
}

.mxb-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--p-content-border-color, #e2e5e9);
}

.mxb-toolbar-spacer {
    flex: 1;
}

/* Колонки в ряд с горизонтальной прокруткой — доску не должно «складывать». */
.mxb-columns {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    overflow-x: auto;
    padding-bottom: 8px;
}

.mxb-column {
    --col-color: #6c757d;
    flex: 0 0 300px;
    width: 300px;
    background: var(--p-content-background, #f6f7f9);
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: var(--mxb-radius-md);
    display: flex;
    flex-direction: column;
    min-height: 160px;
    /* Влезть в область менеджера: колонка не выше вьюпорта (минус табы+тулбар+хедер),
       иначе длинный список карточек уходит под низ фрейма без прокрутки. Длинные
       списки скроллятся ВНУТРИ колонки (.mxb-column-body), шапка остаётся на месте. */
    max-height: calc(100vh - 210px);
    overflow: hidden;
}

.mxb-column--over {
    border-color: var(--col-color);
    box-shadow: 0 0 0 2px var(--col-color) inset;
}

/* Шапка стадии — тонирована цветом стадии (color-mix), это ведущий носитель
   иерархии доски. Цвет отзывается эхом в дедлайн-пилюлях и статусах. */
.mxb-column-head {
    display: flex;
    align-items: center;
    gap: var(--mxb-space-2);
    padding: 10px 12px;
    font-weight: 700;
    font-size: 13px;
    letter-spacing: 0.2px;
    color: color-mix(in srgb, var(--col-color) 72%, #1e2530);
    background: color-mix(in srgb, var(--col-color) 12%, var(--p-surface-0, #fff));
    border-bottom: 1px solid color-mix(in srgb, var(--col-color) 24%, var(--p-content-border-color, #e2e5e9));
}

.mxb-column-dot {
    flex: none;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--col-color);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--col-color) 20%, transparent);
}

.mxb-column-head .pi-check-circle {
    color: var(--col-color);
}

.mxb-column-name {
    text-transform: uppercase;
    font-size: 12px;
    letter-spacing: 0.4px;
}

.mxb-column-count {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 20px;
    padding: 0 7px;
    font-size: 12px;
    font-weight: 600;
    color: color-mix(in srgb, var(--col-color, #64748b) 78%, #1e2530);
    background: color-mix(in srgb, var(--col-color, #64748b) 16%, var(--p-surface-0, #fff));
    border-radius: var(--mxb-radius-pill);
}

.mxb-column-body {
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1;
    min-height: 80px;
    /* Скролл карточек внутри колонки — последняя карточка всегда доступна. */
    overflow-y: auto;
}

.mxb-empty {
    padding: 16px 8px;
    text-align: center;
    color: var(--mxb-ink-muted);
    font-size: 13px;
}

/* Очереди задач: аккордеон внутри модального окна (кнопка «Очереди» в фильтрах).
   Три уровня должны читаться сразу: окно → очередь → строка задачи. Приём тот же,
   что у доски: группа лежит на тонированной поверхности, а элементы внутри — белые
   с лёгкой тенью (ср. .mxb-column / .mxb-card). */
.mxb-queues {
    display: flex;
    flex-direction: column;
    gap: var(--mxb-space-3);
    /* Очередей и задач может быть много, а окно не должно вырастать за экран. */
    max-height: 60vh;
    overflow-y: auto;
}

/* Панель очереди = группа. Своя поверхность и радиус из общей шкалы, а не дефолт
   темы PrimeVue: иначе аккордеон выглядит деталью из чужого приложения. */
.mxb-queues .p-panel {
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: var(--mxb-radius-md);
    background: var(--p-surface-50, #f6f7f9);
    overflow: hidden;
}

/* Заголовок очереди не уезжает при прокрутке длинного списка — иначе на 60 строках
   перестаёшь понимать, в какой очереди находишься. Вся шапка кликабельна: она и есть
   переключатель, «плюс» справа — лишь его видимая часть. */
.mxb-queues .p-panel-header {
    position: sticky;
    top: 0;
    z-index: 1;
    padding: var(--mxb-space-2) var(--mxb-space-3);
    background: var(--p-surface-50, #f6f7f9);
    border-bottom: 1px solid var(--p-content-border-color, #e2e5e9);
    cursor: pointer;
    user-select: none;
    transition: background-color 0.16s ease;
}

.mxb-queues .p-panel-header:hover {
    background: var(--p-surface-100, #f1f5f9);
}

.mxb-queues .p-panel-content {
    padding: var(--mxb-space-2);
    background: transparent;
}

/* Внутренние обёртки Panel не ограничены по ширине: без этого они растягиваются по
   самой длинной строке, строка перестаёт сжиматься, и длинный заголовок выдавливает
   метку «Следующая» за край панели вместо многоточия. */
.mxb-queues .p-panel-content-container,
.mxb-queues .p-panel-content-wrapper,
.mxb-queues .p-panel-content,
.mxb-queue-list,
.mxb-queue-item {
    min-width: 0;
    max-width: 100%;
}

.mxb-queue-head {
    display: inline-flex;
    align-items: baseline;
    gap: var(--mxb-space-2);
    font-size: 14px;
    font-weight: 600;
}

/* Счётчик — спутник названия, а не соперник: мельче, легче, приглушён. */
.mxb-queue-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 18px;
    padding: 0 var(--mxb-space-2);
    font-size: 11px;
    font-weight: 600;
    color: var(--mxb-ink-muted);
    background: var(--p-surface-200, #e2e8f0);
    border-radius: var(--mxb-radius-pill);
}

.mxb-queue-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: var(--mxb-space-1);
}

.mxb-queue-item {
    display: flex;
    align-items: center;
    gap: var(--mxb-space-3);
    padding: var(--mxb-space-2) var(--mxb-space-3);
    font-size: 13px;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: var(--mxb-radius-sm);
    background: var(--p-surface-0, #fff);
    box-shadow: var(--mxb-shadow-1);
    /* Строку и тащат, и открывают кликом: курсор обещает то, что работает везде. */
    cursor: grab;
    transition: border-color 0.16s ease, box-shadow 0.16s ease;
}

.mxb-queue-item:hover {
    border-color: color-mix(in srgb, var(--p-primary-color, #10b981) 45%, var(--p-content-border-color, #e2e5e9));
    box-shadow: var(--mxb-shadow-2);
}

.mxb-queue-item:active {
    cursor: grabbing;
}

.mxb-queue-item:focus-visible {
    outline: none;
    box-shadow: var(--mxb-focus);
}

/* Первая задача = та, что поедет в работу следующей. Это центральная семантика
   очереди, поэтому она помечена и фоном, и подписью, а не только позицией в списке. */
.mxb-queue-item--next {
    background: color-mix(in srgb, var(--p-primary-color, #10b981) 7%, var(--p-surface-0, #fff));
    border-color: color-mix(in srgb, var(--p-primary-color, #10b981) 35%, var(--p-content-border-color, #e2e5e9));
}

.mxb-queue-next {
    flex: none;
    padding: 0 var(--mxb-space-2);
    font-size: 11px;
    font-weight: 600;
    line-height: 18px;
    color: var(--p-primary-700, #047857);
    background: color-mix(in srgb, var(--p-primary-color, #10b981) 16%, var(--p-surface-0, #fff));
    border-radius: var(--mxb-radius-pill);
    white-space: nowrap;
}

/* Задача очереди, которая уже в работе: показываем её стадию — иначе непонятно,
   почему «следующей» помечена не первая строка списка. */
.mxb-queue-stage {
    flex: none;
    padding: 0 var(--mxb-space-2);
    font-size: 11px;
    line-height: 18px;
    color: var(--mxb-ink-muted);
    background: var(--p-surface-100, #f1f5f9);
    border-radius: var(--mxb-radius-pill);
    white-space: nowrap;
}

/* Перетаскиваемая строка гаснет, цель вставки подсвечивается сверху — порядок
   в очереди меняется мышью, и без этих двух подсказок бросок «вслепую». */
.mxb-queue-item--drag {
    opacity: 0.45;
}

/* Линию вставки рисуем тенью, а не border'ом: border сдвинул бы строку на пиксель,
   и список бы «дёргался» под курсором на каждом переходе между элементами. */
.mxb-queue-item--over {
    box-shadow: inset 0 2px 0 0 var(--p-primary-color, #10b981);
}

.mxb-queue-grip {
    flex: none;
    color: var(--p-surface-400, #94a3b8);
    transition: color 0.16s ease;
}

.mxb-queue-item:hover .mxb-queue-grip {
    color: var(--mxb-ink-muted);
}

/* Позиция в очереди — ориентир при перетаскивании. Слабее заголовка размером, но не
   цветом: светло-серый давал 2.4:1 к фону, то есть был нечитаем. */
.mxb-queue-pos {
    flex: none;
    min-width: 14px;
    font-size: 11px;
    font-variant-numeric: tabular-nums;
    color: var(--mxb-ink-muted);
    text-align: right;
}

/* Номер карточки — адрес, а не заголовок: тот же приглушённый чип, что на доске. */
.mxb-queue-num {
    flex: none;
    padding: 0 var(--mxb-space-1);
    font-size: 11px;
    font-weight: 600;
    color: var(--mxb-ink-muted);
    background: var(--p-surface-100, #f1f5f9);
    border-radius: var(--mxb-radius-sm);
    white-space: nowrap;
}

/* Заголовок — то, ради чего строку читают: полный контраст текста. Сжимается он,
   а не соседи: без `min-width: 0` длинный заголовок выдавливает метку «Следующая»
   за край строки вместо того, чтобы обрезаться многоточием. */
.mxb-queue-title {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--p-text-color, #334155);
}

.mxb-queue-hint {
    margin: var(--mxb-space-3) 0 0;
    font-size: 12px;
    color: var(--mxb-ink-muted);
}

.mxb-queue-warn {
    margin: 0;
    line-height: 1.5;
}

@media (prefers-reduced-motion: reduce) {
    .mxb-queue-item,
    .mxb-queue-grip,
    .mxb-queues .p-panel-header {
        transition: none;
    }
}

.mxb-muted {
    color: var(--mxb-ink-muted);
}

/* Осмысленное пустое состояние: приглушённая иконка + строка, а не голый серый текст. */
.mxb-empty--rich {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 28px 12px;
}

.mxb-empty--rich .pi {
    font-size: 26px;
    color: var(--p-surface-300, #cbd5e1);
}

/* Пустая колонка доски — намёк на drop-зону. */
.mxb-empty--drop {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin: 2px;
    padding: 18px 12px;
    border: 1.5px dashed var(--p-content-border-color, #e2e5e9);
    border-radius: var(--mxb-radius-sm);
}

.mxb-empty--drop .pi {
    color: var(--p-surface-300, #cbd5e1);
}

.mxb-column--over .mxb-empty--drop {
    border-color: var(--col-color, #10b981);
    color: color-mix(in srgb, var(--col-color, #10b981) 75%, #1e2530);
}

.mxb-card {
    position: relative;
    background: var(--p-surface-0, #fff);
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: var(--mxb-radius-sm);
    padding: 11px 12px;
    cursor: pointer;
    transition: box-shadow 0.16s ease, transform 0.16s ease, border-color 0.16s ease;
    box-shadow: var(--mxb-shadow-1);
}

/* Иконка удаления в правом верхнем углу карточки: появляется на hover/фокусе,
   на тач-экранах видна всегда (там hover не срабатывает). */
.mxb-card-del {
    position: absolute;
    top: 6px;
    right: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: none;
    border-radius: 6px;
    background: transparent;
    color: var(--mxb-ink-muted, #6c757d);
    font-size: 12px;
    line-height: 1;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.12s, background 0.12s, color 0.12s;
}

.mxb-card:hover .mxb-card-del,
.mxb-card:focus-within .mxb-card-del,
.mxb-card-del:focus-visible {
    opacity: 1;
}

.mxb-card-del:hover,
.mxb-card-del:focus-visible {
    background: color-mix(in srgb, var(--p-red-500, #ef4444) 14%, transparent);
    color: var(--p-red-500, #ef4444);
}

@media (hover: none) {
    .mxb-card-del {
        opacity: 1;
    }
}

.mxb-card:hover {
    box-shadow: var(--mxb-shadow-2);
    border-color: color-mix(in srgb, var(--col-color, #6c757d) 40%, var(--p-content-border-color, #e2e5e9));
    transform: translateY(-1px);
}

.mxb-card--dragging {
    opacity: 0.45;
}

.mxb-card-title {
    font-weight: 600;
    line-height: 1.35;
    margin-bottom: 9px;
    word-break: break-word;
    overflow-wrap: anywhere;
    color: var(--p-text-color, #1f2733);
}

.mxb-card--deletable .mxb-card-title {
    margin-right: 28px;
}

/* Строка тегов (приоритет + тип) — визуально отделена от футера. */
.mxb-card-tags {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

/* Футер карточки: исполнитель слева, дедлайн-пилюля справа. */
.mxb-card-foot {
    display: flex;
    align-items: center;
    gap: var(--mxb-space-2);
    margin-top: 10px;
    padding-top: 9px;
    border-top: 1px solid var(--p-surface-100, #f1f3f5);
    font-size: 12px;
}

.mxb-card-assignee {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
    color: var(--mxb-ink-muted);
}

.mxb-card-assignee-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 130px;
}

/* Дедлайн-пилюля с относительным временем, тон по состоянию. */
.mxb-deadline-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: var(--mxb-radius-pill);
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
    background: var(--p-surface-100, #f1f3f5);
    color: var(--mxb-ink-muted);
}

.mxb-deadline-chip .pi {
    font-size: 11px;
}

.mxb-deadline-chip--overdue {
    background: color-mix(in srgb, var(--p-red-500, #ef4444) 14%, transparent);
    color: var(--p-red-600, #dc2626);
}

.mxb-deadline-chip--soon {
    background: color-mix(in srgb, var(--p-orange-500, #f59e0b) 16%, transparent);
    color: var(--p-orange-600, #d97706);
}

/* Пилюля «план / факт». Идущий замер подсвечен — видно, что время сейчас капает. */
.mxb-time-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: var(--mxb-radius-pill);
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
    background: var(--p-surface-100, #f1f3f5);
    color: var(--mxb-ink-muted);
}

.mxb-time-chip .pi {
    font-size: 11px;
}

.mxb-time-chip--running {
    background: color-mix(in srgb, var(--p-blue-500, #3b82f6) 14%, transparent);
    color: var(--p-blue-600, #2563eb);
}

.mxb-free {
    color: var(--p-orange-500, #f59e0b);
}

/* Рендер markdown (ToR, комментарии) — контент из v-html, scoped-стили его не достанут. */
.mxb-md {
    line-height: 1.5;
    word-break: break-word;
}

.mxb-md h1,
.mxb-md h2,
.mxb-md h3,
.mxb-md h4 {
    margin: 12px 0 6px;
    line-height: 1.3;
}

.mxb-md h1 { font-size: 18px; }
.mxb-md h2 { font-size: 16px; }
.mxb-md h3 { font-size: 15px; }

.mxb-md p { margin: 0 0 8px; }
.mxb-md ul,
.mxb-md ol { margin: 0 0 8px; padding-left: 22px; }
.mxb-md li { margin: 2px 0; }

.mxb-md blockquote {
    margin: 0 0 8px;
    padding: 4px 10px;
    border-left: 3px solid var(--p-content-border-color, #e2e5e9);
    opacity: 0.85;
}

.mxb-md code {
    background: var(--p-surface-100, #f1f3f5);
    padding: 1px 4px;
    border-radius: 3px;
    font-family: monospace;
    font-size: 12px;
}

.mxb-md pre.mxb-code {
    background: var(--p-surface-100, #f1f3f5);
    padding: 10px;
    border-radius: 6px;
    overflow-x: auto;
    margin: 0 0 8px;
}

.mxb-md pre.mxb-code code {
    background: none;
    padding: 0;
}

/* Кат длинного описания: ограничение по высоте с затуханием у нижнего края. */
.mxb-md--clamp {
    max-height: 320px;
    overflow: hidden;
    position: relative;
}
.mxb-md--clamp::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 56px;
    background: linear-gradient(to bottom, transparent, var(--p-surface-0, #fff));
    pointer-events: none;
}
.mxb-md-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    padding: 5px 14px;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: var(--mxb-radius-pill);
    background: var(--p-surface-0, #fff);
    color: var(--mxb-ink-muted);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}
.mxb-md-toggle:hover {
    background: var(--p-surface-50, #f6f7f9);
    color: var(--p-text-color, #1e2530);
}

/* Секция — не «карточка», а раздел: заголовок + верхний разделитель, без рамки и
   фона. Так вложенные подзадачи/плитки не образуют card-in-card. Единственные
   поверхности слева — мета-карта и (справа) чат. */
.mxb-section {
    margin-top: var(--mxb-space-4);
    padding-top: var(--mxb-space-4);
    border-top: 1px solid var(--p-content-border-color, #e2e5e9);
}

.mxb-section-title {
    font-weight: 700;
    margin-bottom: var(--mxb-space-3);
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    color: var(--mxb-ink-muted);
}

.mxb-section-title > .pi {
    color: var(--p-primary-color, #10b981);
    font-size: 14px;
}

.mxb-comment {
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: 8px;
    padding: 10px 12px;
    margin-bottom: 8px;
    background: var(--p-surface-50, #fafbfc);
}

.mxb-comment-head {
    font-size: 12px;
    opacity: 0.7;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.mxb-comment-edited {
    font-size: 11px;
    font-style: italic;
    opacity: 0.6;
}

.mxb-comment-edit {
    margin-top: 6px;
}

.mxb-log {
    font-size: 12px;
    display: flex;
    gap: 8px;
    padding: 4px 0;
    border-bottom: 1px dashed var(--p-content-border-color, #e2e5e9);
}

.mxb-log-time {
    color: var(--mxb-ink-muted);
    white-space: nowrap;
}

.mxb-textarea {
    width: 100%;
    min-height: 90px;
    padding: 8px 10px;
    border: 1px solid var(--p-inputtext-border-color, #d1d5db);
    border-radius: 6px;
    font: inherit;
    font-family: inherit;
    background: var(--p-inputtext-background, #fff);
    color: inherit;
    resize: vertical;
    box-sizing: border-box;
}

.mxb-textarea:focus {
    outline: none;
    border-color: var(--p-primary-color, #10b981);
}

.mxb-field {
    margin-bottom: 12px;
}

.mxb-field label {
    display: block;
    margin-bottom: 4px;
    font-weight: 600;
    font-size: 12px;
    color: var(--p-text-color-secondary, #6c757d);
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.mxb-hint {
    font-size: 12px;
    color: var(--mxb-ink-muted);
    margin-top: 4px;
}

.mxb-dialog-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}

.mxb-token-raw {
    border: 1px solid var(--p-orange-500, #f59e0b);
    background: rgba(245, 158, 11, 0.08);
    border-radius: 6px;
    padding: 12px;
}

.mxb-token-value {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-top: 8px;
}

.mxb-token-value code {
    flex: 1;
    font-family: monospace;
    font-size: 13px;
    word-break: break-all;
    background: var(--p-surface-100, #f1f3f5);
    padding: 8px;
    border-radius: 4px;
}

/* --- v2: типизация, дедлайны, подзадачи, страница задачи --- */

/* Нативный <input> под стиль PrimeVue (date/text вне компонентов). */
.mxb-input {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--p-inputtext-border-color, #d1d5db);
    border-radius: 6px;
    font: inherit;
    background: var(--p-inputtext-background, #fff);
    color: inherit;
    box-sizing: border-box;
}

.mxb-input:focus {
    outline: none;
    border-color: var(--p-primary-color, #10b981);
}

.mxb-row {
    display: flex;
    gap: 12px;
}

.mxb-col {
    flex: 1;
}

.mxb-col-2 {
    flex: 2;
}

.mxb-req {
    color: var(--p-red-500, #ef4444);
}

/* Метка типа/стадии на карточке и в шапке. */
.mxb-chip {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: var(--mxb-radius-pill);
    background: var(--p-surface-100, #f1f3f5);
    color: var(--mxb-ink-muted);
    font-size: 11px;
}

/* Тип задачи — приглушённый тег, уступает приоритету по весу. */
.mxb-chip--type {
    letter-spacing: 0.2px;
}

.mxb-sub-icon {
    margin-right: 4px;
    opacity: 0.6;
    font-size: 12px;
}

.mxb-overdue {
    color: var(--p-red-500, #ef4444);
}

.mxb-disputed {
    color: var(--p-orange-500, #f59e0b);
}

.mxb-flag {
    margin-left: 2px;
    font-size: 11px;
}

/* Страница задачи — двухколоночная: слева описание+мета (свой скролл),
   справа чат на всю высоту (свой скролл). Тулбар сверху фиксирован. */
.mxb-taskpage {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 120px);
    min-height: 420px;
}

.mxb-task-body {
    flex: 1;
    display: grid;
    grid-template-columns: minmax(340px, 1fr) minmax(0, 2fr);
    gap: 16px;
    min-height: 0;
    overflow: hidden;
}

.mxb-task-left {
    overflow-y: auto;
    padding-right: 8px;
    min-height: 0;
}

/* Мета-карточка: строки «метка → значение», как в референсе. */
.mxb-meta-card {
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: var(--mxb-radius-md);
    background: var(--p-surface-0, #fff);
    box-shadow: var(--mxb-shadow-1);
    padding: 4px 16px;
    margin-bottom: var(--mxb-space-4);
}

.mxb-meta-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 9px 0;
    border-bottom: 1px solid var(--p-content-border-color, #e2e5e9);
    font-size: 13px;
}

.mxb-meta-row:last-child {
    border-bottom: none;
}

.mxb-meta-label {
    flex: 0 0 120px;
    color: var(--mxb-ink-muted);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    font-weight: 600;
}

.mxb-meta-value {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    word-break: break-word;
}

.mxb-meta-assignee {
    color: var(--p-primary-700, #047857);
    font-weight: 600;
}

.mxb-meta-row.mxb-overdue .mxb-meta-deadline strong {
    color: var(--p-red-500, #ef4444);
}

.mxb-meta-deadline-actions,
.mxb-meta-id {
    gap: 6px;
}

.mxb-meta-id code {
    background: var(--p-surface-100, #f1f3f5);
    padding: 2px 8px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 13px;
}

/* Журнал — сворачиваемый <details>. */
.mxb-log-section > summary {
    cursor: pointer;
    list-style: none;
    margin-bottom: 0;
    display: flex;
    align-items: center;
    gap: 6px;
}

.mxb-log-section > summary > .mxb-section-title {
    margin-bottom: 0;
}

.mxb-log-section[open] > summary {
    margin-bottom: 10px;
}

.mxb-log-section > summary::-webkit-details-marker {
    display: none;
}

.mxb-log-section > summary::before {
    content: '\e901';
    font-family: 'primeicons';
    font-size: 12px;
    opacity: 0.6;
    transition: transform 0.15s;
}

.mxb-log-section[open] > summary::before {
    transform: rotate(90deg);
}

/* --- Правая колонка: чат задачи --- */
.mxb-task-chat {
    display: flex;
    flex-direction: column;
    min-height: 0;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: var(--mxb-radius-md);
    background: var(--p-surface-0, #fff);
    box-shadow: var(--mxb-shadow-1);
    overflow: hidden;
}

.mxb-chat-head {
    flex: none;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--p-content-border-color, #e2e5e9);
    font-weight: 600;
    background: var(--p-content-background, #f6f7f9);
}

.mxb-chat-head-title {
    font-size: 15px;
}

/* Лента чата — отдельная тёплая поверхность (мягкий вертикальный тон), чтобы
   «разговор» визуально отличался от рабочей меты, а не был пустым белым div. */
.mxb-chat-scroll {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 3px;
    background:
        linear-gradient(180deg,
            color-mix(in srgb, var(--p-primary-500, #10b981) 5%, var(--p-surface-50, #fafbfc)),
            var(--p-surface-50, #fafbfc) 220px);
}

.mxb-chat-empty {
    margin: auto;
    color: var(--mxb-ink-muted);
}

.mxb-chat-empty .pi {
    color: color-mix(in srgb, var(--p-primary-500, #10b981) 45%, var(--p-surface-300, #cbd5e1));
}

.mxb-chat-msg {
    display: flex;
    gap: 10px;
    align-items: flex-end;
    max-width: 82%;
}

.mxb-chat-msg--grouped {
    margin-top: -1px;
}

/* Отступ между разными авторами/группами. */
.mxb-chat-msg:not(.mxb-chat-msg--grouped) {
    margin-top: 10px;
}

.mxb-chat-msg:first-child {
    margin-top: 0;
}

.mxb-chat-msg--own {
    margin-left: auto;
    flex-direction: row-reverse;
}

.mxb-chat-avatar-slot {
    flex: 0 0 32px;
    width: 32px;
}

.mxb-chat-avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    line-height: 1;
    user-select: none;
}

.mxb-chat-bubble-wrap {
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.mxb-chat-author {
    font-size: 12px;
    font-weight: 600;
    color: var(--p-primary-700, #047857);
    margin: 0 0 3px 12px;
}

/* Чужой пузырь — белая карточка с рамкой: контрастнее на тонированной ленте. */
.mxb-chat-bubble {
    position: relative;
    padding: 8px 12px;
    border-radius: 14px;
    background: var(--p-surface-0, #fff);
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    box-shadow: var(--mxb-shadow-1);
    border-top-left-radius: 4px;
    font-size: 14px;
    line-height: 1.45;
    word-break: break-word;
}

.mxb-chat-msg--own .mxb-chat-bubble {
    background: var(--p-primary-100, #d1fae5);
    color: var(--p-primary-950, #052e16);
    border-color: color-mix(in srgb, var(--p-primary-400, #34d399) 55%, transparent);
    border-top-left-radius: 14px;
    border-top-right-radius: 4px;
}

.mxb-chat-msg--grouped .mxb-chat-bubble {
    border-top-left-radius: 14px;
}

.mxb-chat-msg--grouped.mxb-chat-msg--own .mxb-chat-bubble {
    border-top-right-radius: 14px;
}

/* markdown внутри пузыря — без нижнего margin у последнего абзаца. */
.mxb-chat-bubble .mxb-md p:last-child {
    margin-bottom: 0;
}

.mxb-chat-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 2px;
    font-size: 11px;
    color: var(--mxb-ink-muted);
}

.mxb-chat-time {
    white-space: nowrap;
}

.mxb-chat-actions {
    position: absolute;
    top: -12px;
    right: 6px;
    display: none;
    gap: 2px;
    background: var(--p-surface-0, #fff);
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: 8px;
    padding: 1px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
}

.mxb-chat-msg--own .mxb-chat-actions {
    right: auto;
    left: 6px;
}

.mxb-chat-bubble:hover .mxb-chat-actions,
.mxb-chat-bubble:focus-within .mxb-chat-actions {
    display: flex;
}

/* Композер снизу. */
.mxb-chat-composer {
    flex: none;
    display: flex;
    align-items: flex-end;
    gap: 8px;
    padding: 10px 12px;
    border-top: 1px solid var(--p-content-border-color, #e2e5e9);
    background: var(--p-content-background, #f6f7f9);
}

.mxb-chat-attach {
    flex: none;
}

.mxb-chat-input {
    flex: 1;
    min-height: 40px;
    max-height: 160px;
    padding: 9px 12px;
    border: 1px solid var(--p-inputtext-border-color, #d1d5db);
    border-radius: 20px;
    font: inherit;
    font-family: inherit;
    background: var(--p-inputtext-background, #fff);
    color: inherit;
    resize: none;
    box-sizing: border-box;
}

.mxb-chat-input:focus {
    outline: none;
    border-color: var(--p-primary-color, #10b981);
}

/* Скрытый нативный input[type=file] — клик проксируем с кнопки/label. */
.mxb-file-hidden {
    display: none;
}

/* Обёртка композера: строка файлов над строкой ввода. */
.mxb-chat-composer-wrap {
    flex: none;
    display: flex;
    flex-direction: column;
}

.mxb-chat-composer-wrap .mxb-chat-composer {
    border-top: 1px solid var(--p-content-border-color, #e2e5e9);
}

/* Выбранные, но не отправленные файлы над полем ввода. */
.mxb-composer-files {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 8px 12px 0;
    background: var(--p-content-background, #f6f7f9);
}

.mxb-composer-file {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    max-width: 220px;
    padding: 4px 6px 4px 8px;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: 8px;
    background: var(--p-surface-0, #fff);
    font-size: 12px;
}

.mxb-composer-file-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.mxb-composer-file-size {
    opacity: 0.6;
    white-space: nowrap;
}

.mxb-composer-file-x {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: transparent;
    color: var(--mxb-ink-muted);
    cursor: pointer;
}

.mxb-composer-file-x:hover {
    opacity: 1;
    background: var(--p-surface-100, #f1f3f5);
}

/* Список вложений (в сообщении, блоке задачи). */
.mxb-attachments {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 6px;
}

/* Единый формат вложения — квадратная плитка. */
.mxb-att {
    position: relative;
    width: 104px;
    height: 104px;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    background: var(--p-surface-0, #fff);
}

.mxb-att-body {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    height: 100%;
    padding: 8px;
    box-sizing: border-box;
    text-decoration: none;
    color: inherit;
}

/* Картинка — превью на всю плитку. */
.mxb-att-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.mxb-att--image .mxb-att-body {
    padding: 0;
}

/* Файл — крупная иконка + имя. */
/* Специфичность .mxb-att перебивает PrimeIcons `.pi { font-size: 1rem }`. */
.mxb-att .mxb-att-icon {
    color: var(--p-primary-color, #10b981);
    font-size: 46px;
    line-height: 1;
}

.mxb-att-name {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-align: center;
    font-size: 11px;
    line-height: 1.25;
    word-break: break-word;
    color: var(--p-text-color, #374151);
}

/* Кнопка удаления — в углу плитки, по наведению. */
.mxb-att-remove {
    position: absolute;
    top: 4px;
    right: 4px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    font-size: 11px;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.12s, background 0.12s;
}

.mxb-att:hover .mxb-att-remove {
    opacity: 1;
}

.mxb-att-remove:hover {
    background: var(--p-red-500, #ef4444);
}

/* Поле типа file в форме. */
.mxb-filefield {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.mxb-filefield-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: 8px;
    background: var(--p-surface-0, #fff);
    font-size: 13px;
    cursor: pointer;
}

.mxb-filefield-btn:hover {
    border-color: var(--p-primary-color, #10b981);
}

.mxb-filefield-current {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.mxb-filefield-hint {
    font-size: 12px;
    opacity: 0.6;
}

/* Зона drag-n-drop (FileDrop). */
.mxb-filedrop {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 8px;
    padding: 14px 16px;
    border: 1.5px dashed var(--p-content-border-color, #d1d5db);
    border-radius: 10px;
    background: var(--p-content-background, #f6f7f9);
    color: var(--p-text-muted-color, #6b7280);
    font-size: 13px;
    cursor: pointer;
    transition: border-color 0.12s, background 0.12s, color 0.12s;
}

.mxb-filedrop:hover {
    border-color: var(--p-primary-color, #10b981);
    color: var(--p-primary-color, #10b981);
}

.mxb-filedrop--over {
    border-color: var(--p-primary-color, #10b981);
    background: var(--p-primary-50, #ecfdf5);
    color: var(--p-primary-color, #10b981);
}

.mxb-filedrop--busy {
    cursor: default;
    opacity: 0.7;
}

.mxb-filedrop-icon {
    font-size: 18px;
}

/* Подсветка композера при перетаскивании файла в него. */
.mxb-composer-over {
    outline: 2px dashed var(--p-primary-color, #10b981);
    outline-offset: -2px;
    background: var(--p-primary-50, #ecfdf5);
}

/* Staged-файлы в диалоге создания: не липнут к низу, как в композере. */
.mxb-staged-files {
    padding: 0 0 6px;
    background: transparent;
}

/* Адаптив: узкий экран — колонки стекаются, чат под мета. */
@media (max-width: 1000px) {
    .mxb-taskpage {
        height: auto;
        min-height: 0;
    }

    .mxb-task-body {
        display: block;
        overflow: visible;
    }

    .mxb-task-left {
        overflow: visible;
        padding-right: 0;
    }

    .mxb-task-chat {
        margin-top: 16px;
        height: 70vh;
    }
}

.mxb-task-title {
    margin: 4px 0 8px;
    font-size: 22px;
    line-height: 1.3;
    word-break: break-word;
    font-weight: 700;
}

.mxb-parent-link,
.mxb-parent-note {
    font-size: 13px;
    margin-bottom: 8px;
    opacity: 0.85;
}

.mxb-parent-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    max-width: 100%;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
    color: var(--p-primary-700, #047857);
    font: inherit;
    font-weight: 600;
    text-align: left;
}

.mxb-parent-link span {
    min-width: 0;
    overflow-wrap: anywhere;
}

.mxb-parent-link:hover {
    text-decoration: underline;
}

.mxb-parent-note {
    padding: 8px 10px;
    background: var(--p-surface-100, #f1f3f5);
    border-radius: var(--mxb-radius-sm);
    margin-bottom: 12px;
    color: var(--mxb-ink-muted);
}

.mxb-deadline {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding: 10px 12px;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: 8px;
    margin-bottom: 12px;
    background: var(--p-content-background, #f6f7f9);
}

.mxb-overdue-badge,
.mxb-disputed-badge {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 10px;
    font-weight: 600;
}

.mxb-overdue-badge {
    background: rgba(239, 68, 68, 0.12);
    color: var(--p-red-500, #ef4444);
}

.mxb-disputed-badge {
    background: rgba(245, 158, 11, 0.12);
    color: var(--p-orange-500, #f59e0b);
}

.mxb-inline-form {
    padding: 12px;
    border: 1px dashed var(--p-content-border-color, #e2e5e9);
    border-radius: 8px;
    margin-bottom: 12px;
}

.mxb-field-hint {
    display: block;
    margin-top: 4px;
    font-size: 12px;
    color: var(--p-text-color-secondary, #6c757d);
}

.mxb-resolve-reason {
    padding: 8px 10px;
    border-radius: 6px;
    background: var(--p-content-hover-background, #f4f5f7);
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 13px;
    line-height: 1.5;
}

.mxb-fieldrow {
    display: flex;
    gap: 10px;
    padding: 6px 0;
    border-bottom: 1px solid var(--p-content-border-color, #e2e5e9);
    font-size: 13px;
}

.mxb-fieldrow:last-child {
    border-bottom: none;
}

.mxb-fieldrow-label {
    flex: 0 0 200px;
    font-weight: 600;
    color: var(--p-text-color-secondary, #6c757d);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.mxb-fieldrow-value {
    flex: 1;
    word-break: break-word;
    white-space: pre-wrap;
}

.mxb-fieldrow-link {
    color: var(--p-primary-700, #047857);
    font-weight: 500;
    text-decoration: none;
}

.mxb-fieldrow-link:hover {
    text-decoration: underline;
}

.mxb-subtask {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 11px;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: var(--mxb-radius-sm);
    margin-bottom: 6px;
    cursor: pointer;
    background: var(--p-surface-0, #fff);
    transition: box-shadow 0.16s ease, border-color 0.16s ease;
}

.mxb-subtask:hover {
    box-shadow: var(--mxb-shadow-2);
    border-color: color-mix(in srgb, var(--p-primary-400, #34d399) 45%, var(--p-content-border-color, #e2e5e9));
}

.mxb-subtask-title {
    flex: 1;
    word-break: break-word;
}

.mxb-subtask-assignee {
    font-size: 12px;
    color: var(--mxb-ink-muted);
}

.mxb-subtask-assignee i,
.mxb-fieldrow i {
    margin-right: 3px;
}

.mxb-done {
    color: var(--p-green-500, #10b981);
}

/* Радио-группа «кто может двигать» — 4 варианта вместо CSV-поля. */
.mxb-radio-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 18px;
    padding-top: 2px;
}

.mxb-radio-item {
    display: flex;
    align-items: center;
    gap: 6px;
}

.mxb-radio-item label {
    margin: 0;
    font-weight: 400;
    font-size: 13px;
    text-transform: none;
    letter-spacing: normal;
    color: var(--p-text-color, #1f2733);
    cursor: pointer;
}

/* --- 3c: экран «Структура» --- */
.mxb-check {
    display: flex;
    align-items: center;
    gap: 8px;
}

.mxb-check label {
    margin: 0;
    font-weight: 400;
}

/* Рабочая область «Структуры» скроллится сама: фрейм менеджера MODX страницу не
   прокручивает, и длинный список уходил под нижний край без доступа к прокрутке.
   Тот же приём, что у колонки канбана (.mxb-column) — offset с запасом. */
.mxb-struct-scroll {
    max-height: calc(100vh - 200px);
    overflow-y: auto;
    overscroll-behavior: contain;
}

.mxb-fields-panel {
    margin-top: 12px;
    padding: 12px;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: 8px;
    background: var(--p-content-background, #f6f7f9);
}

/* Строка редактора полей типа: ключ · метка · тип · required · удалить. */
.mxb-field-editrow {
    display: grid;
    grid-template-columns: 1fr 1fr 130px auto auto;
    gap: 8px;
    align-items: center;
    margin-bottom: 8px;
}

.mxb-field-editrow .mxb-check {
    white-space: nowrap;
}

.mxb-fieldrow code {
    background: var(--p-surface-100, #f1f3f5);
    padding: 1px 5px;
    border-radius: 3px;
    font-size: 12px;
    margin-left: 6px;
    opacity: 0.8;
}

/* Вердикт ИИ-проверки полноты постановки (в диалоге создания и на странице задачи). */
.mxb-ai-verdict {
    margin-top: 12px;
    padding: 12px;
    border: 1px solid var(--p-orange-300, #fcd34d);
    border-radius: 8px;
    background: var(--p-orange-50, #fff7ed);
}

.mxb-ai-verdict-head {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--p-orange-700, #b45309);
}

.mxb-ai-verdict-summary {
    margin-bottom: 6px;
}

.mxb-ai-verdict-missing {
    margin: 6px 0 0;
    padding-left: 18px;
}

.mxb-ai-verdict-missing li {
    margin-bottom: 3px;
}

.mxb-chip.mxb-ai-ok {
    background: var(--p-green-100, #dcfce7);
    color: var(--p-green-700, #15803d);
    opacity: 1;
}

.mxb-chip.mxb-ai-bad {
    background: var(--p-red-100, #fee2e2);
    color: var(--p-red-700, #b91c1c);
    opacity: 1;
}

/* Уважаем системную настройку «меньше движения»: гасим лифты/повороты/переходы. */
@media (prefers-reduced-motion: reduce) {
    .mxb *,
    .mxb *::before,
    .mxb *::after {
        transition-duration: 0.01ms !important;
        animation-duration: 0.01ms !important;
    }

    .mxb-card:hover {
        transform: none;
    }
}
</style>
