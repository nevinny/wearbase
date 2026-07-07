<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

/**
 * Единственная точка E-E-A-T-фильтра для серии «уход с маркетплейсов / прямые продажи».
 * Отдаёт генератору ТОЛЬКО curated-факты (type=fact) из config/content/marketplace_exit_facts.yaml —
 * type=opinion физически НИКОГДА не попадает в вывод (значит, и в промпт LLM).
 *
 * Схема факта в yaml: {claim, metric, source, type, attribution, needs_check}.
 * Формат строки блока: «- {claim} ({metric}). Источник: {source}» (metric пустой/~ → без скобок).
 *
 * Разбор-первоисточник и полный список кандидатов — docs/marketplace_exit_content.md.
 */
final class MarketplaceExitContext
{
    private const FACTS_FILE = '/config/content/marketplace_exit_facts.yaml';

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * Все подтверждённые факты (type=fact) единым блоком. Мнения отфильтрованы.
     */
    public function factBlock(): string
    {
        return $this->render($this->facts());
    }

    /**
     * Подмножество фактов под угол: включаем факт, если хотя бы одна подстрока из $refs
     * содержится в его claim. Пустой $refs → все факты. Фильтр type=fact неизменен.
     *
     * @param string[] $refs подстроки claim
     */
    public function factsForAngle(array $refs): string
    {
        $facts = $this->facts();

        $refs = array_values(array_filter(array_map('trim', $refs), static fn(string $r) => $r !== ''));
        if ($refs !== []) {
            $facts = array_values(array_filter($facts, static function (array $f) use ($refs): bool {
                $claim = (string) ($f['claim'] ?? '');
                foreach ($refs as $ref) {
                    if (mb_stripos($claim, $ref) !== false) {
                        return true;
                    }
                }

                return false;
            }));
        }

        return $this->render($facts);
    }

    /**
     * Сырые факты type=fact из yaml. Единственное место чтения файла.
     *
     * @return array<int,array<string,mixed>>
     */
    private function facts(): array
    {
        $data = Yaml::parseFile($this->projectDir . self::FACTS_FILE);

        return array_values(array_filter(
            (array) ($data['facts'] ?? []),
            static fn($f) => is_array($f) && ($f['type'] ?? null) === 'fact',
        ));
    }

    /** @param array<int,array<string,mixed>> $facts */
    private function render(array $facts): string
    {
        $lines = [];
        foreach ($facts as $f) {
            $claim  = trim((string) ($f['claim'] ?? ''));
            if ($claim === '') {
                continue;
            }
            $metric = trim((string) ($f['metric'] ?? ''));
            $source = trim((string) ($f['source'] ?? ''));

            $line = '- ' . $claim;
            if ($metric !== '') {
                $line .= ' (' . $metric . ')';
            }
            $line .= '.';
            if ($source !== '') {
                $line .= ' Источник: ' . $source;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
