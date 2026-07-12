<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

/**
 * Маркер user-safe сообщений WardrobeAiService: текст этого исключения можно
 * показать пользователю напрямую (дневной кап, «не удалось распознать/получить…»).
 * Любое ДРУГОЕ исключение (транспортные ошибки LlmService/WebScraperService и
 * т.п. могут содержать URL провайдера/детали ответа) — наружу уходит generic-текст,
 * полная деталь остаётся только в логе (см. catch в suggestFromPhoto/suggestFromUrl).
 */
class WardrobeAiException extends \RuntimeException
{
}
