<script setup>
import { ref } from 'vue';
import { useToast } from 'primevue';
import { capFiles } from '../utils/upload.js';
import { t } from '../utils/i18n.js';

/**
 * Переиспользуемая зона загрузки: drag-n-drop + кнопка выбора, батч-мультивыбор с
 * капом числа файлов «за раз» (из системной настройки через window.MxBoardConfig).
 * Отдаёт наверх уже обрезанный список File[] событием `files`; лишние — тост.
 */
const props = defineProps({
    // Сколько файлов уже выбрано у вызывающего (для накопления в композере) — чтобы кап
    // считал общий предел, а не только текущий батч.
    already: { type: Number, default: 0 },
    // Блокировать во время загрузки.
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['files']);

const toast = useToast();
const input = ref(null);
const over = ref(false);

function accept(fileList) {
    const { files, dropped, max } = capFiles(fileList, props.already);
    if (dropped > 0) {
        toast.add({ severity: 'warn', summary: t('mxboard_ui_too_many_files', { max }), life: 5000 });
    }
    if (files.length) emit('files', files);
}

function onDrop(event) {
    over.value = false;
    if (props.busy) return;
    const dt = event.dataTransfer;
    if (dt && dt.files && dt.files.length) accept(dt.files);
}

function onPick(event) {
    accept(event.target.files);
    event.target.value = '';
}
</script>

<template>
    <div
        class="mxb-filedrop"
        :class="{ 'mxb-filedrop--over': over, 'mxb-filedrop--busy': busy }"
        @dragover.prevent="over = true"
        @dragenter.prevent="over = true"
        @dragleave.prevent="over = false"
        @drop.prevent="onDrop"
        @click="!busy && input?.click()"
    >
        <i :class="busy ? 'pi pi-spin pi-spinner' : 'pi pi-cloud-upload'" class="mxb-filedrop-icon" />
        <span class="mxb-filedrop-text">{{ t('mxboard_ui_drop_hint') }}</span>
        <input ref="input" type="file" multiple class="mxb-file-hidden" :disabled="busy" @change="onPick" />
    </div>
</template>
