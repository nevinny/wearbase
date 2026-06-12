<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Таблица подписчиков рассылки с double opt-in и отпиской (soft-delete через
 * unsubscribed_at). Заменяет сбор «подписок» в landing_lead из формы футера.
 */
final class Version20260612_newsletter_subscriber extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create newsletter_subscriber table (double opt-in + unsubscribe)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS newsletter_subscriber (
                id INT AUTO_INCREMENT NOT NULL,
                email VARCHAR(255) NOT NULL,
                source VARCHAR(50) DEFAULT NULL,
                confirm_token VARCHAR(64) NOT NULL,
                unsubscribe_token VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                confirmed_at DATETIME DEFAULT NULL,
                unsubscribed_at DATETIME DEFAULT NULL,
                UNIQUE INDEX UNIQ_ns_email (email),
                UNIQUE INDEX UNIQ_ns_confirm_token (confirm_token),
                UNIQUE INDEX UNIQ_ns_unsubscribe_token (unsubscribe_token),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS newsletter_subscriber');
    }
}
