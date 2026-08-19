<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260819_user_tryon_photos extends AbstractMigration
{
    public function getDescription(): string { return 'Store reusable selfie and full-body photos for virtual try-on'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD tryon_selfie VARCHAR(255) DEFAULT NULL, ADD tryon_full_body_photo VARCHAR(255) DEFAULT NULL, ADD tryon_photo_consent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP tryon_selfie, DROP tryon_full_body_photo, DROP tryon_photo_consent_at');
    }
}
