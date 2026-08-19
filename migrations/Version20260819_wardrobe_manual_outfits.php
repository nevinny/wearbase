<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260819_wardrobe_manual_outfits extends AbstractMigration
{
    public function getDescription(): string { return 'Saved manual wardrobe outfit collages'; }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE IF NOT EXISTS wardrobe_manual_outfit (id INT AUTO_INCREMENT NOT NULL, created_by_id INT NOT NULL, wardrobe_owner_id INT NOT NULL, title VARCHAR(100) NOT NULL, layout JSON NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', deleted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_manual_outfit_owner_deleted (wardrobe_owner_id, deleted_at), INDEX idx_manual_outfit_created_by (created_by_id), CONSTRAINT fk_manual_outfit_created_by FOREIGN KEY (created_by_id) REFERENCES client (id) ON DELETE CASCADE, CONSTRAINT fk_manual_outfit_owner FOREIGN KEY (wardrobe_owner_id) REFERENCES client (id) ON DELETE CASCADE, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE TABLE IF NOT EXISTS wardrobe_manual_outfit_item (wardrobe_manual_outfit_id INT NOT NULL, wardrobe_item_id INT NOT NULL, INDEX idx_manual_outfit_item_outfit (wardrobe_manual_outfit_id), INDEX idx_manual_outfit_item_item (wardrobe_item_id), CONSTRAINT fk_manual_outfit_item_outfit FOREIGN KEY (wardrobe_manual_outfit_id) REFERENCES wardrobe_manual_outfit (id) ON DELETE CASCADE, CONSTRAINT fk_manual_outfit_item_item FOREIGN KEY (wardrobe_item_id) REFERENCES wardrobe_item (id) ON DELETE CASCADE, PRIMARY KEY(wardrobe_manual_outfit_id, wardrobe_item_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void { throw new IrreversibleMigration('Saved user collages must not be deleted.'); }
}
