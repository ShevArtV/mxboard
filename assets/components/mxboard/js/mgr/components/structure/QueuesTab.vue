<script setup>
import { ref, computed, onMounted } from 'vue';
import {
    DataTable, Column, Button, InputText, Dialog, Select, Checkbox, useToast, useConfirm,
} from 'primevue';
import {
    ProjectApi, QueueApi, errorMessage, listOf,
} from '../../api/connector.js';
import { t } from '../../utils/i18n.js';

// Очереди — сущность проекта: у одного проекта их может быть сколько угодно.
// Управлять ими вправе тот же, кто управляет проектом (проверка на сервере).
const toast = useToast();
const confirm = useConfirm();

const projects = ref([]);
const queues = ref([]);
const loading = ref(false);
const saving = ref(false);

const createOpen = ref(false);
const createForm = ref({ project_id: 0, key: '', name: '', description: '' });
const editOpen = ref(false);
const editForm = ref({ id: 0, key: '', name: '', description: '', active: true });

const projectName = computed(() => (queue) => {
    if (queue.project_name) return queue.project_name;
    const p = projects.value.find((x) => Number(x.id) === Number(queue.project_id));
    return p ? p.name : `#${queue.project_id}`;
});

onMounted(load);

async function load() {
    loading.value = true;
    try {
        const p = await ProjectApi.getList();
        projects.value = listOf(p);
        // Процессор отдаёт очереди одного проекта — собираем по всем проектам,
        // потому что вкладка структуры показывает общий реестр.
        const lists = await Promise.all(projects.value.map((x) => QueueApi.getList(x.id)));
        queues.value = lists.flatMap((res) => listOf(res));
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_refs_load'), detail: errorMessage(e), life: 8000 });
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    createForm.value = {
        project_id: projects.value.length ? Number(projects.value[0].id) : 0,
        key: '', name: '', description: '',
    };
    createOpen.value = true;
}
async function create() {
    if (!createForm.value.project_id || !createForm.value.name.trim()) return;
    saving.value = true;
    try {
        await QueueApi.create({
            project_id: createForm.value.project_id,
            // Ключ необязателен: пустой сервер сгенерирует из названия.
            key: createForm.value.key.trim(),
            name: createForm.value.name.trim(),
            description: createForm.value.description,
        });
        toast.add({ severity: 'success', summary: t('mxboard_ui_struct_created'), life: 3000 });
        createOpen.value = false;
        load();
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    } finally {
        saving.value = false;
    }
}

function openEdit(queue) {
    editForm.value = {
        id: queue.id,
        key: queue.key || '',
        name: queue.name || '',
        description: queue.description || '',
        active: queue.active !== false && queue.active !== 0,
    };
    editOpen.value = true;
}
async function saveEdit() {
    saving.value = true;
    try {
        await QueueApi.update(editForm.value.id, {
            key: editForm.value.key,
            name: editForm.value.name,
            description: editForm.value.description,
            active: editForm.value.active ? 1 : 0,
        });
        toast.add({ severity: 'success', summary: t('mxboard_ui_struct_saved'), life: 3000 });
        editOpen.value = false;
        load();
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    } finally {
        saving.value = false;
    }
}
function removeQueue(event, queue) {
    confirm.require({
        target: event.currentTarget,
        message: t('mxboard_ui_queue_confirm_remove', { name: queue.name }),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('mxboard_ui_delete'),
        rejectLabel: t('mxboard_ui_cancel'),
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            try {
                await QueueApi.remove(queue.id);
                toast.add({ severity: 'success', summary: t('mxboard_ui_struct_removed'), life: 3000 });
                load();
            } catch (e) {
                toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
            }
        },
    });
}
</script>

<template>
    <div>
        <div class="mxb-toolbar">
            <Button :label="t('mxboard_ui_queue_new')" icon="pi pi-plus" size="small" :disabled="!projects.length" @click="openCreate" />
            <Button :label="t('mxboard_ui_refresh')" icon="pi pi-refresh" size="small" severity="secondary" outlined :loading="loading" @click="load" />
        </div>

        <DataTable :value="queues" :loading="loading" size="small" striped-rows>
            <Column field="id" header="id" style="width: 70px" />
            <Column field="name" :header="t('mxboard_ui_struct_name')" />
            <Column field="description" :header="t('mxboard_ui_struct_description')" />
            <Column :header="t('mxboard_ui_queue_project')" style="width: 220px">
                <template #body="{ data }">{{ projectName(data) }}</template>
            </Column>
            <Column style="width: 110px">
                <template #body="{ data }">
                    <Button icon="pi pi-pencil" size="small" severity="secondary" text @click="openEdit(data)" />
                    <Button icon="pi pi-trash" size="small" severity="danger" text @click="removeQueue($event, data)" />
                </template>
            </Column>
            <template #empty><div class="mxb-empty">{{ t('mxboard_ui_struct_empty') }}</div></template>
        </DataTable>

        <!-- Новая очередь -->
        <Dialog v-model:visible="createOpen" modal :header="t('mxboard_ui_queue_new')" :style="{ width: '560px' }">
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_queue_project') }}</label>
                <Select v-model="createForm.project_id" :options="projects" option-label="name" option-value="id" fluid />
            </div>
            <div class="mxb-row">
                <div class="mxb-field mxb-col">
                    <label>{{ t('mxboard_ui_struct_key') }}</label>
                    <InputText v-model="createForm.key" fluid />
                </div>
                <div class="mxb-field mxb-col">
                    <label>{{ t('mxboard_ui_struct_name') }}</label>
                    <InputText v-model="createForm.name" fluid />
                </div>
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_description') }}</label>
                <InputText v-model="createForm.description" fluid />
            </div>
            <template #footer>
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined @click="createOpen = false" />
                    <Button :label="t('mxboard_ui_create')" icon="pi pi-check" :loading="saving" @click="create" />
                </div>
            </template>
        </Dialog>

        <!-- Правка очереди -->
        <Dialog v-model:visible="editOpen" modal :header="t('mxboard_ui_struct_edit')" :style="{ width: '520px' }">
            <div class="mxb-row">
                <div class="mxb-field mxb-col">
                    <label>{{ t('mxboard_ui_struct_key') }}</label>
                    <InputText v-model="editForm.key" fluid />
                </div>
                <div class="mxb-field mxb-col">
                    <label>{{ t('mxboard_ui_struct_name') }}</label>
                    <InputText v-model="editForm.name" fluid />
                </div>
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_description') }}</label>
                <InputText v-model="editForm.description" fluid />
            </div>
            <div class="mxb-field mxb-check">
                <Checkbox v-model="editForm.active" :binary="true" input-id="queue-active" />
                <label for="queue-active">{{ t('mxboard_ui_struct_active') }}</label>
            </div>
            <template #footer>
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined @click="editOpen = false" />
                    <Button :label="t('mxboard_ui_save')" icon="pi pi-check" :loading="saving" @click="saveEdit" />
                </div>
            </template>
        </Dialog>
    </div>
</template>
