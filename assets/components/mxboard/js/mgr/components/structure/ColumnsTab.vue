<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import {
    DataTable, Column, Button, InputText, Textarea, Dialog, Select, Checkbox, RadioButton, Tag, Message, useToast, useConfirm,
} from 'primevue';
import {
    ProjectApi, ColumnApi, errorMessage, listOf,
} from '../../api/connector.js';
import { revisions, bumpColumns } from '../../utils/bus.js';
import { t } from '../../utils/i18n.js';

const toast = useToast();
const confirm = useConfirm();

const projects = ref([]);
const projectId = ref(null);
const columns = ref([]);
const loading = ref(false);
const saving = ref(false);

const createOpen = ref(false);
const createForm = ref({ key: '', name: '', description: '', move_mode: 'both', color: '#6c757d' });
const editOpen = ref(false);
const editForm = ref({ id: 0, name: '', description: '', move_mode: 'both', color: '#6c757d', position: 0, is_initial: false, is_final: false });

const copyOpen = ref(false);
const copySources = ref([]);
const copySourceId = ref(null);

// Кто может двигать карточку В колонку — понятные варианты вместо CSV-синтаксиса.
// На бэке остаётся move_roles (CSV): радио лишь собирает/парсит его.
const MOVE_OPTIONS = computed(() => [
    { value: 'both', label: t('mxboard_ui_struct_move_both') },
    { value: 'author', label: t('mxboard_ui_struct_move_author') },
    { value: 'assignee', label: t('mxboard_ui_struct_move_assignee') },
]);
function modeToRoles(mode) {
    return { author: 'author', assignee: 'assignee', both: 'author,assignee' }[mode] || 'author,assignee';
}
function rolesToMode(roles) {
    const parts = String(roles || '').split(',').map((x) => x.trim()).filter(Boolean);
    const a = parts.includes('author');
    const s = parts.includes('assignee');
    if (a && s) return 'both';
    if (a) return 'author';
    if (s) return 'assignee';
    return 'both';
}
function moveLabel(roles) {
    const map = { author: 'mxboard_ui_struct_move_author', assignee: 'mxboard_ui_struct_move_assignee', both: 'mxboard_ui_struct_move_both' };
    return t(map[rolesToMode(roles)]);
}

// Опции селектора: «шаблон новых проектов» (project_id=0) + реальные проекты.
const projectOptions = computed(() => [
    { id: 0, name: t('mxboard_ui_struct_template') },
    ...projects.value.map((p) => ({ id: Number(p.id), name: p.name })),
]);

// Fallback: выбран реальный проект (>0), но показанные колонки принадлежат шаблону
// (project_id=0) — своих колонок у проекта нет. Такие колонки только для чтения.
const isFallback = computed(() => Number(projectId.value) > 0
    && columns.value.length > 0
    && Number(columns.value[0].project_id) === 0);

// Редактировать (создавать/править/удалять/двигать) можно только СВОИ колонки:
// шаблон (project_id=0) правит суперюзер, свои колонки проекта — менеджер.
const canEdit = computed(() => !isFallback.value);

// Копировать колонки предлагаем только для реального проекта (у шаблона источника нет).
const canCopy = computed(() => Number(projectId.value) > 0);

onMounted(async () => {
    try {
        projects.value = listOf(await ProjectApi.getList());
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_refs_load'), detail: errorMessage(e), life: 8000 });
    }
    projectId.value = 0;
});

watch(projectId, load);

// Реактивная синхронизация: проект создан/удалён на соседней вкладке — обновляем
// список проектов, чтобы новый сразу был доступен в селекторе (без перезагрузки).
async function reloadProjects() {
    try {
        projects.value = listOf(await ProjectApi.getList());
    } catch (e) { /* тихо: список освежится при следующем действии */ }
}
watch(() => revisions.projects, reloadProjects);

async function load() {
    if (projectId.value === null) { columns.value = []; return; }
    loading.value = true;
    try {
        columns.value = listOf(await ColumnApi.getList(projectId.value));
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_refs_load'), detail: errorMessage(e), life: 8000 });
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    createForm.value = { key: '', name: '', description: '', move_mode: 'both', color: '#6c757d' };
    createOpen.value = true;
}
async function create() {
    if (!createForm.value.key.trim() || !createForm.value.name.trim()) return;
    saving.value = true;
    try {
        await ColumnApi.create({
            project_id: projectId.value,
            key: createForm.value.key.trim(),
            name: createForm.value.name.trim(),
            description: createForm.value.description,
            move_roles: modeToRoles(createForm.value.move_mode),
            color: createForm.value.color,
        });
        toast.add({ severity: 'success', summary: t('mxboard_ui_struct_created'), life: 3000 });
        createOpen.value = false;
        load();
        bumpColumns();
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    } finally {
        saving.value = false;
    }
}

function openEdit(col) {
    editForm.value = {
        id: col.id, name: col.name || '', move_mode: rolesToMode(col.move_roles),
        description: col.description || '',
        color: col.color || '#6c757d',
        position: Number(col.position) || 0,
        is_initial: col.is_initial === true || col.is_initial === 1,
        is_final: col.is_final === true || col.is_final === 1,
    };
    editOpen.value = true;
}
async function saveEdit() {
    saving.value = true;
    try {
        // is_initial/is_final = 1 переносит флаг; снять в 0 напрямую нельзя (сервер игнорит falsy).
        const data = {
            name: editForm.value.name,
            description: editForm.value.description,
            move_roles: modeToRoles(editForm.value.move_mode),
            color: editForm.value.color,
            position: editForm.value.position,
        };
        if (editForm.value.is_initial) data.is_initial = 1;
        if (editForm.value.is_final) data.is_final = 1;
        await ColumnApi.update(editForm.value.id, data);
        toast.add({ severity: 'success', summary: t('mxboard_ui_struct_saved'), life: 3000 });
        editOpen.value = false;
        load();
        bumpColumns();
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    } finally {
        saving.value = false;
    }
}
function removeColumn(event, col) {
    confirm.require({
        target: event.currentTarget,
        message: t('mxboard_ui_struct_confirm_remove_column', { name: col.name }),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('mxboard_ui_delete'),
        rejectLabel: t('mxboard_ui_cancel'),
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            try {
                await ColumnApi.remove(col.id);
                toast.add({ severity: 'success', summary: t('mxboard_ui_struct_removed'), life: 3000 });
                load();
                bumpColumns();
            } catch (e) {
                toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
            }
        },
    });
}

// Сброс колонок проекта к дефолтным: удаляем свои колонки, задачи переносятся на
// шаблон по ключу (несовпавшие — в стартовую стадию). Всё делает сервис в транзакции.
function resetColumns(event) {
    confirm.require({
        target: event.currentTarget,
        message: t('mxboard_ui_struct_reset_confirm'),
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: t('mxboard_ui_struct_reset'),
        rejectLabel: t('mxboard_ui_cancel'),
        acceptProps: { severity: 'danger', size: 'small' },
        rejectProps: { severity: 'secondary', outlined: true, size: 'small' },
        accept: async () => {
            try {
                await ColumnApi.reset(projectId.value);
                toast.add({ severity: 'success', summary: t('mxboard_ui_struct_removed'), life: 3000 });
                load();
                bumpColumns();
            } catch (e) {
                toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
            }
        },
    });
}

// Drag-n-drop переупорядочивание: оптимистично применяем и шлём новый порядок id.
async function onRowReorder(e) {
    const prev = columns.value;
    columns.value = e.value;
    try {
        await ColumnApi.reorder(projectId.value, e.value.map((c) => c.id));
        toast.add({ severity: 'success', summary: t('mxboard_ui_struct_saved'), life: 2000 });
        bumpColumns();
    } catch (err) {
        columns.value = prev;
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(err), life: 8000 });
        load();
    }
}

// Копирование: если источник один — копируем сразу, иначе показываем диалог выбора.
async function openCopy() {
    try {
        const res = await ColumnApi.sources(projectId.value);
        const list = (res && res.object && Array.isArray(res.object.sources)) ? res.object.sources : [];
        if (list.length === 0) {
            toast.add({ severity: 'warn', summary: t('mxboard_ui_struct_copy_no_sources'), life: 5000 });
            return;
        }
        if (list.length === 1) {
            await doCopy(list[0].id);
            return;
        }
        copySources.value = list;
        copySourceId.value = list[0].id;
        copyOpen.value = true;
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    }
}
async function doCopy(sourceId) {
    saving.value = true;
    try {
        await ColumnApi.copy(projectId.value, sourceId);
        toast.add({ severity: 'success', summary: t('mxboard_ui_struct_saved'), life: 3000 });
        copyOpen.value = false;
        load();
        bumpColumns();
    } catch (e) {
        toast.add({ severity: 'error', summary: t('mxboard_msg_rejected'), detail: errorMessage(e), life: 8000 });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div>
        <div class="mxb-toolbar">
            <Select v-model="projectId" :options="projectOptions" option-label="name" option-value="id" :placeholder="t('mxboard_ui_struct_pick_project')" />
            <Button v-if="canEdit" :label="t('mxboard_ui_struct_new_column')" icon="pi pi-plus" size="small" :disabled="projectId === null" @click="openCreate" />
            <Button v-if="canCopy" :label="t('mxboard_ui_struct_copy_columns')" icon="pi pi-copy" size="small" severity="secondary" @click="openCopy" />
            <Button v-if="canEdit && Number(projectId) > 0" :label="t('mxboard_ui_struct_reset')" icon="pi pi-undo" size="small" severity="danger" outlined @click="resetColumns" />
            <Button :label="t('mxboard_ui_refresh')" icon="pi pi-refresh" size="small" severity="secondary" outlined :loading="loading" @click="load" />
        </div>

        <!-- Фича 3: у проекта нет своих колонок — показываем плашку без списка дефолтных
             (на канбане дефолты остаются как fallback, но в редакторе стадий их не правят). -->
        <Message v-if="isFallback" severity="info" :closable="false" style="margin-bottom: 8px">{{ t('mxboard_ui_struct_no_own') }}</Message>
        <div v-else class="mxb-hint" style="margin-bottom: 8px">{{ t('mxboard_ui_struct_flag_transfer') }}<template v-if="canEdit"> · {{ t('mxboard_ui_struct_reorder_hint') }}</template></div>

        <DataTable v-if="!isFallback" :value="columns" :loading="loading" size="small" striped-rows @row-reorder="onRowReorder">
            <Column v-if="canEdit" row-reorder style="width: 40px" />
            <Column field="position" :header="t('mxboard_ui_struct_position')" style="width: 80px" />
            <Column field="key" :header="t('mxboard_ui_struct_key')" style="width: 150px" />
            <Column field="name" :header="t('mxboard_ui_struct_name')" />
            <Column field="description" :header="t('mxboard_ui_struct_description')">
                <template #body="{ data }">
                    <span v-if="data.description" class="mxb-muted">{{ data.description }}</span>
                    <span v-else class="mxb-muted">—</span>
                </template>
            </Column>
            <Column :header="t('mxboard_ui_struct_move_roles')" style="width: 180px">
                <template #body="{ data }">{{ moveLabel(data.move_roles) }}</template>
            </Column>
            <Column field="color" :header="t('mxboard_ui_struct_color')" style="width: 80px">
                <template #body="{ data }">
                    <span :style="{ display: 'inline-block', width: '24px', height: '24px', borderRadius: '4px', backgroundColor: data.color || '#6c757d', verticalAlign: 'middle' }" />
                </template>
            </Column>
            <Column style="width: 130px">
                <template #body="{ data }">
                    <Tag v-if="data.is_initial" :value="t('mxboard_ui_struct_is_initial')" severity="info" />
                    <Tag v-if="data.is_final" :value="t('mxboard_ui_struct_is_final')" severity="success" />
                </template>
            </Column>
            <Column v-if="canEdit" style="width: 110px">
                <template #body="{ data }">
                    <Button icon="pi pi-pencil" size="small" severity="secondary" text @click="openEdit(data)" />
                    <Button icon="pi pi-trash" size="small" severity="danger" text @click="removeColumn($event, data)" />
                </template>
            </Column>
            <template #empty><div class="mxb-empty">{{ t('mxboard_ui_struct_empty') }}</div></template>
        </DataTable>

        <!-- Новая колонка -->
        <Dialog v-model:visible="createOpen" modal :header="t('mxboard_ui_struct_new_column')" :style="{ width: '560px' }">
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
                <Textarea v-model="createForm.description" rows="3" auto-resize fluid />
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_move_roles') }}</label>
                <div class="mxb-radio-group">
                    <div v-for="opt in MOVE_OPTIONS" :key="opt.value" class="mxb-radio-item">
                        <RadioButton v-model="createForm.move_mode" :input-id="'cmove-' + opt.value" :value="opt.value" />
                        <label :for="'cmove-' + opt.value">{{ opt.label }}</label>
                    </div>
                </div>
                <div class="mxb-hint">{{ t('mxboard_ui_struct_move_roles_hint') }}</div>
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_color') }}</label>
                <div style="display: flex; align-items: center; gap: 8px">
                    <input type="color" v-model="createForm.color" style="width: 40px; height: 32px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer" />
                    <InputText v-model="createForm.color" fluid style="flex: 1" />
                </div>
            </div>
            <template #footer>
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined @click="createOpen = false" />
                    <Button :label="t('mxboard_ui_create')" icon="pi pi-check" :loading="saving" @click="create" />
                </div>
            </template>
        </Dialog>

        <!-- Правка колонки -->
        <Dialog v-model:visible="editOpen" modal :header="t('mxboard_ui_struct_edit')" :style="{ width: '560px' }">
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_name') }}</label>
                <InputText v-model="editForm.name" fluid />
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_description') }}</label>
                <Textarea v-model="editForm.description" rows="3" auto-resize fluid />
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_move_roles') }}</label>
                <div class="mxb-radio-group">
                    <div v-for="opt in MOVE_OPTIONS" :key="opt.value" class="mxb-radio-item">
                        <RadioButton v-model="editForm.move_mode" :input-id="'emove-' + opt.value" :value="opt.value" />
                        <label :for="'emove-' + opt.value">{{ opt.label }}</label>
                    </div>
                </div>
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_position') }}</label>
                <InputText v-model="editForm.position" fluid />
            </div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_color') }}</label>
                <div style="display: flex; align-items: center; gap: 8px">
                    <input type="color" v-model="editForm.color" style="width: 40px; height: 32px; border: 1px solid #ccc; border-radius: 4px; cursor: pointer" />
                    <InputText v-model="editForm.color" fluid style="flex: 1" />
                </div>
            </div>
            <div class="mxb-field mxb-check">
                <Checkbox v-model="editForm.is_initial" :binary="true" input-id="col-initial" />
                <label for="col-initial">{{ t('mxboard_ui_struct_is_initial') }}</label>
            </div>
            <div class="mxb-field mxb-check">
                <Checkbox v-model="editForm.is_final" :binary="true" input-id="col-final" />
                <label for="col-final">{{ t('mxboard_ui_struct_is_final') }}</label>
            </div>
            <template #footer>
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined @click="editOpen = false" />
                    <Button :label="t('mxboard_ui_save')" icon="pi pi-check" :loading="saving" @click="saveEdit" />
                </div>
            </template>
        </Dialog>

        <!-- Копирование колонок из источника -->
        <Dialog v-model:visible="copyOpen" modal :header="t('mxboard_ui_struct_copy_title')" :style="{ width: '480px' }">
            <div class="mxb-hint" style="margin-bottom: 8px">{{ t('mxboard_ui_struct_copy_hint') }}</div>
            <div class="mxb-field">
                <label>{{ t('mxboard_ui_struct_copy_source') }}</label>
                <Select v-model="copySourceId" :options="copySources" option-label="name" option-value="id" fluid />
            </div>
            <template #footer>
                <div class="mxb-dialog-actions">
                    <Button :label="t('mxboard_ui_cancel')" severity="secondary" outlined @click="copyOpen = false" />
                    <Button :label="t('mxboard_ui_struct_copy_columns')" icon="pi pi-copy" :loading="saving" @click="doCopy(copySourceId)" />
                </div>
            </template>
        </Dialog>
    </div>
</template>
