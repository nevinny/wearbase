<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608_pipeline_content_changed extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add content_changed_at to brand_rag_pipeline (re-push trigger for post-push enrichment)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_rag_pipeline ADD content_changed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_rag_pipeline DROP content_changed_at');
    }
}
