/** Приоритет — unsigned int в БД; в UI даём фиксированную шкалу. */
export const PRIORITIES = [
    { value: 0, label: 'Низкий', severity: 'secondary' },
    { value: 1, label: 'Обычный', severity: 'info' },
    { value: 2, label: 'Высокий', severity: 'warn' },
    { value: 3, label: 'Критический', severity: 'danger' },
];

export function priorityMeta(value) {
    const v = Number(value) || 0;
    return PRIORITIES.find((p) => p.value === v) || { value: v, label: `P${v}`, severity: 'contrast' };
}

/** Дата-время в БД — unix timestamp; процессор мог отдать и строку. */
export function fmtDate(value) {
    const d = toDate(value);
    if (!d) return '';
    const p = (n) => String(n).padStart(2, '0');
    return `${p(d.getDate())}.${p(d.getMonth() + 1)}.${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** Только дата (для дедлайна — время в нём не значимо). */
export function fmtDay(value) {
    const d = toDate(value);
    if (!d) return '';
    const p = (n) => String(n).padStart(2, '0');
    return `${p(d.getDate())}.${p(d.getMonth() + 1)}.${d.getFullYear()}`;
}

/** Только время HH:MM (для чата задачи). */
export function fmtTime(value) {
    const d = toDate(value);
    if (!d) return '';
    const p = (n) => String(n).padStart(2, '0');
    return `${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** Байты → человекочитаемый размер (Б/КБ/МБ/ГБ). */
export function fmtSize(bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return `${n} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let val = n / 1024;
    let i = 0;
    while (val >= 1024 && i < units.length - 1) {
        val /= 1024;
        i += 1;
    }
    return `${val < 10 ? val.toFixed(1) : Math.round(val)} ${units[i]}`;
}

/** unix-секунды → 'YYYY-MM-DD' для <input type="date">. */
export function toDateInput(value) {
    const d = toDate(value);
    if (!d) return '';
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

/** Дедлайн просрочен (и задача не закрыта) — для подсветки. */
export function isOverdue(task) {
    const dl = Number(task?.deadlineon) || 0;
    const closed = Number(task?.closedon) || 0;
    return dl > 0 && !closed && dl * 1000 < Date.now();
}

/** Начало календарного дня — для счёта дедлайнов «в днях», без учёта времени. */
function dayStart(d) {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
}

/**
 * Относительный дедлайн: «сегодня» / «завтра» / «через 3 дня» / «2 дня назад».
 * Локализация — через браузерный Intl.RelativeTimeFormat (не хардкод строк);
 * язык берём из <html lang>, при сбое — падаем на абсолютную дату.
 */
export function fmtRelativeDay(value) {
    const d = toDate(value);
    if (!d) return '';
    const diffDays = Math.round((dayStart(d) - dayStart(new Date())) / 86400000);
    try {
        const loc = (typeof document !== 'undefined' && document.documentElement.lang) || 'ru';
        return new Intl.RelativeTimeFormat(loc, { numeric: 'auto' }).format(diffDays, 'day');
    } catch {
        return fmtDay(value);
    }
}

/**
 * Тон дедлайн-пилюли: 'overdue' (просрочен) / 'soon' (≤2 дней) / 'normal' / 'none'.
 * Закрытая задача — всегда 'normal' (не пугаем красным то, что уже сделано).
 */
export function deadlineTone(task) {
    const dl = Number(task?.deadlineon) || 0;
    if (!dl) return 'none';
    if (Number(task?.closedon) || 0) return 'normal';
    if (isOverdue(task)) return 'overdue';
    const days = Math.round((dayStart(new Date(dl * 1000)) - dayStart(new Date())) / 86400000);
    return days <= 2 ? 'soon' : 'normal';
}

/**
 * Фактическое время в часах: от входа в стартовую стадию (startedon) до закрытия,
 * а у незакрытой — до «сейчас». 0 = замера нет (стартовая стадия не помечена либо
 * карточку вернули в бэклог и отсчёт сброшен).
 */
export function factHours(task) {
    const started = Number(task?.startedon) || 0;
    if (!started) return 0;
    const closed = Number(task?.closedon) || 0;
    const until = closed > 0 ? closed : Math.floor(Date.now() / 1000);
    return Math.max(0, Math.round((until - started) / 3600));
}

/** Идёт ли замер прямо сейчас — карточка в работе и не закрыта. */
export function factRunning(task) {
    return (Number(task?.startedon) || 0) > 0 && !(Number(task?.closedon) || 0);
}

/** Инициалы из имени (аватар). Общий помощник для чата и карточек. */
export function initials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return (parts[0][0] + (parts[1] ? parts[1][0] : '')).toUpperCase();
}

/** Стабильный оттенок аватара из id пользователя — собеседники визуально различаются. */
export function avatarStyle(userId) {
    const hue = ((Number(userId) || 0) * 47) % 360;
    return { background: `hsl(${hue}, 52%, 45%)` };
}

function toDate(value) {
    if (!value) return null;
    const num = Number(value);
    const d = Number.isFinite(num) && num > 0
        ? new Date(num * 1000)
        : new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(d.getTime()) ? null : d;
}

/**
 * Имя пользователя из строки. Процессор может отдать его по-разному
 * (author, author_username, Author.username) — берём первое доступное.
 */
export function userName(row, prefix) {
    if (!row) return '';
    const direct = row[prefix] ?? row[`${prefix}_username`] ?? row[`${prefix}_name`] ?? row[`${prefix}_fullname`];
    if (direct && typeof direct === 'string') return direct;
    if (direct && typeof direct === 'object') return direct.username || direct.fullname || '';
    const nested = row[prefix.charAt(0).toUpperCase() + prefix.slice(1)];
    if (nested && typeof nested === 'object') return nested.username || nested.fullname || '';
    const id = Number(row[`${prefix}_id`]) || 0;
    return id ? `#${id}` : '';
}

/**
 * Доска приходит от BoardQuery::board: {project, columns:[{key,name,is_initial,
 * is_final,tasks:[...]}]}. Колонки адресуются КЛЮЧОМ (id у них нет —
 * перетаскивание оперирует column.key, не id).
 */
export function normalizeBoard(res) {
    const root = res?.object ?? res ?? {};
    let columns = root.columns || root.results || res?.results || [];
    if (!Array.isArray(columns)) columns = [];

    const normalized = columns.map((c) => ({
        key: String(c.key ?? ''),
        name: String(c.name ?? c.key ?? ''),
        description: String(c.description ?? ''),
        color: String(c.color ?? '') || '#6c757d',
        is_initial: !!Number(c.is_initial),
        is_final: !!Number(c.is_final),
        is_start: !!Number(c.is_start),
        tasks: (Array.isArray(c.tasks) ? c.tasks : []).map(normalizeTask),
    }));

    return {
        project: root.project || null,
        columns: normalized,
    };
}

export function normalizeTask(t) {
    return {
        ...t,
        id: Number(t.id) || 0,
        priority: Number(t.priority) || 0,
        author_id: Number(t.author_id) || 0,
        assignee_id: Number(t.assignee_id) || 0,
        parent_id: Number(t.parent_id) || 0,
        deadlineon: Number(t.deadlineon) || 0,
        deadline_disputed: !!Number(t.deadline_disputed),
        deadline_proposed: Number(t.deadline_proposed) || 0,
        plan_hours: Number(t.plan_hours) || 0,
        plan_disputed: !!Number(t.plan_disputed),
        plan_proposed: Number(t.plan_proposed) || 0,
        startedon: Number(t.startedon) || 0,
        closedon: Number(t.closedon) || 0,
        // 0 — карточка вне очередей. По этому полю доска решает, спрашивать ли
        // подтверждение при перетаскивании в стартовую стадию.
        queue_id: Number(t.queue_id) || 0,
        queue_position: Number(t.queue_position) || 0,
        column_key: String(t.column_key ?? t.column ?? ''),
    };
}
