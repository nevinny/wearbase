<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Жёсткий граф внутренней перелинковки брендов (brand_related).
 *
 * Принципы (docs/seo_adoption_plan.md, п.2):
 *  - рёбра строятся офлайн и НЕ пересчитываются на запрос — стабильный граф для Google;
 *  - существующие рёбра неприкосновенны: ребро заменяется только если target ушёл
 *    из active или при балансировке in-degree (вытесняются слабые source, не embedding);
 *  - инвариант: каждый активный бренд имеет >= MIN_IN входящих рёбер (нет сирот).
 *
 * Источники рёбер по силе: embedding (Qdrant) > style > city > fill.
 * Эмбеддинг-скоринг живёт в BuildLinkGraphCommand (Qdrant есть только в локальной сети);
 * этот сервис — чистый SQL, безопасен на проде (publish-tick).
 */
class BrandLinkGraphService
{
    public const OUT_DEGREE = 12; // исходящих рёбер на бренд (кратно сетке 2/3/4 колонок — ровные ряды на всех брейкпоинтах)
    public const MIN_IN     = 2; // гарантированный минимум входящих

    // Какие рёбра можно вытеснять при балансировке (слабые → сильные нельзя)
    private const REPLACEABLE = ['fill', 'city'];

    public function __construct(private readonly Connection $db)
    {
    }

    public function outDegree(int $brandId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM brand_related WHERE brand_id = :id',
            ['id' => $brandId],
        );
    }

    public function inDegree(int $brandId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM brand_related WHERE related_brand_id = :id',
            ['id' => $brandId],
        );
    }

    /**
     * Жёсткая запись исходящих рёбер бренда. Существующие рёбра не трогаем —
     * только добиваем свободные позиции. $candidates — id в порядке убывания близости.
     *
     * @param array<int,int> $candidates
     * @return int сколько рёбер добавлено
     */
    public function addEdges(int $brandId, array $candidates, string $source): int
    {
        $existing = $this->db->fetchFirstColumn(
            'SELECT related_brand_id FROM brand_related WHERE brand_id = :id',
            ['id' => $brandId],
        );
        $existing  = array_map('intval', $existing);
        $positions = $this->db->fetchFirstColumn(
            'SELECT position FROM brand_related WHERE brand_id = :id',
            ['id' => $brandId],
        );
        $positions = array_map('intval', $positions);

        $added = 0;
        $pos   = 1;
        foreach ($candidates as $candidateId) {
            if (count($existing) >= self::OUT_DEGREE) {
                break;
            }
            $candidateId = (int) $candidateId;
            if ($candidateId === $brandId || in_array($candidateId, $existing, true)) {
                continue;
            }
            while (in_array($pos, $positions, true)) {
                $pos++;
            }
            if ($pos > self::OUT_DEGREE) {
                break;
            }
            $this->db->executeStatement(
                'INSERT IGNORE INTO brand_related (brand_id, related_brand_id, position, source)
                 VALUES (:brand, :related, :pos, :source)',
                ['brand' => $brandId, 'related' => $candidateId, 'pos' => $pos, 'source' => $source],
            );
            $existing[]  = $candidateId;
            $positions[] = $pos;
            $added++;
        }

        return $added;
    }

    /**
     * SQL-кандидаты без Qdrant: пересечение стилей → тот же город → активные
     * с наименьшим in-degree (добивка попутно лечит чужое сиротство).
     *
     * @return array<int,int>
     */
    public function fallbackCandidates(int $brandId, int $limit = self::OUT_DEGREE): array
    {
        $byStyle = $this->db->fetchFirstColumn(
            "SELECT bs2.brand_id FROM brand_style_brand bs1
             JOIN brand_style_brand bs2 ON bs2.brand_style_id = bs1.brand_style_id AND bs2.brand_id != bs1.brand_id
             JOIN brand b ON b.id = bs2.brand_id AND b.status = 'active'
             WHERE bs1.brand_id = :id
             GROUP BY bs2.brand_id ORDER BY COUNT(*) DESC, bs2.brand_id LIMIT " . (int) $limit,
            ['id' => $brandId],
        );

        $byCity = $this->db->fetchFirstColumn(
            "SELECT b2.id FROM brand b1
             JOIN brand b2 ON b2.city = b1.city AND b2.id != b1.id AND b2.status = 'active'
             WHERE b1.id = :id AND b1.city IS NOT NULL AND b1.city != ''
             ORDER BY b2.id LIMIT " . (int) $limit,
            ['id' => $brandId],
        );

        $fill = $this->db->fetchFirstColumn(
            "SELECT b.id FROM brand b
             LEFT JOIN brand_related r ON r.related_brand_id = b.id
             WHERE b.status = 'active' AND b.id != :id
             GROUP BY b.id ORDER BY COUNT(r.id) ASC, b.id LIMIT " . (int) ($limit * 2),
            ['id' => $brandId],
        );

        $merged = [];
        foreach ([$byStyle, $byCity, $fill] as $bucket) {
            foreach ($bucket as $cid) {
                $merged[(int) $cid] = true;
            }
        }

        return array_slice(array_keys($merged), 0, $limit * 2);
    }

    /**
     * Источник ребра для SQL-кандидата (для честной маркировки слабости).
     */
    public function classifyFallback(int $brandId, int $candidateId): string
    {
        $sharesStyle = (bool) $this->db->fetchOne(
            'SELECT 1 FROM brand_style_brand a JOIN brand_style_brand b ON b.brand_style_id = a.brand_style_id
             WHERE a.brand_id = :a AND b.brand_id = :b LIMIT 1',
            ['a' => $brandId, 'b' => $candidateId],
        );
        if ($sharesStyle) {
            return 'style';
        }
        $sameCity = (bool) $this->db->fetchOne(
            "SELECT 1 FROM brand a JOIN brand b ON b.city = a.city AND a.city IS NOT NULL AND a.city != ''
             WHERE a.id = :a AND b.id = :b LIMIT 1",
            ['a' => $brandId, 'b' => $candidateId],
        );

        return $sameCity ? 'city' : 'fill';
    }

    /**
     * Гарантия входящих: врезаем бренд в чужие списки, пока in-degree < MIN_IN.
     * Доноры — те, на кого бренд сам ссылается (взаимность близости), затем
     * SQL-кандидаты. У донора занимаем свободную позицию, иначе вытесняем
     * слабое ребро (fill/city), чей target не станет сиротой.
     *
     * @return int сколько входящих добавлено
     */
    public function ensureIncoming(int $brandId): int
    {
        $need = self::MIN_IN - $this->inDegree($brandId);
        if ($need <= 0) {
            return 0;
        }

        $donors = $this->db->fetchFirstColumn(
            'SELECT related_brand_id FROM brand_related WHERE brand_id = :id ORDER BY position',
            ['id' => $brandId],
        );
        $donors = array_merge($donors, $this->fallbackCandidates($brandId, self::OUT_DEGREE * 2));

        $added = 0;
        $seen  = [];
        foreach ($donors as $donorId) {
            if ($added >= $need) {
                break;
            }
            $donorId = (int) $donorId;
            if ($donorId === $brandId || isset($seen[$donorId])) {
                continue;
            }
            $seen[$donorId] = true;

            $alreadyLinks = (bool) $this->db->fetchOne(
                'SELECT 1 FROM brand_related WHERE brand_id = :donor AND related_brand_id = :target',
                ['donor' => $donorId, 'target' => $brandId],
            );
            if ($alreadyLinks) {
                continue;
            }

            if ($this->outDegree($donorId) < self::OUT_DEGREE) {
                if ($this->addEdges($donorId, [$brandId], $this->classifyFallback($donorId, $brandId)) > 0) {
                    $added++;
                }
                continue;
            }

            // Вытеснение: слабое ребро донора, чей target переживёт потерю входящего
            $victim = $this->db->fetchAssociative(
                'SELECT r.id, r.position FROM brand_related r
                 WHERE r.brand_id = :donor AND r.source IN (:sources)
                   AND (SELECT COUNT(*) FROM brand_related r2 WHERE r2.related_brand_id = r.related_brand_id) > :minIn
                 ORDER BY FIELD(r.source, \'fill\', \'city\'), r.position DESC LIMIT 1',
                ['donor' => $donorId, 'sources' => self::REPLACEABLE, 'minIn' => self::MIN_IN],
                ['sources' => \Doctrine\DBAL\ArrayParameterType::STRING],
            );
            if ($victim === false) {
                continue;
            }
            $this->db->executeStatement(
                'UPDATE brand_related SET related_brand_id = :target, source = :source, created_at = NOW()
                 WHERE id = :id',
                [
                    'target' => $brandId,
                    'source' => $this->classifyFallback($donorId, $brandId),
                    'id'     => $victim['id'],
                ],
            );
            $added++;
        }

        return $added;
    }

    /**
     * Полное вплетение бренда в граф SQL-средствами (publish-tick на проде,
     * fallback в build-команде). Идемпотентно: при полном out-degree и
     * достаточном in-degree — no-op.
     */
    public function weave(int $brandId): void
    {
        if ($this->outDegree($brandId) < self::OUT_DEGREE) {
            foreach ($this->fallbackCandidates($brandId) as $candidateId) {
                if ($this->outDegree($brandId) >= self::OUT_DEGREE) {
                    break;
                }
                $this->addEdges($brandId, [$candidateId], $this->classifyFallback($brandId, $candidateId));
            }
        }
        $this->ensureIncoming($brandId);
    }

    /**
     * Замена рёбер на неактивные бренды (бренд скрыт/удалён → ребро мертво).
     *
     * @return int сколько рёбер заменено
     */
    public function replaceDeadEdges(): int
    {
        // Сторона ИСТОЧНИКА: ребро ИЗ non-active бренда. На рендере не участвует
        // (страница источника отдаёт 404), но искажает inDegree таргета — активный
        // бренд думает, что входящих хватает, хотя одно ведёт с мёртвой страницы.
        // Источник станет active → weave() пересоберёт его исходящие, поэтому удаляем.
        // Делаем первым, чтобы ниже не тратить fallback-подбор на рёбра мёртвых источников.
        $replaced = (int) $this->db->executeStatement(
            "DELETE FROM brand_related
             WHERE brand_id IN (SELECT id FROM brand WHERE status != 'active')",
        );

        // Сторона ТАРГЕТА: ребро НА non-active бренд — заменяем живым кандидатом
        // (источник сохраняет out-degree), а если кандидата нет — удаляем.
        $dead = $this->db->fetchAllAssociative(
            "SELECT r.id, r.brand_id FROM brand_related r
             JOIN brand b ON b.id = r.related_brand_id
             WHERE b.status != 'active'",
        );

        foreach ($dead as $edge) {
            $brandId = (int) $edge['brand_id'];
            $linked  = array_map('intval', $this->db->fetchFirstColumn(
                'SELECT related_brand_id FROM brand_related WHERE brand_id = :id',
                ['id' => $brandId],
            ));
            $candidate = null;
            foreach ($this->fallbackCandidates($brandId) as $cid) {
                if ($cid !== $brandId && !in_array($cid, $linked, true)) {
                    $candidate = $cid;
                    break;
                }
            }
            if ($candidate === null) {
                $this->db->executeStatement('DELETE FROM brand_related WHERE id = :id', ['id' => $edge['id']]);
                $replaced++;
                continue;
            }
            $this->db->executeStatement(
                'UPDATE brand_related SET related_brand_id = :target, source = :source, created_at = NOW()
                 WHERE id = :id',
                [
                    'target' => $candidate,
                    'source' => $this->classifyFallback($brandId, $candidate),
                    'id'     => $edge['id'],
                ],
            );
            $replaced++;
        }

        return $replaced;
    }

    /**
     * Сироты: активные бренды с in-degree < MIN_IN.
     *
     * @return array<int,array{id:int,in_degree:int}>
     */
    public function orphans(): array
    {
        return array_map(
            static fn (array $row) => ['id' => (int) $row['id'], 'in_degree' => (int) $row['in_degree']],
            $this->db->fetchAllAssociative(
                "SELECT b.id, COUNT(r.id) AS in_degree FROM brand b
                 LEFT JOIN brand_related r ON r.related_brand_id = b.id
                 WHERE b.status = 'active'
                 GROUP BY b.id HAVING in_degree < " . self::MIN_IN . ' ORDER BY in_degree, b.id',
            ),
        );
    }
}
