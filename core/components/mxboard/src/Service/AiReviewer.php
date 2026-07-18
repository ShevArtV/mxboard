<?php

declare(strict_types=1);

namespace MxBoard\Service;

use MODX\Revolution\modX;
use MxBoard\Model\MxBoardTaskType;

/**
 * Провайдер-агностик проверятор полноты постановки задачи.
 *
 * Говорит на OpenAI-совместимом формате (POST {base_url}/chat/completions,
 * Authorization: Bearer) — работает с mimo, OpenAI, DeepSeek, локальными vLLM/Ollama.
 * Никакой привязки к конкретному провайдеру в коде: URL/ключ/модель — системные настройки.
 *
 * Ответ парсится защитно (JSON из текста, «думающие» модели с пустым content),
 * а любой сбой транспорта = null → вызывающий трактует как fail-open (не блокировать
 * работу доски из-за недоступности стороннего API).
 */
class AiReviewer
{
    /** Запас токенов: reasoning-модели тратят бюджет на «размышление» ДО ответа —
     *  на длинном промпте 2048 иногда не оставляет места для JSON (пустой content). */
    private const MAX_TOKENS = 4096;

    /** Таймаут запроса, сек. Reasoning-модели отвечают 12–18с с большим разбросом —
     *  15с рвёт «долгие» вызовы (curl → null → ложный fail-open, гейт не срабатывает). */
    private const TIMEOUT = 30;

    public function __construct(private modX $modx)
    {
    }

    /** Проверка настроена и включена: заданы базовый URL и ключ. */
    public function isEnabled(): bool
    {
        return $this->baseUrl() !== '' && trim((string) $this->modx->getOption('mxboard.ai_api_key', null, '')) !== '';
    }

    /**
     * Оценить полноту постановки. Возвращает вердикт или null при сбое (fail-open).
     *
     * @param array{title: string, tor: string, fields: array<string, mixed>} $task
     * @param list<array{key: string, label: string, type: string, required: bool}> $fieldDefs
     *
     * @return array{complete: bool, score: int, missing: list<string>, summary: string, model: string, checkedon: int}|null
     */
    public function review(array $task, MxBoardTaskType $type, array $fieldDefs): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $system = trim((string) $type->get('ai_prompt'));
        if ($system === '') {
            $system = (string) $this->modx->getOption('mxboard.ai_check_prompt', null, '');
        }
        if ($system === '') {
            return null;
        }

        $model = (string) $this->modx->getOption('mxboard.ai_model', null, 'mimo-v2.5');
        $userMessage = $this->buildUserMessage($task, $type, $fieldDefs);

        $response = $this->call($model, $system, $userMessage);
        if ($response === null) {
            return null;
        }

        $verdict = $this->parseVerdict($response);
        if ($verdict === null) {
            return null;
        }

        $verdict['model'] = $model;
        $verdict['checkedon'] = time();

        return $verdict;
    }

    /** Собрать текст задачи для модели: тип, его поля-вопросы и заполненные значения. */
    private function buildUserMessage(array $task, MxBoardTaskType $type, array $fieldDefs): string
    {
        $lines = [];
        $lines[] = 'Тип задачи: ' . (string) $type->get('name');
        $description = trim((string) $type->get('description'));
        if ($description !== '') {
            $lines[] = 'Описание типа: ' . $description;
        }
        $lines[] = '';
        $lines[] = 'Заголовок: ' . (string) ($task['title'] ?? '');
        $lines[] = 'Постановка (ToR): ' . (trim((string) ($task['tor'] ?? '')) ?: '(пусто)');
        $lines[] = '';
        $lines[] = 'Поля типа (каждое — вопрос, на который должна отвечать постановка):';

        $values = (array) ($task['fields'] ?? []);
        foreach ($fieldDefs as $def) {
            $key = (string) ($def['key'] ?? '');
            $label = (string) ($def['label'] ?? $key);
            $req = !empty($def['required']) ? ' [обязательное]' : '';
            $raw = $values[$key] ?? '';
            $val = is_scalar($raw) ? trim((string) $raw) : json_encode($raw, JSON_UNESCAPED_UNICODE);
            $lines[] = "- {$label}{$req}: " . ($val !== '' ? $val : '(не заполнено)');
        }

        return implode("\n", $lines);
    }

    /**
     * Один запрос к провайдеру. Возвращает текст ответа модели или null при сбое.
     */
    private function call(string $model, string $system, string $user): ?string
    {
        $url = $this->baseUrl() . '/chat/completions';
        $key = trim((string) $this->modx->getOption('mxboard.ai_api_key', null, ''));

        $payload = json_encode([
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '') {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[mxBoard] ИИ-проверка: сбой запроса: ' . $err);

            return null;
        }
        if ($status < 200 || $status >= 300) {
            $this->modx->log(modX::LOG_LEVEL_WARN, "[mxBoard] ИИ-проверка: HTTP {$status}: " . mb_substr((string) $body, 0, 500));

            return null;
        }

        $decoded = json_decode((string) $body, true);
        // OpenAI-формат: choices[0].message.content. Пустой content у reasoning-моделей
        // при малом бюджете токенов — это сбой, а не «пустой вердикт».
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        if (!is_string($content) || trim($content) === '') {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[mxBoard] ИИ-проверка: пустой ответ модели.');

            return null;
        }

        return $content;
    }

    /**
     * Достать вердикт из текста ответа: находим JSON-объект и валидируем ключи.
     *
     * @return array{complete: bool, score: int, missing: list<string>, summary: string}|null
     */
    private function parseVerdict(string $text): ?array
    {
        $json = $this->extractJson($text);
        if ($json === null) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[mxBoard] ИИ-проверка: не удалось разобрать JSON из ответа.');

            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !array_key_exists('complete', $data)) {
            return null;
        }

        $missing = [];
        foreach ((array) ($data['missing'] ?? []) as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $missing[] = $item;
            }
        }

        return [
            'complete' => (bool) $data['complete'],
            'score' => max(0, min(100, (int) ($data['score'] ?? 0))),
            'missing' => $missing,
            'summary' => trim((string) ($data['summary'] ?? '')),
        ];
    }

    /** Первый сбалансированный JSON-объект в тексте (модель может обрамлять его прозой). */
    private function extractJson(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($text);
        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /** Базовый URL без хвостового слэша. */
    private function baseUrl(): string
    {
        return rtrim(trim((string) $this->modx->getOption('mxboard.ai_base_url', null, '')), '/');
    }
}
