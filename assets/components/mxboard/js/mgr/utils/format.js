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
 * is_final,stage_key,tasks:[...]}]}. Колонки адресуются КЛЮЧОМ (id у них нет —
 * перетаскивание оперирует column.key, не id).
 */
export function normalizeBoard(res) {
    const root = res?.object ?? res ?? {};
    let columns = root.columns || root.results || res?.results || [];
    if (!Array.isArray(columns)) columns = [];

    const normalized = columns.map((c) => ({
        key: String(c.key ?? ''),
        name: String(c.name ?? c.key ?? ''),
        stage_key: String(c.stage_key ?? ''),
        is_initial: !!Number(c.is_initial),
        is_final: !!Number(c.is_final),
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
        closedon: Number(t.closedon) || 0,
        column_key: String(t.column_key ?? t.column ?? ''),
    };
}
