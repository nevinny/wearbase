<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Баг: gsc_page_stats копил дубли на каждый ре-синк (13-17x на пару page_url+day,
 * SUM(impressions) завышен в те же разы). Причина — старый UNIQUE(page_url(191), day, query):
 * `query` в этой таблице ВСЕГДА NULL (агрегат по странице, см. Version20260604_gsc), а MySQL
 * трактует NULL как «неравно самому себе» в уникальных индексах → ON DUPLICATE KEY UPDATE
 * в SyncGscCommand::syncAnalytics() никогда не матчился, каждый прогон плодил новые строки.
 *
 * Фикс:
 *  1. Дедуп существующих строк — оставляем max(id) на пару (page_url, day) (физический DELETE
 *     системной чистки, допустим по CLAUDE.md).
 *  2. UNIQUE(page_url, day) вместо UNIQUE(page_url, day, query) — раз query здесь не варьируется,
 *     ключ идемпотентности синка должен игнорировать эту колонку.
 *
 * Идемпотентно: information_schema-guard (MySQL не поддерживает ADD/DROP INDEX IF NOT EXISTS).
 */
final class Version20260719_gsc_page_stats_dedup extends AbstractMigration
{
    private const TABLE     = 'gsc_page_stats';
    private const OLD_INDEX = 'uniq_gsc';
    private const NEW_INDEX = 'uniq_gsc_page_day';

    public function getDescription(): string
    {
        return 'gsc_page_stats: дедуп дублей + UNIQUE(page_url, day) вместо UNIQUE(page_url, day, query)';
    }

    public function up(Schema $schema): void
    {
        // 1) дедуп: оставляем строку с максимальным id на пару (page_url, day)
        $this->addSql(<<<'SQL'
            DELETE g1 FROM gsc_page_stats g1
            INNER JOIN gsc_page_stats g2
                ON g1.page_url = g2.page_url AND g1.day = g2.day AND g1.id < g2.id
        SQL);

        // 2) старый UNIQUE(page_url, day, query) мешает upsert'у (query всегда NULL) — дропаем
        $hasOldIndex = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i',
            ['t' => self::TABLE, 'i' => self::OLD_INDEX],
        );
        if ($hasOldIndex > 0) {
            $this->addSql('ALTER TABLE ' . self::TABLE . ' DROP INDEX ' . self::OLD_INDEX);
        }

        // 3) новый UNIQUE(page_url, day) — ключ идемпотентности ON DUPLICATE KEY UPDATE
        $hasNewIndex = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i',
            ['t' => self::TABLE, 'i' => self::NEW_INDEX],
        );
        if ($hasNewIndex === 0) {
            $this->addSql('CREATE UNIQUE INDEX ' . self::NEW_INDEX . ' ON ' . self::TABLE . ' (page_url(191), day)');
        }
    }

    public function down(Schema $schema): void
    {
        // дедуп-DELETE необратим (системная чистка) — восстанавливаем только структуру индекса
        $hasNewIndex = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i',
            ['t' => self::TABLE, 'i' => self::NEW_INDEX],
        );
        if ($hasNewIndex > 0) {
            $this->addSql('DROP INDEX ' . self::NEW_INDEX . ' ON ' . self::TABLE);
        }

        $hasOldIndex = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i',
            ['t' => self::TABLE, 'i' => self::OLD_INDEX],
        );
        if ($hasOldIndex === 0) {
            $this->addSql('CREATE UNIQUE INDEX ' . self::OLD_INDEX . ' ON ' . self::TABLE . ' (page_url(191), day, query)');
        }
    }
}
