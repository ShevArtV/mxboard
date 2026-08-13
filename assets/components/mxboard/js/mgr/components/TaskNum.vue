<script setup>
import { ref, onBeforeUnmount } from 'vue';
import { useToast } from 'primevue';
import { copyText } from '../utils/clipboard.js';
import { t } from '../utils/i18n.js';

// Единый элемент «номер задачи»: везде, где номер виден пользователю, клик по нему
// копирует ровно номер — без «#», заголовка и прочего окружения. Внешний вид задаёт
// место вызова обычными классами: они прилетают через $attrs на корневую кнопку.
//
// Кнопка, а не span: копирование — действие, ему нужны фокус, Enter/Space и роль,
// понятная скринридеру. draggable="false" обязателен — номер стоит внутри карточки
// канбана и строки очереди, которые сами draggable, и без этого перетаскивание
// начиналось бы с номера. Enter/Space гасим на всплытии: контейнеры вокруг (карточка,
// строка очереди) вешают на эти клавиши открытие задачи, и без stop нажатие на
// сфокусированном номере копировало бы и открывало карточку разом. Клик, наоборот,
// НЕ глушим — на нём позиционируются оверлеи PrimeVue; там отсев по `.mxb-num`
// в обработчике контейнера.

const props = defineProps({
    num: { type: [String, Number], default: '' },
});

const toast = useToast();
const copied = ref(false);
let timer = 0;

onBeforeUnmount(() => clearTimeout(timer));

async function onCopy() {
    const value = String(props.num ?? '');
    if (!value) return;

    if (await copyText(value)) {
        copied.value = true;
        clearTimeout(timer);
        timer = setTimeout(() => { copied.value = false; }, 1000);
        toast.add({ severity: 'success', summary: t('mxboard_msg_num_copied'), detail: value, life: 2000 });
    } else {
        toast.add({ severity: 'warn', summary: t('mxboard_msg_copy_failed'), detail: value, life: 4000 });
    }
}
</script>

<template>
    <button
        type="button"
        class="mxb-num"
        :class="{ 'mxb-num--copied': copied }"
        draggable="false"
        :title="t('mxboard_ui_copy_num')"
        :aria-label="`${t('mxboard_ui_copy_num')}: ${num}`"
        @click="onCopy"
        @keydown.enter.stop
        @keydown.space.stop
    >{{ num }}</button>
</template>
