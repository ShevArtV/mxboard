import { reactive } from 'vue';

/**
 * Лёгкая реактивная шина «данные изменились» между вкладками структуры и канбаном.
 * Pinia в проекте нет; каждая вкладка грузит справочники локально в onMounted, а
 * PrimeVue Tabs не перемонтирует панели — поэтому изменения в одной вкладке не
 * доходили до других без перезагрузки страницы.
 *
 * Инкремент ревизии = сигнал заинтересованным компонентам перечитать данные
 * (через watch на соответствующее поле).
 */
export const revisions = reactive({ projects: 0, columns: 0 });

export const liveEvents = reactive({ seq: 0, last: null });

/** Проекты изменились (создан/переименован/удалён) — обновить списки проектов. */
export function bumpProjects() {
    revisions.projects += 1;
}

/** Колонки проекта изменились (состав/порядок/цвет/копирование) — перечитать доску. */
export function bumpColumns() {
    revisions.columns += 1;
}

/** Событие из SSE-журнала задач: доска/карточка сами решают, нужно ли перечитываться. */
export function pushLiveEvent(event) {
    liveEvents.last = event || null;
    liveEvents.seq += 1;
}
