<script setup>
import { computed } from 'vue';
import { t } from '../utils/i18n.js';

/**
 * Показ списка вложений единым форматом: квадратная плитка — превью для картинок,
 * иконка + имя для прочего; полное имя по наведению. Удаление — эмитом наверх;
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
        <!-- Единый формат: квадратная плитка. Картинка — превью; прочее — иконка + имя.
             При наведении — полное имя (title + всплывающая подпись). -->
        <div
            v-for="att in list"
            :key="att.id"
            class="mxb-att"
            :class="att.is_image ? 'mxb-att--image' : 'mxb-att--file'"
            :title="att.name"
        >
            <a :href="att.url" target="_blank" rel="noopener" :download="att.is_image ? null : att.name" class="mxb-att-body">
                <img v-if="att.is_image" :src="att.url" :alt="att.name" loading="lazy" class="mxb-att-img" />
                <template v-else>
                    <i class="mxb-att-icon" :class="fileIcon(att.ext)" />
                    <span class="mxb-att-name">{{ att.name }}</span>
                </template>
            </a>
            <button
                v-if="canRemove(att)"
                type="button"
                class="mxb-att-remove"
                :title="t('mxboard_ui_delete')"
                @click.prevent="emit('remove', att, $event)"
            ><i class="pi pi-times" /></button>
        </div>
    </div>
</template>
