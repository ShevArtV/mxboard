import { useApi } from '@vuetools/useApi';

const cfg = () => window.MxBoardConfig || {};

// Единый HTTP-слой на useApi (VueTools), а не свой fetch. useApi берёт baseUrl/token
// из window.MODx по умолчанию — у нас кастомный коннектор компонента и токен в
// MxBoardConfig, поэтому передаём их явно. Инициализация ленивая: конфиг кладётся в
// <head> до модуля, но так безопаснее к порядку загрузки.
let _api = null;
function api() {
    if (!_api) {
        const c = cfg();
        _api = useApi({ baseUrl: c.connector_url, authToken: c.token });
    }
    return _api;
}

// action=FQCN процессора, тело — FormData (объекты сериализуются в JSON сами).
// useApi БРОСАЕТ при !res.ok и при success===false (тело в err.data) — по этому
// договору весь фронт работает через try/catch, а не проверку res.success.
const OPTS = { headers: { 'X-Requested-With': 'XMLHttpRequest' } };
const post = (action, params = {}) => api().post(action, params, OPTS);

// useApi кладёт МАССИВ в FormData поэлементно (fields[0]=[object Object]) — для
// массива объектов это ломается. Такие поля (список полей типа, колонки проекта)
// сериализуем в JSON-строку: процессор их всё равно json_decode-ит. Плоский объект
// {key:value} useApi сериализует в JSON сам — его не трогаем.
function withJson(params, keys) {
    const out = { ...params };
    for (const k of keys) {
        if (Array.isArray(out[k])) out[k] = JSON.stringify(out[k]);
    }
    return out;
}

const P = 'MxBoard\\Processors\\Mgr\\';
const B = P + 'Board\\';
const T = P + 'Task\\';
const D = P + 'Department\\';
const PR = P + 'Project\\';
const TY = P + 'Type\\';
const C = P + 'Column\\';
const K = P + 'Token\\';
const N = P + 'Notification\\';

/**
 * Текст ошибки из проваленного вызова useApi. Тело ответа MODX — в err.data:
 * message (общая ошибка) или errors[] (валидация полей). Иначе err.message.
 */
export function errorMessage(err, fallback = '') {
    if (!err) return fallback;
    const body = err.data ?? err;
    if (body.message) return String(body.message);
    if (Array.isArray(body.errors) && body.errors.length) {
        const parts = body.errors.map((e) => e.msg || e.message).filter(Boolean);
        if (parts.length) return parts.join('; ');
    }
    return err.message ? String(err.message) : fallback;
}

/** Список из успешного ответа: object (наш success) или results (гриды MODX). */
export function listOf(res) {
    if (!res) return [];
    if (Array.isArray(res.object)) return res.object;
    if (Array.isArray(res.results)) return res.results;
    return [];
}

// Доска и справочники иерархии — читаются при выборе отдела/проекта.
export const BoardApi = {
    get: (params = {}) => post(B + 'Get', params),
};

export const DepartmentApi = {
    getList: () => post(D + 'GetList'),
    users: (departmentId) => post(D + 'Users', { department_id: departmentId }),
    groups: () => post(D + 'Groups'),
    register: (data) => post(D + 'Register', data),
    update: (id, data) => post(D + 'Update', { id, ...data }),
    remove: (id) => post(D + 'Remove', { id }),
};

export const ProjectApi = {
    getList: () => post(PR + 'GetList'),
    create: (data) => post(PR + 'Create', withJson(data, ['columns'])),
    update: (id, data) => post(PR + 'Update', { id, ...data }),
    remove: (id) => post(PR + 'Remove', { id }),
};

export const TypeApi = {
    getList: (departmentId) => post(TY + 'GetList', { department_id: departmentId }),
    // Схема типа (builtin + поля) под конкретный проект — по ней строится форма задачи.
    schema: (params) => post(TY + 'Schema', params),
    create: (data) => post(TY + 'Create', withJson(data, ['fields'])),
    update: (id, data) => post(TY + 'Update', { id, ...data }),
    remove: (id) => post(TY + 'Remove', { id }),
};

export const FieldApi = {
    getList: (taskTypeId) => post(P + 'Field\\GetList', { task_type_id: taskTypeId }),
    create: (data) => post(P + 'Field\\Create', data),
    update: (id, data) => post(P + 'Field\\Update', { id, ...data }),
    remove: (id) => post(P + 'Field\\Remove', { id }),
};

export const ColumnApi = {
    getList: (projectId) => post(C + 'GetList', { project_id: projectId }),
    create: (data) => post(C + 'Create', data),
    update: (id, data) => post(C + 'Update', { id, ...data }),
    remove: (id) => post(C + 'Remove', { id }),
    // Источники для копирования (шаблон + проекты отдела со своими колонками).
    sources: (projectId) => post(C + 'Sources', { project_id: projectId }),
    // Копировать колонки из источника (sourceId: id проекта или 0 — дефолтный шаблон).
    copy: (projectId, sourceId) => post(C + 'Copy', { project_id: projectId, source_id: sourceId }),
    // Переупорядочить: order — массив id в новом порядке (drag-n-drop).
    reorder: (projectId, order) => post(C + 'Reorder', withJson({ project_id: projectId, order }, ['order'])),
    // Сбросить свои колонки проекта → вернуться к дефолтному шаблону (задачи переносятся по ключу).
    reset: (projectId) => post(C + 'Reset', { project_id: projectId }),
};

// Задача. Модель v2: исполнитель назначается при создании (пула/захвата нет),
// дедлайн оспаривается исполнителем и решается автором, подзадачи через parent_id.
export const TaskApi = {
    get: (id) => post(T + 'Get', { id }),
    create: (data) => post(T + 'Create', data),
    move: (id, column, note = '') => post(T + 'Move', { id, column, note }),
    comment: (id, content, allowEmpty = false) => post(T + 'Comment', { id, content, allow_empty: allowEmpty ? 1 : 0 }),
    updateComment: (taskId, commentId, content) => post(T + 'CommentUpdate', { id: taskId, comment_id: commentId, content }),
    deleteComment: (taskId, commentId) => post(T + 'CommentDelete', { id: taskId, comment_id: commentId }),
    update: (data) => post(T + 'Update', data),
    remove: (id) => post(T + 'Remove', { id }),
    disputeDeadline: (id, proposedDate, reason = '') =>
        post(T + 'DisputeDeadline', { id, proposed_date: proposedDate, reason }),
    resolveDeadline: (id, accept) => post(T + 'ResolveDeadline', { id, accept: accept ? 1 : 0 }),
    disputePlan: (id, proposedHours, reason = '') =>
        post(T + 'DisputePlan', { id, proposed_hours: proposedHours, reason }),
    resolvePlan: (id, accept) => post(T + 'ResolvePlan', { id, accept: accept ? 1 : 0 }),
};

// Вложения. Загрузка файлов через useApi невозможна (он кладёт только JSON/скаляры
// в FormData, а File-объекты не шлёт), поэтому multipart отправляем raw fetch'ем на
// коннектор компонента — ровно как useApi: action и токен в query (?action=…&HTTP_MODAUTH=…),
// тело — FormData с полями file[]. Удаление обычным JSON-вызовом.
export const AttachmentApi = {
    /**
     * Загрузить файлы к задаче (commentId=0) или к сообщению чата (commentId>0).
     * @param {number} taskId
     * @param {number} commentId
     * @param {File[]} files
     */
    async upload(taskId, commentId, files) {
        const c = cfg();
        const url = new URL(c.connector_url, window.location.origin);
        url.searchParams.set('action', T + 'Upload');
        if (c.token) url.searchParams.set('HTTP_MODAUTH', c.token);

        const fd = new FormData();
        fd.append('task_id', String(taskId));
        fd.append('comment_id', String(commentId || 0));
        for (const f of files) fd.append('file[]', f, f.name);

        // Content-Type не ставим руками — браузер сам добавит multipart boundary.
        const res = await fetch(url.toString(), {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        let data = {};
        try { data = await res.json(); } catch { /* пустой/невалидный ответ */ }
        // Тот же договор, что у useApi: бросаем при !ok или success===false, тело — в err.data.
        if (!res.ok || data.success === false) {
            const err = new Error(data.message || `HTTP ${res.status}`);
            err.data = data;
            throw err;
        }
        return data;
    },
    remove: (attachmentId) => post(T + 'AttachmentRemove', { attachment_id: attachmentId }),
};

export const TokenApi = {
    getList: (params = {}) => post(K + 'GetList', params),
    create: (user_id, name) => post(K + 'Create', { user_id, name }),
    remove: (id) => post(K + 'Remove', { id }),
};

// Уведомления: первичная лента (счётчик непрочитанных) и отметка прочитанного.
// Живой поток идёт по SSE (sse.php), см. utils/useNotifications.js.
export const NotificationApi = {
    getList: (limit = 50) => post(N + 'GetList', { limit }),
    markSeen: (ids = []) => post(N + 'MarkSeen', { ids }),
};

export const boardConfig = cfg;
