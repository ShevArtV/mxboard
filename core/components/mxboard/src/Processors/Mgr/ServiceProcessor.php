<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr;

use MODX\Revolution\modUser;
use MODX\Revolution\Processors\Processor;

/**
 * Базовый процессор менеджера mxBoard: тонкая обёртка над сервисами.
 *
 * Вся логика (права, валидация, журнал) — в TaskService/StructureService/BoardQuery,
 * общих для менеджера, REST и MCP. Здесь только: пользователь из сессии менеджера,
 * маппинг свойств запроса в аргументы сервиса и результата сервиса в ответ коннектора.
 */
abstract class ServiceProcessor extends Processor
{
    public $languageTopics = ['mxboard:default'];

    public function process()
    {
        // Через коннектор топик не всегда успевает подгрузиться до process(),
        // и lexicon() вернул бы голый ключ.
        $this->modx->lexicon->load('mxboard:default');

        $user = $this->modx->user;
        if (!$user instanceof modUser || (int) $user->get('id') <= 0) {
            return $this->failure($this->modx->lexicon('mxboard_err_unauthenticated'));
        }

        return $this->handle($user);
    }

    /** Тело процессора: пользователь уже проверен. */
    abstract protected function handle(modUser $user);

    /**
     * Ответ из результата сервиса {success, message, object}.
     *
     * @param array{success: bool, message: string, object: mixed} $result
     */
    protected function fromResult(array $result)
    {
        if (!$result['success']) {
            // object прокидываем и при отказе: напр. ИИ-проверка кладёт туда вердикт
            // (чего не хватает + можно ли создать в обход) для показа во фронте.
            return $this->failure((string) $result['message'], $result['object'] ?? null);
        }

        return $this->success((string) $result['message'], $result['object']);
    }

    /**
     * Свойства запроса по списку ключей — только реально переданные (null = не слать
     * ключ сервису: его update-методы различают «не менять» и «очистить» через
     * array_key_exists).
     *
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    protected function presentProperties(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $this->getProperty($key);
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * JSON-свойство: массив как есть (JSON-тело), строку — распарсить (form-data).
     *
     * @return array<mixed>|null
     */
    protected function jsonProperty(string $key): ?array
    {
        $value = $this->getProperty($key);
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
