<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Жёсткий граф внутренней перелинковки брендов (brand_related).
 *
 * Принципы (docs/seo_adoption_plan.md, п.2):
 *  - рёбра строятся офлайн и НЕ пересчитываются на запрос — стабильный граф для Google;
 *  - существующие рёбра неприкосновенны: ребро заменяется только если target ушёл из active;
 *  - out-degree переменный (0..OUT_DEGREE) — добиваем только реальными совпадениями,
 *    без farm-like добивки произвольными брендами (тир `fill` убит, решение 2026-07-19,
 *    docs/foreign_brands_policy.md по соседству); MIN_IN — не гарантия, а порог отчёта
 *    для best-effort довязывания (см. ensureIncoming).
 *
 * Источники рёбер по силе: embedding (Qdrant) > style > city.
 * Эмбеддинг-скоринг живёт в BuildLinkGraphCommand (Qdrant есть только в локальной сети);
 * этот сервис — чистый SQL, безопасен на проде (publish-tick).
 */
class BrandLinkGraphService
{
    public const OUT_DEGREE = 12; // исходящих рёбер на бренд (кратно сетке 2/3/4 колонок — ровные ряды на всех брейкпоинтах)
    public const MIN_IN     = 2; // порог отчёта orphans()/best-effort довязывания, не гарантия

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
     * SQL-кандидаты без Qdrant: пересечение стилей → тот же город. Никакой
     * farm-like добивки произвольными брендами (тир `fill` убит, решение
     * 2026-07-19) — если пересечений нет, список может быть короче лимита
     * или пустым, out-degree бренда останется переменным.
     * Кандидаты-таргеты origin_status='foreign' исключены — не линкуем российские
     * бренды на иностранные (docs/foreign_brands_policy.md, решение 2026-07-19).
     *
     * @return array<int,int>
     */
    public function fallbackCandidates(int $brandId, int $limit = self::OUT_DEGREE): array
    {
        $notForeign = "(b.origin_status IS NULL OR b.origin_status != 'foreign')";

        $byStyle = $this->db->fetchFirstColumn(
            "SELECT bs2.brand_id FROM brand_style_brand bs1
             JOIN brand_style_brand bs2 ON bs2.brand_style_id = bs1.brand_style_id AND bs2.brand_id != bs1.brand_id
             JOIN brand b ON b.id = bs2.brand_id AND b.status = 'active' AND $notForeign
             WHERE bs1.brand_id = :id
             GROUP BY bs2.brand_id ORDER BY COUNT(*) DESC, bs2.brand_id LIMIT " . (int) $limit,
            ['id' => $brandId],
        );

        $byCity = $this->db->fetchFirstColumn(
            "SELECT b2.id FROM brand b1
             JOIN brand b2 ON b2.city = b1.city AND b2.id != b1.id AND b2.status = 'active'
             WHERE b1.id = :id AND b1.city IS NOT NULL AND b1.city != '' AND (b2.origin_status IS NULL OR b2.origin_status != 'foreign')
             ORDER BY b2.id LIMIT " . (int) $limit,
            ['id' => $brandId],
        );

        $merged = [];
        foreach ([$byStyle, $byCity] as $bucket) {
            foreach ($bucket as $cid) {
                $merged[(int) $cid] = true;
            }
        }

        return array_slice(array_keys($merged), 0, $limit * 2);
    }

    /**
     * Источник ребра для SQL-кандидата (для честной маркировки слабости).
     * Вызывается только для пар из fallbackCandidates() — там гарантирован
     * общий style либо city, третьего варианта (fill) больше нет.
     */
    public function classifyFallback(int $brandId, int $candidateId): string
    {
        $sharesStyle = (bool) $this->db->fetchOne(
            'SELECT 1 FROM brand_style_brand a JOIN brand_style_brand b ON b.brand_style_id = a.brand_style_id
             WHERE a.brand_id = :a AND b.brand_id = :b LIMIT 1',
            ['a' => $brandId, 'b' => $candidateId],
        );

        return $sharesStyle ? 'style' : 'city';
    }

    /**
     * Best-effort довязывание входящих: пока in-degree < MIN_IN, ищем среди
     * релевантных SQL-кандидатов (style/city) донора со свободным исходящим
     * слотом и добавляем ребро донор→бренд. Никакого форс-вытеснения чужих
     * рёбер и никакой гарантии — если релевантных доноров со свободным слотом
     * нет, бренд остаётся под MIN_IN (тир `fill`, который раньше это гарантировал,
     * убит: farm-like ссылки хуже, чем часть брендов без полного покрытия;
     * решение 2026-07-19).
     *
     * @return int сколько входящих добавлено
     */
    public function ensureIncoming(int $brandId): int
    {
        $need = self::MIN_IN - $this->inDegree($brandId);
        if ($need <= 0) {
            return 0;
        }

        $donors = $this->fallbackCandidates($brandId, self::OUT_DEGREE * 2);

        $added = 0;
        foreach ($donors as $donorId) {
            if ($added >= $need) {
                break;
            }
            $donorId = (int) $donorId;
            if ($donorId === $brandId || $this->outDegree($donorId) >= self::OUT_DEGREE) {
                continue;
            }

            $alreadyLinks = (bool) $this->db->fetchOne(
                'SELECT 1 FROM brand_related WHERE brand_id = :donor AND related_brand_id = :target',
                ['donor' => $donorId, 'target' => $brandId],
            );
            if ($alreadyLinks) {
                continue;
            }

            if ($this->addEdges($donorId, [$brandId], $this->classifyFallback($donorId, $brandId)) > 0) {
                $added++;
            }
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
                'UPDATE brand_related SET related_brand_id = :target, source = :source, created_at = CURRENT_TIMESTAMP
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
