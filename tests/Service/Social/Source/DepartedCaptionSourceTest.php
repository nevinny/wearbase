<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Source;

use App\Entity\Brand;
use App\Entity\SocialPost;
use App\Repository\BrandRepository;
use App\Repository\SocialPostRepository;
use App\Service\LlmService;
use App\Service\Social\Source\DepartedCaptionSource;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use PHPUnit\Framework\TestCase;

class DepartedCaptionSourceTest extends TestCase
{
    private const YAML = <<<'YAML'
-
  departed: "Alpha"
  niche: "быстрая мода"
  successor: ""
  successor_note: ""
  alternatives: ["alpha-live-1", "alpha-dead-1"]
-
  departed: "Beta"
  niche: "спортивная одежда"
  successor: "BetaNext"
  successor_note: "перезапущен под новым именем"
  alternatives: ["beta-live-1", "beta-live-2"]
-
  departed: "Gamma"
  niche: "джинсовая одежда"
  successor: ""
  successor_note: ""
  alternatives: ["gamma-live-1", "gamma-live-2", "gamma-live-3"]
YAML;

    /** Только slug'и с "-live-" в имени считаются активными брендами в БД. */
    private function brandsRepo(): BrandRepository
    {
        $repo = $this->createMock(BrandRepository::class);
        $repo->method('findOneBy')->willReturnCallback(function (array $criteria) {
            $slug = $criteria['slug'] ?? '';
            self::assertSame(Statuses::Active, $criteria['status'] ?? null);
            if (!str_contains($slug, '-live-')) {
                return null;
            }
            return (new Brand())->setTitle(ucfirst(str_replace('-', ' ', $slug)))->setSlug($slug);
        });

        return $repo;
    }

    private function yamlPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'departed_') . '.yaml';
        file_put_contents($path, self::YAML);

        return $path;
    }

    private function llmEchoingPrompt(): LlmService
    {
        $llm = $this->createMock(LlmService::class);
        $llm->method('generate')->willReturnCallback(static fn (string $prompt): string => $prompt);

        return $llm;
    }

    private function post(): SocialPost
    {
        return (new SocialPost())->setRubric('replace_departed');
    }

    /** Alpha имеет только 1 живую альтернативу → пропускается, курсор=0 берёт Beta. */
    public function testSkipsRecordWithFewerThanTwoLiveAlternatives(): void
    {
        $posts = $this->createMock(SocialPostRepository::class);
        $posts->method('countByRubric')->willReturn(0);

        $source = new DepartedCaptionSource($this->llmEchoingPrompt(), $this->brandsRepo(), $posts, $this->yamlPath());
        $body = $source->body($this->post());

        self::assertStringContainsString('Beta', $body);
        self::assertStringNotContainsString('Alpha', $body);
        self::assertStringContainsString('Российские альтернативы: Beta live 1, Beta live 2', $body);
    }

    /** Курсор=2 (countByRubric=2) → напрямую Gamma (3 живых альтернативы, максимум 3 в строке). */
    public function testRotatesByCursorToGamma(): void
    {
        $posts = $this->createMock(SocialPostRepository::class);
        $posts->method('countByRubric')->willReturn(2);

        $source = new DepartedCaptionSource($this->llmEchoingPrompt(), $this->brandsRepo(), $posts, $this->yamlPath());
        $body = $source->body($this->post());

        self::assertStringContainsString('Gamma', $body);
        self::assertStringContainsString('Российские альтернативы: Gamma live 1, Gamma live 2, Gamma live 3', $body);
    }

    /** Строка альтернатив — детерминированная (не через LLM): не содержит служебных фраз промпта. */
    public function testAlternativesLineIsDeterministicNotLlm(): void
    {
        $posts = $this->createMock(SocialPostRepository::class);
        $posts->method('countByRubric')->willReturn(1);

        $source = new DepartedCaptionSource($this->llmEchoingPrompt(), $this->brandsRepo(), $posts, $this->yamlPath());
        $body = $source->body($this->post());

        $lines = explode("\n\n", trim($body));
        self::assertSame('Российские альтернативы: Beta live 1, Beta live 2', end($lines));
    }
}
