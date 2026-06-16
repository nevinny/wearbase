<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Соц-автоматизация (Фаза 1): social_channel / social_post / social_post_metric.
 * Статус-машина и очередь публикации (claimDue, FOR UPDATE SKIP LOCKED).
 * См. docs/marketing_instagram.md.
 */
final class Version20260617_social extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Соц-автоматизация: social_channel / social_post / social_post_metric';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS social_channel (
                id INT AUTO_INCREMENT NOT NULL,
                platform VARCHAR(8) NOT NULL DEFAULT 'tg',
                name VARCHAR(120) NOT NULL DEFAULT '',
                target VARCHAR(190) NOT NULL DEFAULT '',
                token_enc LONGTEXT DEFAULT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                egress_host VARCHAR(8) NOT NULL DEFAULT 'mac',
                launch_date DATE DEFAULT NULL,
                rate_start INT DEFAULT NULL,
                rate_cap INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS social_post (
                id INT AUTO_INCREMENT NOT NULL,
                channel_id INT NOT NULL,
                brand_id INT DEFAULT NULL,
                rubric VARCHAR(40) NOT NULL DEFAULT '',
                status VARCHAR(20) NOT NULL DEFAULT 'planned',
                caption LONGTEXT DEFAULT NULL,
                media_type VARCHAR(20) NOT NULL DEFAULT 'none',
                media_path VARCHAR(255) DEFAULT NULL,
                ai_generated TINYINT(1) NOT NULL DEFAULT 0,
                scheduled_at DATETIME DEFAULT NULL,
                published_at DATETIME DEFAULT NULL,
                external_id VARCHAR(190) DEFAULT NULL,
                claimed_at DATETIME DEFAULT NULL,
                priority INT NOT NULL DEFAULT 0,
                generate_attempts INT NOT NULL DEFAULT 0,
                publish_attempts INT NOT NULL DEFAULT 0,
                last_error LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sp_status (status),
                INDEX idx_sp_sched (status, scheduled_at),
                INDEX idx_sp_channel (channel_id),
                INDEX idx_sp_brand (brand_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_social_post_channel FOREIGN KEY (channel_id) REFERENCES social_channel (id) ON DELETE CASCADE,
                CONSTRAINT FK_social_post_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE=InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS social_post_metric (
                id INT AUTO_INCREMENT NOT NULL,
                post_id INT NOT NULL,
                reach INT NOT NULL DEFAULT 0,
                saves INT NOT NULL DEFAULT 0,
                shares INT NOT NULL DEFAULT 0,
                link_taps INT NOT NULL DEFAULT 0,
                likes INT NOT NULL DEFAULT 0,
                comments INT NOT NULL DEFAULT 0,
                measured_at DATETIME NOT NULL,
                INDEX idx_spm_post (post_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_social_metric_post FOREIGN KEY (post_id) REFERENCES social_post (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE=InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS social_post_metric');
        $this->addSql('DROP TABLE IF EXISTS social_post');
        $this->addSql('DROP TABLE IF EXISTS social_channel');
    }
}
