<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand_outbound_click — учёт исходящих переходов посетителей на ссылки бренда
 * (сайт / соцсети / маркетплейсы) через редирект-прокладку /go/{id}.
 *
 * Зачем: единственная защищаемая ценность каталога-агрегатора в эпоху AI-выдачи —
 * ДОКАЗУЕМЫЙ реферальный клик к бренду (метрика удержания подписки + опцион на
 * transaction/affiliate-монетизацию). Append-only лог + денормализованный счётчик
 * brand.outbound_click_count для дешёвой сортировки по популярности.
 *
 * Идемпотентно: CREATE TABLE IF NOT EXISTS + ADD COLUMN только если столбца ещё нет
 * (MySQL не поддерживает ADD COLUMN IF NOT EXISTS).
 */
final class Version20260627_brand_outbound_click extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_outbound_click — лог исходящих кликов на ссылки бренда + brand.outbound_click_count';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_outbound_click (
                id INT AUTO_INCREMENT NOT NULL,
                brand_id INT NOT NULL,
                brand_link_id INT DEFAULT NULL,
                link_type VARCHAR(32) DEFAULT NULL,
                target_host VARCHAR(255) DEFAULT NULL,
                locale VARCHAR(8) DEFAULT NULL,
                referer VARCHAR(255) DEFAULT NULL,
                ua_hash CHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_boc_brand_created (brand_id, created_at),
                INDEX idx_boc_created (created_at),
                INDEX idx_boc_link (brand_link_id),
                CONSTRAINT fk_boc_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE,
                CONSTRAINT fk_boc_link FOREIGN KEY (brand_link_id) REFERENCES brand_link (id) ON DELETE SET NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'brand' AND column_name = 'outbound_click_count'",
        );
        if ($exists === 0) {
            $this->addSql('ALTER TABLE brand ADD COLUMN outbound_click_count INT NOT NULL DEFAULT 0');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS brand_outbound_click');
        $this->addSql('ALTER TABLE brand DROP COLUMN outbound_click_count');
    }
}
