<script setup>
import { ref, watch } from 'vue';
import { Dialog, Button, InputText, Select, useToast } from 'primevue';
import { TaskApi, errorMessage, boardConfig } from '../api/connector.js';
import { PRIORITIES } from '../utils/format.js';

const props = defineProps({
    visible: { type: Boolean, default: false },
});
const emit = defineEmits(['update:visible', 'created']);

const toast = useToast();
const cfg = boardConfig();

const title = ref('');
const tor = ref('');
const priority = ref(1);
const saving = ref(false);

watch(() => props.visible, (open) => {
    if (open) {
        title.value = '';
        tor.value = '';
        priority.value = 1;
    }
});

async function save() {
    if (!title.value.trim()) {
        toast.add({ severity: 'warn', summary: 'Не указан заголовок', life: 4000 });
        return;
    }

    saving.value = true;
    const res = await TaskApi.create({
        board: cfg.board,
        title: title.value.trim(),
        tor: tor.value,
        priority: priority.value,
    });
    saving.value = false;

    if (!res || res.success === false) {
        toast.add({ severity: 'error', summary: 'Задача не создана', detail: errorMessage(res), life: 8000 });
        return;
    }

    toast.add({ severity: 'success', summary: 'Задача создана', life: 3000 });
    emit('update:visible', false);
    emit('created');
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Новая задача"
        :style="{ width: '640px' }"
        @update:visible="emit('update:visible', $event)"
    >
        <div class="mxb-field">
            <label for="mxb-new-title">Заголовок</label>
            <InputText id="mxb-new-title" v-model="title" fluid autofocus />
        </div>

        <div class="mxb-field">
            <label for="mxb-new-tor">Постановка (ToR, markdown)</label>
            <textarea id="mxb-new-tor" v-model="tor" class="mxb-textarea" rows="10" />
        </div>

        <div class="mxb-field">
            <label for="mxb-new-priority">Приоритет</label>
            <Select
                id="mxb-new-priority"
                v-model="priority"
                :options="PRIORITIES"
                option-label="label"
                option-value="value"
                fluid
            />
        </div>

        <template #footer>
            <div class="mxb-dialog-actions">
                <Button label="Отмена" severity="secondary" outlined @click="emit('update:visible', false)" />
                <Button label="Создать" icon="pi pi-check" :loading="saving" @click="save" />
            </div>
        </template>
    </Dialog>
</template>
