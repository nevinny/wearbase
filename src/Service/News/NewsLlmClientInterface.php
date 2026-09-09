<?php

declare(strict_types=1);

namespace App\Service\News;

/**
 * Точка доступа к LLM для новостного конвейера. Интерфейс — чтобы тесты
 * подсовывали stub/double без сети (ollama в CI недоступна).
 */
interface NewsLlmClientInterface
{
    /**
     * Один chat-запрос; возвращает сырой текст ответа модели.
     *
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @throws NewsLlmUnavailableException если ollama не отвечает / не 2xx
     */
    public function chat(array $messages, ?string $format = null): string;
}
