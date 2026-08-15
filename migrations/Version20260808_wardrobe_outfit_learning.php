<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808_wardrobe_outfit_learning extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Сохранённые AI-образы и реакции для персонализации рекомендаций';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE wardrobe_outfit (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, wardrobe_owner_id INT NOT NULL, prompt VARCHAR(300) NOT NULL, title VARCHAR(100) NOT NULL, explanation LONGTEXT DEFAULT NULL, items JSON NOT NULL, reaction VARCHAR(12) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', reacted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_F1B52867A76ED395 (user_id), INDEX IDX_F1B52867BF86871F (wardrobe_owner_id), INDEX idx_wardrobe_outfit_user_created (user_id, created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE wardrobe_outfit ADD CONSTRAINT FK_F1B52867A76ED395 FOREIGN KEY (user_id) REFERENCES client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wardrobe_outfit ADD CONSTRAINT FK_F1B52867BF86871F FOREIGN KEY (wardrobe_owner_id) REFERENCES client (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wardrobe_outfit');
    }
}
