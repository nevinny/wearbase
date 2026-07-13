<?php

declare(strict_types=1);

namespace App\Service\Social\Source;

use App\Entity\SocialPost;
use App\Repository\BrandRepository;
use App\Repository\SocialPostRepository;
use App\Service\LlmService;
use App\Service\Social\SocialRubrics;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Yaml\Yaml;

/**
 * replace_departed (пт): «чем заменить ушедших» — сид config/social/departed_brands.yaml
 * (проверенные факты, docs/departed_brands_seed.md). Ротация записи — детерминированный
 * курсор (кол-во уже созданных постов рубрики % число записей), зеркало brandCursor
 * в SocialPlanner. needsBrand=false: альтернативы — не одна назначенная рубрике, а несколько
 * живых slug из записи.
 *
 * Тело двухслойное (анти-галлюцинация):
 *  1) LLM-лид строго на фактах записи (departed/niche/successor/successor_note);
 *  2) детерминированная строка «Российские альтернативы: …» — НЕ LLM, имена по slug из БД
 *     (только status=active; несуществующие/неактивные пропускаются).
 * Если у записи живых альтернатив < 2 — запись пропускается, берётся следующая по курсору.
 */
class DepartedCaptionSource implements CaptionSourceInterface
{
    private const MIN_ALTERNATIVES = 2;
    private const MAX_ALTERNATIVES = 3;

    /** @var array<int,array<string,mixed>>|null */
    private ?array $records = null;

    public function __construct(
        private readonly LlmService $llm,
        private readonly BrandRepository $brands,
        private readonly SocialPostRepository $posts,
        private readonly string $yamlPath,
    ) {
    }

    public function key(): string
    {
        return SocialRubrics::SOURCE_DEPARTED;
    }

    public function body(SocialPost $post): string
    {
        $records = $this->loadRecords();
        $total = count($records);
        if ($total === 0) {
            throw new \RuntimeException('config/social/departed_brands.yaml пуст');
        }

        $cursor = $this->posts->countByRubric($post->getRubric());
        for ($i = 0; $i < $total; $i++) {
            $record = $records[($cursor + $i) % $total];
            $alternatives = $this->liveAlternatives($record['alternatives'] ?? []);
            if (count($alternatives) >= self::MIN_ALTERNATIVES) {
                return $this->compose($record, $alternatives);
            }
        }

        throw new \RuntimeException('В departed_brands.yaml нет ни одной записи с ≥2 живыми альтернативами');
    }

    /** @param string[] $names */
    private function compose(array $record, array $names): string
    {
        $lead = trim($this->llm->generate($this->prompt($record), $this->system(), local: true, think: false));
        $line = 'Российские альтернативы: ' . implode(', ', $names);

        return $lead . "\n\n" . $line;
    }

    private function system(): string
    {
        return 'Ты — SMM-копирайтер. Пишешь по-русски, честно и по делу, без рекламных штампов и без markdown. '
            . 'Отвечаешь только текстом подписи.';
    }

    private function prompt(array $record): string
    {
        $departed = (string) ($record['departed'] ?? '');
        $niche = (string) ($record['niche'] ?? '');
        $successor = trim((string) ($record['successor'] ?? ''));
        $successorNote = trim((string) ($record['successor_note'] ?? ''));

        $facts = "Бренд «{$departed}» ушёл из России. Ниша: {$niche}.";
        if ($successor !== '') {
            $facts .= " На месте «{$departed}» работает «{$successor}».";
            if ($successorNote !== '') {
                $facts .= " {$successorNote}.";
            }
        }

        return <<<EOT
Факты (единственный источник, не добавляй других фактов/цифр/дат):
{$facts}

Напиши 2–3 предложения лид-абзаца поста в соцсети о том, что бренд «{$departed}» ушёл из России.
Не упоминай альтернативы и не советуй, чем заменить — это добавится отдельной строкой ниже.
Максимум 40 слов, на «ты».
Запрещено: выдумывать факты сверх приведённых, «инновационный», «уникальный», «передовой», «лидирующий», кавычки, markdown, ссылки, хэштеги.
Только текст.
EOT;
    }

    /**
     * Живые (status=active) бренды по slug из записи, в порядке из yaml, не более MAX_ALTERNATIVES.
     * @param string[] $slugs
     * @return string[] названия брендов
     */
    private function liveAlternatives(array $slugs): array
    {
        $names = [];
        foreach ($slugs as $slug) {
            if (count($names) >= self::MAX_ALTERNATIVES) {
                break;
            }
            $brand = $this->brands->findOneBy(['slug' => $slug, 'status' => Statuses::Active]);
            if ($brand !== null && $brand->getTitle()) {
                $names[] = $brand->getTitle();
            }
        }

        return $names;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadRecords(): array
    {
        if ($this->records === null) {
            $parsed = Yaml::parseFile($this->yamlPath);
            $this->records = is_array($parsed) ? $parsed : [];
        }

        return $this->records;
    }
}
