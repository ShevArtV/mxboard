/**
 * Виджет «Токен агента mxBoard» на странице профиля пользователя (менеджер).
 *
 * Vanilla JS — страница профиля это ExtJS, не наш Vue-бандл. Конфиг и лексикон
 * прокидывает плагин mxBoardProfileToken (window.MxBoardProfileToken, MODx.lang).
 * Строки НЕ хардкодятся — берутся через t() из MODx.lang.
 */
(function () {
    'use strict';

    var cfg = window.MxBoardProfileToken;
    if (!cfg) return;

    function t(key) {
        return (window.MODx && window.MODx.lang && window.MODx.lang[key]) || key;
    }

    // Запрос к коннектору компонента (action = FQCN процессора). Тот же контракт,
    // что и у Vue-части, но без useApi — здесь чужая ExtJS-страница.
    async function request(action, params) {
        var form = new FormData();
        form.append('action', action);
        if (cfg.token) form.append('HTTP_MODAUTH', cfg.token);
        Object.keys(params || {}).forEach(function (k) {
            if (params[k] !== null && params[k] !== undefined) form.append(k, params[k]);
        });
        var res = await fetch(cfg.connector_url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: form,
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        var json = await res.json();
        if (json.success === false) throw new Error(json.message || 'error');
        return json;
    }

    var P = 'MxBoard\\Processors\\Mgr\\Token\\';

    function el(tag, attrs, text) {
        var e = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); });
        if (text != null) e.textContent = text;
        return e;
    }

    function build() {
        var box = el('div', { id: 'mxb-profile-token', style: 'margin:14px 0;padding:14px 16px;border:1px solid #e2e5e9;border-radius:8px;background:#fbfcfd' });

        var title = el('div', { style: 'font-weight:600;margin-bottom:6px;font-size:14px' }, t('mxboard_ui_profile_section'));
        var hint = el('div', { style: 'font-size:12px;opacity:.7;margin-bottom:10px' }, t('mxboard_ui_profile_hint'));
        box.appendChild(title);
        box.appendChild(hint);

        var body = el('div', { id: 'mxb-profile-token-body' });
        box.appendChild(body);
        return { box: box, body: body };
    }

    function copyText(text) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).catch(function () { fallbackCopy(text); });
        } else {
            fallbackCopy(text);
        }
        toast(t('mxboard_ui_profile_copied'));
    }
    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) { /* noop */ }
        document.body.removeChild(ta);
    }
    function toast(msg) {
        if (window.MODx && MODx.msg && MODx.msg.status) {
            MODx.msg.status({ message: msg, delay: 2000 });
        }
    }

    function renderBody(body, data) {
        body.innerHTML = '';

        // На создании пользователя id ещё нет — токен выдавать некому.
        if (!cfg.user_id) {
            body.appendChild(el('div', { style: 'font-size:13px;opacity:.75' }, t('mxboard_ui_profile_save_first')));
            return;
        }

        var hasToken = data && data.token;

        if (hasToken) {
            var row = el('div', { style: 'display:flex;gap:8px;align-items:center;margin-bottom:8px' });
            var input = el('input', {
                type: 'text', readonly: 'readonly', value: data.token,
                style: 'flex:1;font-family:monospace;font-size:13px;padding:7px 9px;border:1px solid #d1d5db;border-radius:6px;background:#f1f3f5',
            });
            var copyBtn = el('button', { type: 'button', style: btnStyle('secondary') }, t('mxboard_ui_copy'));
            copyBtn.addEventListener('click', function () { copyText(data.token); });
            row.appendChild(input);
            row.appendChild(copyBtn);
            body.appendChild(row);
            if (data.createdon_formatted) {
                body.appendChild(el('div', { style: 'font-size:12px;opacity:.6;margin-bottom:8px' }, t('mxboard_ui_profile_created') + ': ' + data.createdon_formatted));
            }
        } else {
            body.appendChild(el('div', { style: 'font-size:13px;opacity:.75;margin-bottom:8px' }, t('mxboard_ui_profile_none')));
        }

        var issueBtn = el('button', { type: 'button', style: btnStyle('primary') },
            hasToken ? t('mxboard_ui_profile_regenerate') : t('mxboard_ui_profile_generate'));
        issueBtn.addEventListener('click', function () {
            if (hasToken && !window.confirm(t('mxboard_ui_profile_confirm_regen'))) return;
            issueBtn.disabled = true;
            request(P + 'IssueForUser', { user_id: cfg.user_id }).then(function (r) {
                renderBody(body, r.object || {});
            }).catch(function (e) {
                toast(e.message || 'error');
                issueBtn.disabled = false;
            });
        });
        body.appendChild(issueBtn);
    }

    function btnStyle(kind) {
        var base = 'padding:7px 14px;border-radius:6px;font-size:13px;cursor:pointer;border:1px solid ';
        return kind === 'primary'
            ? base + '#10b981;background:#10b981;color:#fff'
            : base + '#d1d5db;background:#fff;color:#333';
    }

    function mount() {
        if (document.getElementById('mxb-profile-token')) return true;
        var target = document.getElementById('modx-content') || document.body;
        if (!target) return false;

        var w = build();
        target.insertBefore(w.box, target.firstChild);

        if (cfg.user_id) {
            request(P + 'GetForUser', { user_id: cfg.user_id })
                .then(function (r) { renderBody(w.body, r.object || {}); })
                .catch(function () { renderBody(w.body, {}); });
        } else {
            renderBody(w.body, {});
        }
        return true;
    }

    // ExtJS рендерит контент асинхронно — ждём появления контейнера.
    var tries = 0;
    var timer = setInterval(function () {
        tries += 1;
        if (mount() || tries > 40) clearInterval(timer);
    }, 250);
})();
