const cfg = () => window.MxBoardConfig || {};

async function request(action, params = {}) {
    const config = cfg();
    const form = new FormData();
    form.append('action', action);
    if (config.token) {
        form.append('HTTP_MODAUTH', config.token);
    }
    for (const [k, v] of Object.entries(params)) {
        if (v !== null && v !== undefined) {
            form.append(k, typeof v === 'object' ? JSON.stringify(v) : String(v));
        }
    }
    try {
        const res = await fetch(config.connector_url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: form,
        });
        if (!res.ok) {
            return { success: false, message: `HTTP ${res.status}` };
        }
        return await res.json();
    } catch (e) {
        return { success: false, message: e.message || 'Ошибка сети' };
    }
}

const B = 'MxBoard\\Processors\\Mgr\\Board\\';
const T = 'MxBoard\\Processors\\Mgr\\Task\\';
const K = 'MxBoard\\Processors\\Mgr\\Token\\';

/**
 * Текст ошибки из ответа процессора. MODX кладёт его либо в message, либо в
 * errors[] (валидация полей) — берём то, что есть, иначе общий fallback.
 */
export function errorMessage(res, fallback = 'Не удалось выполнить операцию') {
    if (!res) return fallback;
    if (res.message) return String(res.message);
    if (Array.isArray(res.errors) && res.errors.length) {
        const parts = res.errors.map((e) => e.msg || e.message).filter(Boolean);
        if (parts.length) return parts.join('; ');
    }
    return fallback;
}

export const BoardApi = {
    get: (board) => request(B + 'Get', board ? { board } : {}),
};

export const TaskApi = {
    get: (id) => request(T + 'Get', { id }),
    create: (data) => request(T + 'Create', data),
    move: (id, column, note = '') => request(T + 'Move', { id, column, note }),
    take: (id) => request(T + 'Take', { id }),
    release: (id) => request(T + 'Release', { id }),
    comment: (id, content) => request(T + 'Comment', { id, content }),
    update: (data) => request(T + 'Update', data),
    remove: (id) => request(T + 'Remove', { id }),
};

export const TokenApi = {
    getList: (params = {}) => request(K + 'GetList', params),
    create: (user_id, name) => request(K + 'Create', { user_id, name }),
    remove: (id) => request(K + 'Remove', { id }),
};

export const boardConfig = cfg;
