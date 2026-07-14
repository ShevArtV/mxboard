/**
 * Мини-рендер markdown для ToR и комментариев.
 *
 * Внешних либ (marked/markdown-it) сознательно нет: пакет не должен тащить
 * зависимости ради подсветки заголовков. Поддержаны заголовки, списки, цитаты,
 * hr, fenced-код, inline code/bold/italic/ссылки — этого хватает для ToR.
 * Весь текст экранируется ДО разметки, поэтому HTML из ToR не исполняется.
 */

function esc(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function inline(raw) {
    let t = esc(raw);
    t = t.replace(/`([^`]+)`/g, '<code>$1</code>');
    t = t.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    t = t.replace(/(^|[\s(])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    t = t.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (m, label, url) => {
        // Только безопасные схемы: javascript:/data: в ToR ходить не должны.
        const safe = /^(https?:\/\/|\/|#)/i.test(url) ? url : '#';
        return `<a href="${safe}" target="_blank" rel="noopener noreferrer">${label}</a>`;
    });
    return t;
}

function renderBlocks(text) {
    const lines = text.split('\n');
    let html = '';
    let list = null; // 'ul' | 'ol'
    let para = [];

    const flushPara = () => {
        if (para.length) {
            html += `<p>${para.map(inline).join('<br>')}</p>`;
            para = [];
        }
    };
    const flushList = () => {
        if (list) {
            html += `</${list}>`;
            list = null;
        }
    };

    for (const line of lines) {
        const l = line.trimEnd();

        if (!l.trim()) {
            flushPara();
            flushList();
            continue;
        }

        const heading = l.match(/^(#{1,6})\s+(.*)$/);
        if (heading) {
            flushPara();
            flushList();
            const level = heading[1].length;
            html += `<h${level}>${inline(heading[2])}</h${level}>`;
            continue;
        }

        if (/^(-{3,}|\*{3,}|_{3,})$/.test(l.trim())) {
            flushPara();
            flushList();
            html += '<hr>';
            continue;
        }

        const quote = l.match(/^>\s?(.*)$/);
        if (quote) {
            flushPara();
            flushList();
            html += `<blockquote>${inline(quote[1])}</blockquote>`;
            continue;
        }

        const ul = l.match(/^\s*[-*+]\s+(.*)$/);
        if (ul) {
            flushPara();
            if (list !== 'ul') {
                flushList();
                html += '<ul>';
                list = 'ul';
            }
            html += `<li>${inline(ul[1])}</li>`;
            continue;
        }

        const ol = l.match(/^\s*\d+[.)]\s+(.*)$/);
        if (ol) {
            flushPara();
            if (list !== 'ol') {
                flushList();
                html += '<ol>';
                list = 'ol';
            }
            html += `<li>${inline(ol[1])}</li>`;
            continue;
        }

        flushList();
        para.push(l);
    }

    flushPara();
    flushList();

    return html;
}

export function renderMarkdown(src) {
    if (!src) return '';
    const text = String(src).replace(/\r\n?/g, '\n');

    // Сначала выделяем fenced-код: внутри него инлайн-разметка не работает.
    const parts = text.split('```');
    let html = '';
    parts.forEach((part, i) => {
        if (i % 2 === 1) {
            const body = part.replace(/^[^\n]*\n?/, ''); // первая строка — язык
            html += `<pre class="mxb-code"><code>${esc(body.replace(/\n$/, ''))}</code></pre>`;
        } else {
            html += renderBlocks(part);
        }
    });

    return html;
}
