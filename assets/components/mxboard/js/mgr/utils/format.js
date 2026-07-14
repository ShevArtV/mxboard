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

/** Даты в БД — unix timestamp; процессор мог отдать и строку. */
export function fmtDate(value) {
    if (!value) return '';
    const num = Number(value);
    const d = Number.isFinite(num) && num > 0
        ? new Date(num * 1000)
        : new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return String(value);
    const p = (n) => String(n).padStart(2, '0');
    return `${p(d.getDate())}.${p(d.getMonth() + 1)}.${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/**
 * Имя пользователя из карточки. Процессор может отдать его по-разному
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

export function commentsCount(task) {
    const v = task.comments_count ?? task.comment_count ?? task.comments ?? 0;
    return Array.isArray(v) ? v.length : (Number(v) || 0);
}

/**
 * Доска приходит от процессора Board\Get. Форма ответа зависит от него, поэтому
 * приводим к единому виду: колонки с вложенным массивом задач.
 */
export function normalizeBoard(res) {
    const root = res?.object ?? res ?? {};
    let columns = root.columns || root.results || res?.results || [];
    if (!Array.isArray(columns)) columns = [];

    const flatTasks = Array.isArray(root.tasks) ? root.tasks : [];

    const normalized = columns.map((c) => {
        const tasks = Array.isArray(c.tasks)
            ? c.tasks.slice()
            : flatTasks.filter((t) => (
                String(t.column_id ?? '') === String(c.id ?? '')
                || String(t.column ?? t.column_key ?? '') === String(c.key ?? '')
            ));
        return {
            id: Number(c.id) || 0,
            key: String(c.key ?? ''),
            name: String(c.name ?? c.key ?? ''),
            rank: Number(c.rank) || 0,
            is_initial: !!Number(c.is_initial),
            is_ready: !!Number(c.is_ready),
            is_final: !!Number(c.is_final),
            tasks: tasks.map(normalizeTask),
        };
    });

    normalized.sort((a, b) => a.rank - b.rank);

    return {
        board: root.board || root.object || null,
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
        column_id: Number(t.column_id) || 0,
    };
}
