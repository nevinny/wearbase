<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Email-активация владельцев брендов (дизайн 5 агентов, tasktracker 2026-06-05):
 * одна широкая строка на бренд — отправка/открытия/клики/отписка/bounce.
 * Suppression — ПО EMAIL (INDEX не unique: один владелец = несколько брендов).
 * bounced_at = ТОЛЬКО hard bounce; soft → last_error (retryable).
 */
final class Version20260605_brand_outreach extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_outreach — воронка email-активации владельцев';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_outreach (
                id INT AUTO_INCREMENT NOT NULL,
                brand_id INT NOT NULL,
                email VARCHAR(255) NOT NULL COMMENT 'снимок адреса на момент отправки',
                send_token CHAR(32) NOT NULL COMMENT 'bin2hex(random_bytes(16)) — ключ pixel/click/unsub',
                sent_at DATETIME DEFAULT NULL,
                first_opened_at DATETIME DEFAULT NULL,
                open_count INT NOT NULL DEFAULT 0,
                first_clicked_at DATETIME DEFAULT NULL,
                click_count INT NOT NULL DEFAULT 0,
                unsubscribed_at DATETIME DEFAULT NULL,
                bounced_at DATETIME DEFAULT NULL COMMENT 'ТОЛЬКО hard bounce = suppression',
                attempts INT NOT NULL DEFAULT 0,
                last_error VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_outreach_brand (brand_id),
                UNIQUE INDEX uniq_outreach_token (send_token),
                INDEX idx_outreach_email (email),
                INDEX idx_outreach_sent_at (sent_at),
                CONSTRAINT fk_outreach_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS brand_outreach');
    }
}
