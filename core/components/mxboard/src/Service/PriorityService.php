<?php

declare(strict_types=1);

namespace MxBoard\Service;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Helpers\Transitions;
use MxBoard\Model\MxBoardPriority;

/**
 * Глобальный справочник приоритетов — единая точка истины для REST/MCP/валидации/фронта.
 *
 * Справочник проектно НЕзависимый (инвариант 1): один на всю систему, без привязки к
 * проекту или отделу. Инварианты 2–4 (нельзя удалить последний; уникальность value и
 * name) проверяются здесь И на уровне БД (уникальные индексы в mysql-схеме).
 *
 * Право на правку справочника — «менеджер»: sudo ИЛИ супер хотя бы одного отдела. Это
 * совпадает с гейтом вкладки «Структура» (cfg.is_manager): справочник глобальный, а не
 * привязанный к конкретному отделу, поэтому отдельного отдела для проверки прав нет.
 */
class PriorityService
{
    public function __construct(private modX $modx)
    {
    }

    /**
     * Весь справочник, отсортированный по числовому значению. Плоские массивы —
     * пригодны и для JSON фронта, и для enum MCP.
     *
     * @return list<array{id: int, name: string, color: string, value: int}>
     */
    public function all(): array
    {
        $c = $this->modx->newQuery(MxBoardPriority::class);
        $c->sortby('value', 'ASC');

        $out = [];
        /** @var MxBoardPriority $p */
        foreach ($this->modx->getCollection(MxBoardPriority::class, $c) as $p) {
            $out[] = [
                'id' => (int) $p->get('id'),
                'name' => (string) $p->get('name'),
                'color' => (string) ($p->get('color') ?: '#6c757d'),
                'value' => (int) $p->get('value'),
            ];
        }

        return $out;
    }

    /**
     * Набор допустимых числовых значений приоритета.
     *
     * @return list<int>
     */
    public function values(): array
    {
        return array_map(static fn (array $p): int => $p['value'], $this->all());
    }

    /** Есть ли такой приоритет в справочнике. */
    public function isValid(int $value): bool
    {
        return (int) $this->modx->getCount(MxBoardPriority::class, ['value' => $value]) > 0;
    }

    /**
     * Значение по умолчанию для новой карточки, когда приоритет не передан: минимальный
     * существующий. -1 — справочник пуст (не должно случаться: сид заводит четвёрку).
     */
    public function defaultValue(): int
    {
        $values = $this->values();

        return $values === [] ? -1 : min($values);
    }

    /**
     * Создать приоритет. Инварианты 3–4 (уникальность value/name) — до записи, с
     * внятной ошибкой поля; БД-индексы страхуют от гонок.
     *
     * @param array<string, mixed> $data name/color/value
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function create(modUser $user, array $data): array
    {
        $error = $this->gate($user);
        if ($error !== null) {
            return $this->fail($error);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->fail('mxboard_err_priority_name_required');
        }

        [$value, $valueError] = $this->normalizeValue($data['value'] ?? null);
        if ($valueError !== null) {
            return $this->fail($valueError);
        }

        if ($this->modx->getObject(MxBoardPriority::class, ['value' => $value])) {
            return $this->fail('mxboard_err_priority_value_exists');
        }
        if ($this->modx->getObject(MxBoardPriority::class, ['name' => $name])) {
            return $this->fail('mxboard_err_priority_name_exists');
        }

        /** @var MxBoardPriority $priority */
        $priority = $this->modx->newObject(MxBoardPriority::class);
        $priority->fromArray([
            'name' => $name,
            'color' => $this->normalizeColor($data['color'] ?? null),
            'value' => $value,
            'createdon' => time(),
        ]);

        if (!$priority->save()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok($priority->toArray());
    }

    /**
     * Правка приоритета: name, color, value. Уникальность проверяется без учёта самой
     * записи (переименование в то же имя — не дубль).
     *
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function update(modUser $user, int $id, array $data): array
    {
        $error = $this->gate($user);
        if ($error !== null) {
            return $this->fail($error);
        }

        /** @var MxBoardPriority|null $priority */
        $priority = $this->modx->getObject(MxBoardPriority::class, $id);
        if (!$priority) {
            return $this->fail('mxboard_err_priority_not_found');
        }

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->fail('mxboard_err_priority_name_required');
            }
            $dupe = $this->modx->getObject(MxBoardPriority::class, ['name' => $name, 'id:!=' => $id]);
            if ($dupe) {
                return $this->fail('mxboard_err_priority_name_exists');
            }
            $priority->set('name', $name);
        }

        if (array_key_exists('value', $data)) {
            [$value, $valueError] = $this->normalizeValue($data['value']);
            if ($valueError !== null) {
                return $this->fail($valueError);
            }
            $dupe = $this->modx->getObject(MxBoardPriority::class, ['value' => $value, 'id:!=' => $id]);
            if ($dupe) {
                return $this->fail('mxboard_err_priority_value_exists');
            }
            $priority->set('value', $value);
        }

        if (array_key_exists('color', $data)) {
            $priority->set('color', $this->normalizeColor($data['color']));
        }

        if (!$priority->save()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok($priority->toArray());
    }

    /**
     * Удалить приоритет. Инвариант 2: последний оставшийся не удаляется — иначе шкала
     * исчезла бы целиком и карточкам стало бы нечем адресоваться.
     *
     * @return array{success: bool, message: string, object: null}
     */
    public function remove(modUser $user, int $id): array
    {
        $error = $this->gate($user);
        if ($error !== null) {
            return $this->fail($error);
        }

        /** @var MxBoardPriority|null $priority */
        $priority = $this->modx->getObject(MxBoardPriority::class, $id);
        if (!$priority) {
            return $this->fail('mxboard_err_priority_not_found');
        }

        if ((int) $this->modx->getCount(MxBoardPriority::class) <= 1) {
            return $this->fail('mxboard_err_priority_last');
        }

        if (!$priority->remove()) {
            return $this->fail('mxboard_err_save');
        }

        return ['success' => true, 'message' => '', 'object' => null];
    }

    /**
     * Право на правку справочника: sudo ИЛИ супер хотя бы одного отдела.
     *
     * @return string|null ключ ошибки или null, если можно
     */
    private function gate(modUser $user): ?string
    {
        if (Transitions::isSuperuser($this->modx, $user)
            || Transitions::isAnyDepartmentManager($this->modx, $user)) {
            return null;
        }

        return 'mxboard_err_structure_denied';
    }

    /**
     * Нормализовать числовое значение: целое ≥ 0 (поле unsigned). Дробное/отрицательное/
     * нечисловое — ошибка, а не молчаливое приведение.
     *
     * @return array{0: int, 1: string|null}
     */
    private function normalizeValue(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return [0, 'mxboard_err_priority_value_invalid'];
        }
        $value = (int) $raw;
        if ((string) $value !== (string) $raw || $value < 0) {
            // (float)2.5 или -1: не целое неотрицательное — отклоняем явно.
            return [0, 'mxboard_err_priority_value_invalid'];
        }

        return [$value, null];
    }

    /** Цвет #rrggbb; мусор → дефолтный серый (как у колонок). */
    private function normalizeColor(mixed $raw): string
    {
        $color = trim((string) ($raw ?? ''));

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#6c757d';
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array{success: bool, message: string, object: array<string, mixed>}
     */
    private function ok(array $object): array
    {
        return ['success' => true, 'message' => '', 'object' => $object];
    }

    /** @return array{success: bool, message: string, object: null} */
    private function fail(string $lexiconKey): array
    {
        return [
            'success' => false,
            'message' => $this->modx->lexicon($lexiconKey) ?: $lexiconKey,
            'object' => null,
        ];
    }
}
