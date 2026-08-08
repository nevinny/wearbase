<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260808_child_profile extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Optional child profile fields for family wardrobe onboarding';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE client
            ADD gender VARCHAR(20) DEFAULT NULL,
            ADD height_cm INT DEFAULT NULL,
            ADD clothing_size VARCHAR(20) DEFAULT NULL,
            ADD shoe_size VARCHAR(10) DEFAULT NULL,
            ADD profile_notes LONGTEXT DEFAULT NULL,
            ADD profile_completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client
            DROP gender,
            DROP height_cm,
            DROP clothing_size,
            DROP shoe_size,
            DROP profile_notes,
            DROP profile_completed_at');
    }
}
