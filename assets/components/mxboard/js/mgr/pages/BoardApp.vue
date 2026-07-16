<script setup>
import { ref } from 'vue';
import { Toast, ConfirmPopup, Tabs, TabList, Tab, TabPanels, TabPanel } from 'primevue';
import BoardView from './BoardView.vue';
import TokensView from './TokensView.vue';

const tab = ref('board');
</script>

<template>
    <div class="mxb">
        <Toast position="top-right" />
        <ConfirmPopup />

        <Tabs v-model:value="tab">
            <TabList>
                <Tab value="board"><i class="pi pi-th-large mxb-tab-icon" /> Доска</Tab>
                <Tab value="tokens"><i class="pi pi-key mxb-tab-icon" /> Токены агентов</Tab>
            </TabList>
            <TabPanels>
                <TabPanel value="board">
                    <BoardView />
                </TabPanel>
                <TabPanel value="tokens">
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
    margin-bottom: 12px;
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
    flex: 0 0 280px;
    width: 280px;
    background: var(--p-content-background, #f6f7f9);
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: 8px;
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
    border-bottom: 1px solid var(--p-content-border-color, #e2e5e9);
}

.mxb-column-count {
    margin-left: auto;
    opacity: 0.6;
    font-weight: 400;
}

.mxb-column-body {
    padding: 8px;
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
    border-radius: 6px;
    padding: 10px;
    cursor: pointer;
    transition: box-shadow 0.15s, transform 0.15s;
}

.mxb-card:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
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
    margin-top: 18px;
}

.mxb-section-title {
    font-weight: 600;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.mxb-comment {
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: 6px;
    padding: 8px 10px;
    margin-bottom: 8px;
}

.mxb-comment-head {
    font-size: 12px;
    opacity: 0.7;
    margin-bottom: 4px;
    display: flex;
    gap: 8px;
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
    font-size: 13px;
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

/* Страница задачи */
.mxb-taskpage {
    max-width: 900px;
}

.mxb-task-title {
    margin: 4px 0 4px;
    font-size: 20px;
    line-height: 1.3;
    word-break: break-word;
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
}

.mxb-overdue-badge,
.mxb-disputed-badge {
    font-size: 12px;
    padding: 2px 8px;
    border-radius: 10px;
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
    padding: 5px 0;
    border-bottom: 1px dashed var(--p-content-border-color, #e2e5e9);
    font-size: 13px;
}

.mxb-fieldrow-label {
    flex: 0 0 200px;
    font-weight: 600;
    opacity: 0.8;
}

.mxb-fieldrow-value {
    flex: 1;
    word-break: break-word;
    white-space: pre-wrap;
}

.mxb-subtask {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 10px;
    border: 1px solid var(--p-content-border-color, #e2e5e9);
    border-radius: 6px;
    margin-bottom: 6px;
    cursor: pointer;
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
</style>
