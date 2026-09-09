<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825_share_reactions extends AbstractMigration
{
    public function getDescription(): string { return 'Add wardrobe_share_reaction — кружковые «огни» (docs/ratings-spec.md §5)'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE wardrobe_share_reaction (id INT AUTO_INCREMENT NOT NULL, share_id INT NOT NULL, member_id INT NOT NULL, kind VARCHAR(16) DEFAULT \'fire\' NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_reaction_feed (share_id), UNIQUE INDEX uniq_share_member_reaction (share_id, member_id), CONSTRAINT fk_reaction_share FOREIGN KEY (share_id) REFERENCES wardrobe_outfit_share (id) ON DELETE CASCADE, CONSTRAINT fk_reaction_member FOREIGN KEY (member_id) REFERENCES wardrobe_circle_member (id) ON DELETE CASCADE, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wardrobe_share_reaction');
    }
}
