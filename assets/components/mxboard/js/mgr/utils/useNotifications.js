import { reactive, onMounted, onUnmounted } from 'vue';
import { useToast } from 'primevue';
import { NotificationApi, boardConfig } from '../api/connector.js';
import { t } from './i18n.js';
import { pushLiveEvent } from './bus.js';

/**
 * Живые in-app уведомления доски.
 *
 * Первичная лента приходит REST-вызовом (счётчик непрочитанных на момент открытия),
 * дальше — поток по SSE (assets/components/mxboard/sse.php) через нативный EventSource:
 * он сам переподключается и передаёт Last-Event-ID, поэтому докачка пропущенного —
 * бесплатно, руками реконнект не пишем. Сервер закрывает соединение раз в ~25с
 * (лимиты shared-хостинга) — для EventSource это штатный повод переподключиться.
 *
 * Строку тоста собираем из payload (num/title/from/to/preview) — без дозагрузки задачи.
 */
export function useNotifications() {
    const toast = useToast();
    const state = reactive({ items: [], unseen: 0, connected: false });

    let es = null;
    let lastId = 0;

    function typeLabel(n) {
        const key = 'mxboard_notify_' + n.type;
        const label = t(key);
        return label === key ? n.type : label;
    }

    function summary(n) {
        const p = n.payload || {};
        const num = p.num ? '#' + p.num : ('#' + n.task_id);
        return num + ' ' + (p.title || '');
    }

    function detail(n) {
        const p = n.payload || {};
        if (n.type === 'move') return (p.from || '?') + ' → ' + (p.to || '?');
        if (n.type === 'comment') return (n.actor ? n.actor + ': ' : '') + (p.preview || '');
        return n.actor || '';
    }

    function pushToast(n) {
        toast.add({
            severity: n.type === 'deadline_dispute' ? 'warn' : 'info',
            summary: typeLabel(n) + ' · ' + summary(n),
            detail: detail(n),
            life: 6000,
        });
    }

    async function seed() {
        try {
            const res = await NotificationApi.getList(50);
            const data = res.object || {};
            state.items = data.items || [];
            state.unseen = data.unseen || 0;
            // Курсор = максимальный id уже показанных, чтобы SSE не дублировал их.
            lastId = state.items.reduce((m, it) => Math.max(m, it.id || 0), 0);
        } catch (e) {
            /* лента не критична — молча продолжаем к стриму */
        }
    }

    function connect() {
        const cfg = boardConfig();
        if (!cfg.assets_url) return;

        const url = cfg.assets_url + 'sse.php' + (lastId > 0 ? '?lastId=' + lastId : '');
        try {
            es = new EventSource(url, { withCredentials: true });
        } catch (e) {
            return;
        }

        es.addEventListener('open', () => { state.connected = true; });
        es.addEventListener('error', () => { state.connected = false; });

        es.addEventListener('notification', (ev) => {
            let n;
            try { n = JSON.parse(ev.data); } catch (e) { return; }
            if (!n || !n.id) return;
            // Защита от повторной доставки после реконнекта.
            if (state.items.some((it) => it.id === n.id)) return;
            lastId = Math.max(lastId, n.id);
            state.items.unshift(n);
            if (!n.seen) state.unseen += 1;
            pushToast(n);
        });

        es.addEventListener('board-event', (ev) => {
            let event;
            try { event = JSON.parse(ev.data); } catch (e) { return; }
            if (!event || !event.id || !event.task_id) return;
            pushLiveEvent(event);
        });
    }

    async function markAllSeen() {
        if (state.unseen === 0) return;
        try {
            await NotificationApi.markSeen([]);
            state.items.forEach((it) => { it.seen = true; });
            state.unseen = 0;
        } catch (e) {
            /* не критично */
        }
    }

    onMounted(async () => {
        await seed();
        connect();
    });

    onUnmounted(() => {
        if (es) { es.close(); es = null; }
    });

    return { state, markAllSeen, summary, detail, typeLabel };
}
