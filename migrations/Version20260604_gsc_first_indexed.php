<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Time-to-index: когда страница бренда ВПЕРВЫЕ замечена в индексе Google.
 * published_at → first_indexed_at = скорость реакции Google на публикацию
 * (метрика «отслеживаем реакцию» для дрипа + IndexNow).
 */
final class Version20260604_gsc_first_indexed extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'gsc_index_status.first_indexed_at — time-to-index метрика';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gsc_index_status ADD first_indexed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE gsc_index_status DROP first_indexed_at');
    }
}
