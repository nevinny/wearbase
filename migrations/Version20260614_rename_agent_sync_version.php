<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand.content_version → brand.agent_sync_version.
 * Имя сбивало с толку: это sequence-номер доставки в агент-API (защита от ре-доставки),
 * а НЕ версионирование контента (для истории текста — таблица brand_content_revision).
 */
final class Version20260614_rename_agent_sync_version extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand.content_version → agent_sync_version (снять путаницу с версиями контента)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand CHANGE content_version agent_sync_version INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand CHANGE agent_sync_version content_version INT NOT NULL DEFAULT 0');
    }
}
