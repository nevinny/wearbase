<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260726_wardrobe_main_style extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add multiple style classifications to wardrobe item facts';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('wardrobe_item', 'main_style')) {
            $this->addSql('ALTER TABLE wardrobe_item ADD COLUMN main_style JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Main style may contain user data and must not be dropped.');
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column],
        ) > 0;
    }
}
