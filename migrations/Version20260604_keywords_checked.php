<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Исход опроса Wordstat по бренду, чтобы не переопрашивать и не жечь часовую квоту:
 *  - keywords_status: NULL = никогда не опрашивали | found = нашли фразы | not_found = опросили, 0 фраз
 *  - keywords_checked_at: когда опрашивали
 */
final class Version20260604_keywords_checked extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_rag_pipeline: keywords_status (never/found/not_found) + keywords_checked_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE brand_rag_pipeline
                ADD keywords_status     VARCHAR(12) DEFAULT NULL COMMENT 'NULL=никогда|found|not_found',
                ADD keywords_checked_at DATETIME DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_rag_pipeline DROP keywords_status, DROP keywords_checked_at');
    }
}
