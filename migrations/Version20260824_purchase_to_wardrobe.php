<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_purchase_to_wardrobe extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link bought purchase position to exactly one wardrobe item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_request_item ADD wardrobe_item_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_request_item ADD CONSTRAINT FK_PURCHASE_ITEM_WARDROBE FOREIGN KEY (wardrobe_item_id) REFERENCES wardrobe_item (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_PURCHASE_ITEM_WARDROBE ON purchase_request_item (wardrobe_item_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_request_item DROP FOREIGN KEY FK_PURCHASE_ITEM_WARDROBE');
        $this->addSql('DROP INDEX UNIQ_PURCHASE_ITEM_WARDROBE ON purchase_request_item');
        $this->addSql('ALTER TABLE purchase_request_item DROP wardrobe_item_id');
    }
}
