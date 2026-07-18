<?php

declare(strict_types=1);

namespace MxBoard\Helpers;

/**
 * Формирование человекочитаемого номера задачи (num) по шаблону.
 *
 * Шаблон — из настройки mxboard.task_num_format. Плейсхолдеры:
 *   {Y} — год 4 цифры, {y} — год 2 цифры, {m} — месяц, {d} — день, {num} — счётчик периода.
 * По умолчанию «{y}{m}-{num}» → 2607-1 (год+месяц, счётчик за месяц).
 *
 * Период сброса счётчика определяется по самому мелкому date-плейсхолдеру в шаблоне:
 * есть {d} → посуточно (Ymd), иначе {m} → помесячно (Ym), иначе {y}/{Y} → годовой (Y),
 * иначе сквозной ('all'). Так формат и гранулярность счётчика всегда согласованы.
 */
final class TaskNum
{
    public const DEFAULT_FORMAT = '{y}{m}-{num}';

    /** php-date формат ключа периода (или 'all' — без привязки к дате). */
    public static function periodKey(string $format): string
    {
        if (str_contains($format, '{d}')) {
            return 'Ymd';
        }
        if (str_contains($format, '{m}')) {
            return 'Ym';
        }
        if (str_contains($format, '{y}') || str_contains($format, '{Y}')) {
            return 'Y';
        }

        return 'all';
    }

    /** Строка периода для момента $when (ключ строки в mxboard_counter). */
    public static function period(string $format, int $when): string
    {
        $key = self::periodKey($format);

        return $key === 'all' ? 'all' : date($key, $when);
    }

    /** Собрать num из шаблона: подставить дату момента $when и порядковый $seq. */
    public static function render(string $format, int $when, int $seq): string
    {
        return strtr($format, [
            '{Y}' => date('Y', $when),
            '{y}' => date('y', $when),
            '{m}' => date('m', $when),
            '{d}' => date('d', $when),
            '{num}' => (string) $seq,
        ]);
    }
}
