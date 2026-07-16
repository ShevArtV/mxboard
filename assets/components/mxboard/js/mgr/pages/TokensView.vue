<script setup>
import { ref, onMounted } from 'vue';
import { DataTable, Column, Button, InputText, Dialog, Tag, useToast, useConfirm } from 'primevue';
import { TokenApi, errorMessage, listOf, boardConfig } from '../api/connector.js';
import { fmtDate, userName } from '../utils/format.js';
import { t } from '../utils/i18n.js';

const toast = useToast();
const confirm = useConfirm();
const cfg = boardConfig();

const rows = ref([]);
const loading = ref(false);
const createOpen = ref(false);
const saving = ref(false);
const form = ref({ user_id: Number(cfg.user_id) || 0, name: '' });

// Сырой токен приходит от сервера ровно один раз — в ответе на создание.
// Дальше в БД только sha256-хэш, показать его повторно неоткуда.
const rawToken = ref('');
const copied = ref(false);

async function load() {
    loading.value = true;
    try {
        rows.value = listOf(await TokenApi.getList({ limit: 0 }));
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_tokens_load'), detail: errorMessage(e), life: 8000 });
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    form.value = { user_id: Number(cfg.user_id) || 0, name: '' };
    createOpen.value = true;
}

async function create() {
    if (!form.value.name.trim()) {
        toast.add({ severity: 'warn', summary: t('mxboard_msg_token_name_required'), life: 4000 });
        return;
    }

    saving.value = true;
    let res;
    try {
        res = await TokenApi.create(Number(form.value.user_id) || 0, form.value.name.trim());
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_token_not_created'), detail: errorMessage(e), life: 8000 });
        return;
    } finally {
        saving.value = false;
    }

    const obj = res.object || {};
    const token = obj.token || obj.raw_token || obj.secret || obj.value || '';

    createOpen.value = false;
    copied.value = false;
    rawToken.value = token;

    if (!token) {
        toast.add({
            severity: 'warn',
            summary: t('mxboard_msg_token_created'),
            detail: t('mxboard_msg_token_no_value'),
            life: 8000,
        });
    }

    load();
}

async function copyToken() {
    const text = rawToken.value;
    try {
        await navigator.clipboard.writeText(text);
        copied.value = true;
    } catch (e) {
        // В менеджере по http Clipboard API недоступен — старый способ.
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            copied.value = document.execCommand('copy');
        } catch (err) {
            copied.value = false;
        }
        document.body.removeChild(ta);
    }

    toast.add({
        severity: copied.value ? 'success' : 'warn',
        summary: copied.value ? t('mxboard_msg_token_copied') : t('mxboard_msg_token_copy_manual'),
        life: 4000,
    });
}

function removeToken(event, row) {
    confirm.require({
        target: event.currentTarget,
        message: t('mxboard_msg_confirm_revoke', { name: row.name }),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('mxboard_ui_delete'),
        rejectLabel: t('mxboard_ui_cancel'),
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            try {
                await TokenApi.remove(row.id);
                toast.add({ severity: 'success', summary: t('mxboard_msg_token_revoked'), life: 3000 });
                load();
            } catch (e) {
                toast.add({ severity: 'error', summary: t('mxboard_msg_token_revoke_fail'), detail: errorMessage(e), life: 8000 });
            }
        },
    });
}

onMounted(load);
</script>

<template>
    <div>
        <div class="mxb-toolbar">
            <Button :label="t('mxboard_ui_new_token')" icon="pi pi-plus" size="small" @click="openCreate" />
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

        <div v-if="rawToken" class="mxb-token-raw" style="margin-bottom: 12px">
            <div><strong>{{ t('mxboard_ui_token_created_banner') }}</strong> {{ t('mxboard_ui_token_created_hint') }}</div>
            <div class="mxb-token-value">
                <code>{{ rawToken }}</code>
                <Button :label="t('mxboard_ui_copy')" icon="pi pi-copy" size="small" @click="copyToken" />
                <Button icon="pi pi-times" size="small" severity="secondary" text @click="rawToken = ''" />
            </div>
        </div>

        <DataTable :value="rows" :loading="loading" size="small" striped-rows>
            <Column field="name" :header="t('mxboard_ui_col_name')" />
            <Column :header="t('mxboard_ui_col_user')">
                <template #body="{ data }">{{ userName(data, 'user') || `#${data.user_id}` }}</template>
            </Column>
            <Column :header="t('mxboard_ui_col_status')">
                <template #body="{ data }">
                    <Tag
                        :value="Number(data.active) ? t('mxboard_ui_status_active') : t('mxboard_ui_status_revoked')"
                        :severity="Number(data.active) ? 'success' : 'secondary'"
                    />
                </template>
            </Column>
            <Column :header="t('mxboard_ui_col_created')">
                <template #body="{ data }">{{ fmtDate(data.createdon) }}</template>
            </Column>
            <Column :header="t('mxboard_ui_col_used')">
                <template #body="{ data }">{{ fmtDate(data.lastusedon) || '—' }}</template>
            </Column>
            <Column style="width: 60px">
                <template #body="{ data }">
                    <Button icon="pi pi-trash" size="small" severity="danger" text @click="removeToken($event, data)" />
                </template>
            </Column>
            <template #empty>
                <div class="mxb-empty">{{ t('mxboard_ui_no_tokens') }}</div>
            </template>
        </DataTable>

        <Dialog v-model:visible="createOpen" modal :header="t('mxboard_ui_new_token_agent')" :style="{ width: '520px' }">
            <div class="mxb-field">
                <label for="mxb-token-name">{{ t('mxboard_ui_token_name') }}</label>
                <InputText id="mxb-token-name" v-model="form.name" fluid autofocus :placeholder="t('mxboard_ui_token_name_placeholder')" />
            </div>
            <div class="mxb-field">
                <label for="mxb-token-user">{{ t('mxboard_ui_token_user') }}</label>
                <InputText id="mxb-token-user" v-model="form.user_id" fluid />
                <div class="mxb-hint">{{ t('mxboard_ui_token_user_hint') }}</div>
            </div>
            <template #footer>
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined @click="createOpen = false" />
                    <Button :label="t('mxboard_ui_create')" icon="pi pi-check" :loading="saving" @click="create" />
                </div>
            </template>
        </Dialog>
    </div>
</template>
