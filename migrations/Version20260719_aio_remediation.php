<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * aio_remediation: кандидаты авто-ремедиации AIO-утечки (docs/seo_sitewide_backlog.md
 * HIGH#2, docs/drmax_seo_2026_digest.md §5). app:seo:aio-remediate детектит утечку по
 * gsc_query_stats (impr≥N, clicks=0, группа brand_entity «чей бренд»), матчит запрос на
 * бренд, генерит grounded Q/A и кладёт сюда status=pending — запись в brand_faq только
 * по клику admin-кнопки в Telegram (aioapply:<id>/aioreject:<id>, TelegramController).
 * brand.id — signed INT (см. CLAUDE.md, UNSIGNED-нюанс country тут неактуален).
 */
final class Version20260719_aio_remediation extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'aio_remediation — кандидаты авто-ремедиации AIO-утечки (pending/applied/rejected)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS aio_remediation (
                id INT AUTO_INCREMENT NOT NULL,
                brand_id INT DEFAULT NULL,
                query VARCHAR(255) NOT NULL,
                kind VARCHAR(16) NOT NULL DEFAULT 'faq',
                proposed_question VARCHAR(255) NOT NULL,
                proposed_answer TEXT NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                applied_at DATETIME DEFAULT NULL,
                INDEX idx_aio_remediation_status (status),
                INDEX idx_aio_remediation_brand (brand_id),
                PRIMARY KEY (id),
                CONSTRAINT FK_aio_remediation_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS aio_remediation');
    }
}
