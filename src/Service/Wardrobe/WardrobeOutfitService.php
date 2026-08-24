<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\AiUsageLog;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Repository\WardrobeConsentRepository;
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
        private readonly string $remoteModel,
        private readonly string $localModel,
        private readonly bool $localFirst,
        private readonly ?WardrobeConsentRepository $consents = null,
        private readonly ?WardrobeStylistContextBuilder $contextBuilder = null,
    ) {}

    /**
     * @param WardrobeItem[] $items
     * @return array<int, array{title:string, explanation:string, items:WardrobeItem[]}>
     */
    public function suggest(User $user, array $items, string $request, string $preferenceContext = '', ?User $subject = null, ?string $event = null, ?string $weatherCondition = null, ?string $temperatureBand = null): array
    {
        $subject ??= $user;
        $context = $this->contextBuilder?->build($subject, $items, $event, $weatherCondition, $temperatureBand) ?? [
            'items' => array_values(array_filter($items, static fn (WardrobeItem $item): bool =>
                $item->getItemStatus() === WardrobeItem::ITEM_ACTIVE
                && $item->getWearStatus() === WardrobeItem::WEAR_ACTIVE
                && $item->getCleanlinessStatus() === WardrobeItem::CLEANLINESS_CLEAN
            )),
            'rotation' => [],
            'event' => null,
            'weather' => null,
        ];
        $items = array_slice($context['items'], 0, self::MAX_ITEMS);
        if (count($items) < 2) {
            throw new \DomainException('Добавьте хотя бы две активные вещи, чтобы собрать образ');
        }
        $request = mb_substr(trim($request), 0, 300) ?: 'Повседневный универсальный образ';

        if ($this->localFirst) {
            try {
                [$prompt, $itemMap] = $this->prompt($items, $request, $preferenceContext, $context, false);
                $response = $this->llm->generate(
                    $prompt,
                    model: $this->localModel,
                    timeout: 60,
                    local: true,
                    think: false,
                    temperature: 0.4,
                    fastFail: true,
                );
                $result = $this->normalize($response, $itemMap);
            } catch (\Throwable) {
                // Remote fallback ниже разрешён только явным consent владельца гардероба.
            }
            if (isset($result)) {
                $this->usageTracker->recordLocal($user, AiUsageLog::FEATURE_WARDROBE_OUTFIT, $this->localModel);

                return $result;
            }
        }

        if (!$this->consents?->isPersonalizationGranted($subject)) {
            throw new WardrobeAiException('Разрешите remote-стилиста и персонализацию для этого профиля');
        }
        if (!$this->meter->allowed()) {
            throw new WardrobeAiException('Дневной лимит AI-подборов исчерпан, попробуйте завтра');
        }
        $this->meter->record();
        [$prompt, $itemMap] = $this->prompt($items, $request, $preferenceContext, $context, true);
        $response = $this->llm->generate($prompt, model: $this->remoteModel, timeout: 45, maxTokens: 1200, temperature: 0.4);
        $this->usageTracker->record($user, AiUsageLog::FEATURE_WARDROBE_OUTFIT);

        return $this->normalize($response, $itemMap);
    }

    /**
     * @param WardrobeItem[] $items
     * @param array{items:WardrobeItem[],rotation:array<int,string>,event:?string,weather:?string} $context
     * @return array{string,array<int,WardrobeItem>}
     */
    private function prompt(array $items, string $request, string $preferenceContext, array $context, bool $minimized): array
    {
        $catalog = [];
        $itemMap = [];
        foreach ($items as $index => $item) {
            $promptId = $minimized ? $index + 1 : (int) $item->getId();
            $itemMap[$promptId] = $item;
            $row = [
                'id' => $promptId,
                'category' => $item->getCategory(),
                'color' => $item->getColorName(),
                'season' => $item->getSeason(),
                'styles' => array_map(static fn ($style): string => $style->getTitle(), $item->getStyles()->toArray()),
                'rotation' => $context['rotation'][(int) $item->getId()] ?? 'fresh',
            ];
            if (!$minimized) {
                $row['name'] = $item->getName() ?: 'Без названия';
                $row['material'] = $item->getMaterialText();
            }
            $catalog[] = $row;
        }
        $catalogJson = json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $structuredJson = json_encode([
            'event' => $context['event'],
            'weather' => $context['weather'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $prompt = <<<PROMPT
Ты стилист цифрового гардероба. Собери до 3 разных образов только из вещей каталога.
Учитывай категорию, цвет, сезон и стиль. Не добавляй вещи, которых нет в каталоге.
В каждом образе должно быть от 2 до 5 вещей с разными функциональными ролями.
Предпочитай rotation=fresh; rotation=recent используй, только если сочетание заметно лучше.

Запрос пользователя: {$request}
{$preferenceContext}
Структурированный контекст: {$structuredJson}

Каталог JSON:
{$catalogJson}

Верни ТОЛЬКО валидный JSON без markdown:
{"outfits":[{"title":"короткое название","explanation":"одно предложение до 240 символов","item_ids":[1,2]}]}
PROMPT;

        return [$prompt, $itemMap];
    }

    /**
     * @param array<int,WardrobeItem> $byId
     * @return array<int, array{title:string, explanation:string, items:WardrobeItem[]}>
     */
    private function normalize(string $response, array $byId): array
    {
        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $response) ?? $response;
        if (!preg_match('/\{[\s\S]*\}/', $cleaned, $match)) {
            throw new WardrobeAiException('Не удалось собрать образы, попробуйте ещё раз');
        }
        $data = json_decode($match[0], true);
        if (!is_array($data['outfits'] ?? null)) {
            throw new WardrobeAiException('Не удалось собрать образы, попробуйте ещё раз');
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
                'explanation' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($outfit['explanation'] ?? ''))), 0, 240),
                'items' => $selected,
            ];
        }

        if ($result === []) {
            throw new WardrobeAiException('Модель не нашла подходящих сочетаний');
        }

        return $result;
    }
}
