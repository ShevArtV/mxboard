// Копирование в буфер обмена. `navigator.clipboard` живёт только в secure context
// (https или localhost), а менеджер MODX у пользователя пакета вполне может открываться
// по http — там API просто отсутствует, и вызов без запасного пути молча уходит в catch.
// Поэтому сначала штатный API, затем скрытая textarea + execCommand, и только если не
// сработало ни то ни другое — false, чтобы вызывающий показал ошибку, а не «скопировано».

function fallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    // fixed + прозрачность: не даём странице дёрнуться скроллом на время выделения.
    ta.style.position = 'fixed';
    ta.style.top = '0';
    ta.style.left = '0';
    ta.style.opacity = '0';
    ta.setAttribute('readonly', 'readonly');
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try {
        ok = document.execCommand('copy');
    } catch {
        ok = false;
    }
    document.body.removeChild(ta);
    return ok;
}

/**
 * @param {string} text
 * @returns {Promise<boolean>} удалось ли скопировать
 */
export async function copyText(text) {
    const value = String(text ?? '');
    if (!value) return false;

    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(value);
            return true;
        } catch {
            // Отказ разрешения или не-secure context — пробуем запасной путь.
        }
    }

    return fallbackCopy(value);
}
