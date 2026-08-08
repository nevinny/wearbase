<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\AiUsageLog;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Service\AiUsageTracker;
use App\Service\LlmService;
use App\Service\WardrobeAiMeter;

class WardrobeOutfitService
{
    private const MAX_ITEMS = 80;

    public function __construct(
        private readonly LlmService $llm,
        private readonly WardrobeAiMeter $meter,
        private readonly AiUsageTracker $usageTracker,
        private readonly string $model,
    ) {}

    /**
     * @param WardrobeItem[] $items
     * @return array<int, array{title:string, explanation:string, items:WardrobeItem[]}>
     */
    public function suggest(User $user, array $items, string $request): array
    {
        $items = array_slice($items, 0, self::MAX_ITEMS);
        if (count($items) < 2) {
            throw new \DomainException('Добавьте хотя бы две активные вещи, чтобы собрать образ');
        }
        if (!$this->meter->allowed()) {
            throw new WardrobeAiException('Дневной лимит AI-подборов исчерпан, попробуйте завтра');
        }

        $catalog = array_map(static fn (WardrobeItem $item): array => [
            'id' => $item->getId(),
            'name' => $item->getName() ?: 'Без названия',
            'category' => $item->getCategory(),
            'color' => $item->getColorName(),
            'season' => $item->getSeason(),
            'material' => $item->getMaterialText(),
            'styles' => array_map(static fn ($style): string => $style->getTitle(), $item->getStyles()->toArray()),
        ], $items);
        $catalogJson = json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $request = mb_substr(trim($request), 0, 300) ?: 'Повседневный универсальный образ';

        $prompt = <<<PROMPT
Ты стилист цифрового гардероба. Собери до 3 разных образов только из вещей каталога.
Учитывай категорию, цвет, сезон, материал и стиль. Не добавляй вещи, которых нет в каталоге.
В каждом образе должно быть от 2 до 5 вещей с разными функциональными ролями.

Запрос пользователя: {$request}

Каталог JSON:
{$catalogJson}

Верни ТОЛЬКО валидный JSON без markdown:
{"outfits":[{"title":"короткое название","explanation":"почему вещи сочетаются и куда так пойти","item_ids":[1,2]}]}
PROMPT;

        $this->meter->record();
        $response = $this->llm->generate($prompt, model: $this->model, timeout: 45, maxTokens: 1200, temperature: 0.4);
        $this->usageTracker->record($user, AiUsageLog::FEATURE_WARDROBE_OUTFIT);

        return $this->normalize($response, $items);
    }

    /**
     * @param WardrobeItem[] $items
     * @return array<int, array{title:string, explanation:string, items:WardrobeItem[]}>
     */
    private function normalize(string $response, array $items): array
    {
        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $response) ?? $response;
        if (!preg_match('/\{[\s\S]*\}/', $cleaned, $match)) {
            throw new WardrobeAiException('Не удалось собрать образы, попробуйте ещё раз');
        }
        $data = json_decode($match[0], true);
        if (!is_array($data['outfits'] ?? null)) {
            throw new WardrobeAiException('Не удалось собрать образы, попробуйте ещё раз');
        }

        $byId = [];
        foreach ($items as $item) {
            $byId[$item->getId()] = $item;
        }

        $result = [];
        foreach (array_slice($data['outfits'], 0, 3) as $outfit) {
            if (!is_array($outfit)) {
                continue;
            }
            $ids = is_array($outfit['item_ids'] ?? null) ? $outfit['item_ids'] : [];
            $selected = [];
            foreach (array_slice(array_unique($ids), 0, 5) as $id) {
                $id = filter_var($id, FILTER_VALIDATE_INT);
                if ($id !== false && isset($byId[$id])) {
                    $selected[] = $byId[$id];
                }
            }
            if (count($selected) < 2) {
                continue;
            }
            $result[] = [
                'title' => mb_substr(trim((string) ($outfit['title'] ?? 'Готовый образ')), 0, 100),
                'explanation' => mb_substr(trim((string) ($outfit['explanation'] ?? '')), 0, 600),
                'items' => $selected,
            ];
        }

        if ($result === []) {
            throw new WardrobeAiException('Модель не нашла подходящих сочетаний');
        }

        return $result;
    }
}
