<script setup>
import { computed } from 'vue';
import { fmtSize } from '../utils/format.js';
import { t } from '../utils/i18n.js';

/**
 * Показ списка вложений: картинки (is_image) — превью-плиткой, прочее — чипом
 * с иконкой/именем/размером и ссылкой на скачивание. Удаление — эмитом наверх;
 * кнопку показываем автору файла и менеджеру (сервер всё равно проверит право).
 */
const props = defineProps({
    // Список вложений в форме AttachmentService::toArray (id, name, url, size, ext, is_image, user_id…).
    items: { type: Array, default: () => [] },
    // Текущий пользователь — для показа кнопки удаления «своих» файлов.
    userId: { type: Number, default: 0 },
    // Менеджер/автор задачи — может удалять чужие файлы.
    canManage: { type: Boolean, default: false },
    // Скрыть кнопки удаления полностью (напр. режим только-чтение).
    readonly: { type: Boolean, default: false },
});
const emit = defineEmits(['remove']);

function canRemove(att) {
    if (props.readonly) return false;
    return Number(att.user_id) === props.userId || props.canManage;
}

const list = computed(() => props.items || []);

// Иконка чипа по расширению — грубая группировка, PrimeIcons.
function fileIcon(ext) {
    const e = String(ext || '').toLowerCase();
    if (['pdf'].includes(e)) return 'pi pi-file-pdf';
    if (['doc', 'docx', 'rtf', 'odt', 'txt', 'md'].includes(e)) return 'pi pi-file-word';
    if (['xls', 'xlsx', 'csv', 'ods'].includes(e)) return 'pi pi-file-excel';
    if (['zip', 'rar', '7z', 'tar', 'gz'].includes(e)) return 'pi pi-box';
    if (['mp4', 'mov', 'avi', 'mkv', 'webm'].includes(e)) return 'pi pi-video';
    if (['mp3', 'wav', 'ogg', 'flac'].includes(e)) return 'pi pi-volume-up';
    return 'pi pi-file';
}
</script>

<template>
    <div v-if="list.length" class="mxb-attachments">
        <template v-for="att in list" :key="att.id">
            <!-- Картинка: превью-плитка -->
            <div v-if="att.is_image" class="mxb-att mxb-att--image">
                <a :href="att.url" target="_blank" rel="noopener" class="mxb-att-thumb">
                    <img :src="att.url" :alt="att.name" loading="lazy" />
                </a>
                <button
                    v-if="canRemove(att)"
                    type="button"
                    class="mxb-att-remove"
                    :title="t('mxboard_ui_delete')"
                    @click="emit('remove', att, $event)"
                ><i class="pi pi-times" /></button>
            </div>

            <!-- Прочее: чип -->
            <div v-else class="mxb-att mxb-att--file">
                <i class="mxb-att-icon" :class="fileIcon(att.ext)" />
                <a :href="att.url" target="_blank" rel="noopener" class="mxb-att-name" :title="att.name">{{ att.name }}</a>
                <span class="mxb-att-size">{{ fmtSize(att.size) }}</span>
                <a :href="att.url" target="_blank" rel="noopener" download class="mxb-att-dl" :title="t('mxboard_ui_download')"><i class="pi pi-download" /></a>
                <button
                    v-if="canRemove(att)"
                    type="button"
                    class="mxb-att-remove mxb-att-remove--inline"
                    :title="t('mxboard_ui_delete')"
                    @click="emit('remove', att, $event)"
                ><i class="pi pi-times" /></button>
            </div>
        </template>
    </div>
</template>
