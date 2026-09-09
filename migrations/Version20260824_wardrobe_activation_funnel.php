<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824_wardrobe_activation_funnel extends AbstractMigration
{
    public function getDescription(): string { return 'Repeatable privacy-safe wardrobe activation events'; }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE wardrobe_activation_event ADD dedup_key VARCHAR(64) NOT NULL DEFAULT ''");
        $this->addSql('UPDATE wardrobe_activation_event SET dedup_key = event_type');
        $this->addSql('DROP INDEX uniq_wardrobe_activation_milestone ON wardrobe_activation_event');
        $this->addSql('CREATE UNIQUE INDEX uniq_wardrobe_activation_dedup ON wardrobe_activation_event (profile_subject_id, event_type, dedup_key)');
        $this->addSql("ALTER TABLE wardrobe_activation_event ALTER dedup_key DROP DEFAULT");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_wardrobe_activation_dedup ON wardrobe_activation_event');
        $this->addSql('ALTER TABLE wardrobe_activation_event DROP dedup_key');
        $this->addSql('CREATE UNIQUE INDEX uniq_wardrobe_activation_milestone ON wardrobe_activation_event (profile_subject_id, event_type)');
    }
}
