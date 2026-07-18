import { boardConfig } from '../api/connector.js';

/** Лимиты вложений из window.MxBoardConfig.upload (кап числа файлов + макс. размер). */
export function uploadLimits() {
    const u = (boardConfig() || {}).upload || {};
    return {
        maxFiles: Number(u.max_files) || 0, // 0 — без лимита
        maxSize: Number(u.max_size) || 0,
    };
}

/**
 * Обрезать батч до лимита числа файлов «за раз». Возвращает разрешённый список и
 * число отсечённых — вызывающий сам решит, показать ли тост. Серверный кап это не
 * заменяет (AttachmentService тоже режет), а лишь дружелюбно предупреждает до отправки.
 *
 * @param {File[]} files
 * @param {number} [already] сколько файлов уже выбрано (для композера с накоплением)
 * @returns {{ files: File[], dropped: number, max: number }}
 */
export function capFiles(files, already = 0) {
    const { maxFiles } = uploadLimits();
    const list = Array.from(files || []);
    if (maxFiles <= 0) return { files: list, dropped: 0, max: 0 };
    const room = Math.max(0, maxFiles - already);
    if (list.length <= room) return { files: list, dropped: 0, max: maxFiles };
    return { files: list.slice(0, room), dropped: list.length - room, max: maxFiles };
}
