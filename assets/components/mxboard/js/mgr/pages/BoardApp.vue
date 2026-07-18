<script setup>
import { ref } from 'vue';
import { Toast, ConfirmPopup, Tabs, TabList, Tab, TabPanels, TabPanel } from 'primevue';
import BoardView from './BoardView.vue';
import TokensView from './TokensView.vue';
import StructureView from './StructureView.vue';
import { t } from '../utils/i18n.js';

// Гейты вкладок (UI лишь прячет; финальную проверку делают процессоры/плагин):
// «Структура» — менеджеру (sudo/супер отдела); «Токены агентов» — только sudo.
const cfg = window.MxBoardConfig || {};
const isManager = !!cfg.is_manager;
const isSudo = !!cfg.is_sudo;
const tab = ref('board');
</script>

<template>
    <div class="mxb">
        <Toast position="top-right" />
        <ConfirmPopup />

        <Tabs v-model:value="tab">
            <TabList>
                <Tab value="board"><i class="pi pi-th-large mxb-tab-icon" /> {{ t('mxboard_ui_board') }}</Tab>
                <Tab v-if="isManager" value="structure"><i class="pi pi-sitemap mxb-tab-icon" /> {{ t('mxboard_ui_structure') }}</Tab>
                <Tab v-if="isSudo" value="tokens"><i class="pi pi-key mxb-tab-icon" /> {{ t('mxboard_ui_tokens') }}</Tab>
            </TabList>
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

.mxb-tab-icon {
    margin-right: 6px;
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
    flex: 0 0 290px;
    width: 290px;
    background: var(--p-content-background, #f6f7f9);
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: 10px;
    display: flex;
    flex-direction: column;
    min-height: 160px;
}

.mxb-column--over {
    border-color: var(--p-primary-color, #10b981);
    box-shadow: 0 0 0 2px var(--p-primary-color, #10b981) inset;
}

.mxb-column-head {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    font-weight: 600;
    border-top: 3px solid var(--col-color, #6c757d);
    border-bottom: 1px solid var(--p-content-border-color, #e2e5e9);
}

.mxb-column-count {
    margin-left: auto;
    opacity: 0.6;
    font-weight: 400;
}

.mxb-column-body {
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex: 1;
    min-height: 80px;
}

.mxb-empty {
    padding: 16px 8px;
    text-align: center;
    opacity: 0.5;
    font-size: 13px;
}

.mxb-card {
    background: var(--p-surface-0, #fff);
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: 8px;
    padding: 12px;
    cursor: pointer;
    transition: box-shadow 0.15s, transform 0.15s;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

.mxb-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
}

.mxb-card--dragging {
    opacity: 0.45;
}

.mxb-card-title {
    font-weight: 600;
    line-height: 1.35;
    margin-bottom: 8px;
    word-break: break-word;
}

.mxb-card-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 12px;
    opacity: 0.85;
}

.mxb-card-meta i {
    margin-right: 3px;
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

.mxb-section {
    margin-top: 20px;
    padding: 14px;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: 8px;
    background: var(--p-content-background, #f6f7f9);
}

.mxb-section-title {
    font-weight: 600;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
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
    opacity: 0.6;
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
    opacity: 0.65;
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
    padding: 1px 8px;
    border-radius: 10px;
    background: var(--p-surface-100, #f1f3f5);
    font-size: 11px;
    opacity: 0.85;
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
    grid-template-columns: minmax(340px, 460px) minmax(0, 1fr);
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
    border-radius: 10px;
    background: var(--p-content-background, #f6f7f9);
    padding: 6px 14px;
    margin-bottom: 16px;
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
    color: var(--p-text-color-secondary, #6c757d);
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
    color: var(--p-primary-color, #10b981);
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
    border-radius: 12px;
    background: var(--p-surface-0, #fff);
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

.mxb-chat-scroll {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 3px;
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
    color: var(--p-primary-color, #10b981);
    margin: 0 0 3px 12px;
}

.mxb-chat-bubble {
    position: relative;
    padding: 8px 12px;
    border-radius: 14px;
    background: var(--p-surface-100, #f1f3f5);
    border-top-left-radius: 4px;
    font-size: 14px;
    line-height: 1.45;
    word-break: break-word;
}

.mxb-chat-msg--own .mxb-chat-bubble {
    background: var(--p-primary-100, #d1fae5);
    color: var(--p-primary-950, #052e16);
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
    opacity: 0.6;
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

.mxb-chat-bubble:hover .mxb-chat-actions {
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
    width: 18px;
    height: 18px;
    padding: 0;
    border: none;
    border-radius: 50%;
    background: transparent;
    color: inherit;
    cursor: pointer;
    opacity: 0.6;
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

.mxb-att {
    position: relative;
}

/* Картинка-превью. */
.mxb-att--image .mxb-att-thumb {
    display: block;
    width: 120px;
    height: 120px;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
}

.mxb-att--image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

/* Чип файла. */
.mxb-att--file {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    max-width: 280px;
    padding: 6px 10px;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: 10px;
    background: var(--p-surface-0, #fff);
    font-size: 13px;
}

.mxb-att-icon {
    color: var(--p-primary-color, #10b981);
    font-size: 16px;
}

.mxb-att-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: inherit;
    text-decoration: none;
}

.mxb-att-name:hover {
    text-decoration: underline;
}

.mxb-att-size {
    opacity: 0.6;
    white-space: nowrap;
    margin-left: auto;
}

.mxb-att-dl {
    color: var(--p-text-muted-color, #6b7280);
    text-decoration: none;
}

.mxb-att-dl:hover {
    color: var(--p-primary-color, #10b981);
}

/* Кнопка удаления вложения. */
.mxb-att-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: none;
    cursor: pointer;
    color: #fff;
}

.mxb-att--image .mxb-att-remove {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.55);
    font-size: 11px;
}

.mxb-att--image .mxb-att-remove:hover {
    background: rgba(0, 0, 0, 0.8);
}

.mxb-att-remove--inline {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: transparent;
    color: var(--p-text-muted-color, #6b7280);
}

.mxb-att-remove--inline:hover {
    background: var(--p-red-50, #fef2f2);
    color: var(--p-red-500, #ef4444);
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
    cursor: pointer;
    color: var(--p-primary-color, #10b981);
}

.mxb-parent-link:hover {
    text-decoration: underline;
}

.mxb-parent-note {
    padding: 8px 10px;
    background: var(--p-surface-100, #f1f3f5);
    border-radius: 6px;
    margin-bottom: 12px;
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
    color: var(--p-primary-color, #10b981);
    text-decoration: none;
}

.mxb-fieldrow-link:hover {
    text-decoration: underline;
}

.mxb-subtask {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: 8px;
    margin-bottom: 6px;
    cursor: pointer;
    background: var(--p-surface-0, #fff);
    transition: box-shadow 0.15s;
}

.mxb-subtask:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.mxb-subtask-title {
    flex: 1;
    word-break: break-word;
}

.mxb-subtask-assignee {
    font-size: 12px;
    opacity: 0.75;
}

.mxb-subtask-assignee i,
.mxb-fieldrow i {
    margin-right: 3px;
}

.mxb-done {
    color: var(--p-green-500, #10b981);
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
</style>
