<script setup>
// Строка проекта в выпадающем списке: название и рядом приглушённый ключ.
//
// Ключ виден не для красоты: список ищет и по нему, и без показанного ключа ввод
// «kb-graph» оставлял бы на экране строку «Граф БЗ» — совпадение есть, а причины не
// видно. Ключом же проект зовут в номерах карточек и в путях репозиториев.
//
// У псевдо-опций (например «Шаблон» в редакторе стадий) ключа нет — тогда остаётся
// одно название.

defineProps({
    option: { type: Object, required: true },
});
</script>

<template>
    <span class="mxb-project-opt">
        <span class="mxb-project-opt-name">{{ option.name }}</span>
        <span v-if="option.key" class="mxb-project-opt-key">{{ option.key }}</span>
    </span>
</template>
