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

const P = 'MxBoard\\Processors\\Mgr\\';
const B = P + 'Board\\';
const T = P + 'Task\\';
const D = P + 'Department\\';
const PR = P + 'Project\\';
const TY = P + 'Type\\';
const C = P + 'Column\\';
const K = P + 'Token\\';

/**
 * Текст ошибки из проваленного вызова useApi. Тело ответа MODX — в err.data:
 * message (общая ошибка) или errors[] (валидация полей). Иначе err.message.
 */
export function errorMessage(err, fallback = 'Не удалось выполнить операцию') {
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
};

export const ProjectApi = {
    getList: () => post(PR + 'GetList'),
};

export const TypeApi = {
    getList: (departmentId) => post(TY + 'GetList', { department_id: departmentId }),
    // Схема типа (builtin + поля) под конкретный проект — по ней строится форма задачи.
    schema: (params) => post(TY + 'Schema', params),
};

export const ColumnApi = {
    getList: (projectId) => post(C + 'GetList', { project_id: projectId }),
};

// Задача. Модель v2: исполнитель назначается при создании (пула/захвата нет),
// дедлайн оспаривается исполнителем и решается автором, подзадачи через parent_id.
export const TaskApi = {
    get: (id) => post(T + 'Get', { id }),
    create: (data) => post(T + 'Create', data),
    move: (id, column, note = '') => post(T + 'Move', { id, column, note }),
    comment: (id, content) => post(T + 'Comment', { id, content }),
    update: (data) => post(T + 'Update', data),
    remove: (id) => post(T + 'Remove', { id }),
    disputeDeadline: (id, proposedDate, reason = '') =>
        post(T + 'DisputeDeadline', { id, proposed_date: proposedDate, reason }),
    resolveDeadline: (id, accept) => post(T + 'ResolveDeadline', { id, accept: accept ? 1 : 0 }),
};

export const TokenApi = {
    getList: (params = {}) => post(K + 'GetList', params),
    create: (user_id, name) => post(K + 'Create', { user_id, name }),
    remove: (id) => post(K + 'Remove', { id }),
};

export const boardConfig = cfg;
