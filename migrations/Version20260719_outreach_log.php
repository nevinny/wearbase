<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * outreach_log — трекинг campaign-level касаний бренда, отдельно от brand_outreach
 * (та таблица = 1 строка на бренд, жёстко привязана к активационному письму
 * BrandOutreachMailer/app:outreach:send). Здесь — генерические (brand_id, type)
 * пары: сейчас только warm_offer_draft (app:outreach:warm-refresh), в будущем
 * можно добавить другие campaign-type без миграции схемы.
 */
final class Version20260719_outreach_log extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'outreach_log — трекинг кампаний по бренду (brand_id, type), старт: warm_offer_draft';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS outreach_log (
                id INT AUTO_INCREMENT NOT NULL,
                brand_id INT NOT NULL,
                type VARCHAR(32) NOT NULL COMMENT 'warm_offer_draft и т.д.',
                status VARCHAR(16) NOT NULL DEFAULT 'drafted' COMMENT 'drafted|sent|skipped',
                sent_at DATETIME DEFAULT NULL COMMENT 'NULL — драфт ещё не отправлен вручную',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_outreach_log (brand_id, type),
                INDEX idx_outreach_log_type (type, created_at),
                CONSTRAINT fk_outreach_log_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS outreach_log');
    }
}
