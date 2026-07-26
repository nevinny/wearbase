<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260726_wardrobe_styles_multiselect extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Upgrade wardrobe style from one value to a JSON multi-select';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('wardrobe_item', 'main_style')) {
            $this->addSql('ALTER TABLE wardrobe_item ADD COLUMN main_style JSON DEFAULT NULL');
            return;
        }

        // Совместимость с короткой локальной версией поля VARCHAR: одиночное
        // значение превращается в массив, JSON-массивы остаются без изменений.
        $this->addSql(
            'UPDATE wardrobe_item SET main_style = JSON_ARRAY(main_style)
             WHERE main_style IS NOT NULL AND JSON_VALID(main_style) = 0',
        );
        $this->addSql('ALTER TABLE wardrobe_item MODIFY main_style JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Multiple selected styles cannot safely be reduced to one value.');
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
