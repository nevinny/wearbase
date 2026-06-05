<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Стадия краула сайта бренда (отдельный поток между discover и fetch):
 *  - crawl_status: NULL=не краулили | done | skipped (нет own_site) | failed
 *  - crawled_at: когда развернули sitemap own_site в очередь own_page
 * Дизайн: tasktracker «полный краул сайтов брендов».
 */
final class Version20260605_brand_crawl extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_rag_pipeline: crawl_status + crawled_at (стадия краула сайта)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE brand_rag_pipeline
                ADD crawl_status VARCHAR(12) DEFAULT NULL COMMENT 'NULL=не краулили|done|skipped|failed',
                ADD crawled_at   DATETIME DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_rag_pipeline DROP crawl_status, DROP crawled_at');
    }
}
