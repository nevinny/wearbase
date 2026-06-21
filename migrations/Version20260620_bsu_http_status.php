<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand_source_url.http_status — http-код последнего фетча для триажа пустых фетчей
 * (403/429 бан · 404 мёртв · 200 JS-пусто · null недоступен).
 */
final class Version20260620_bsu_http_status extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_source_url.http_status — диагностика пустых фетчей';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_source_url ADD COLUMN http_status SMALLINT UNSIGNED DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_source_url DROP COLUMN http_status');
    }
}
