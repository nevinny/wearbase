<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * landing_lead.brand_name / .website — поля формы заявки лендинга «Размещение под ключ»
 * (/for-brands/placement); остальные источники лида их не заполняют.
 */
final class Version20260719_landing_lead_placement_fields extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'landing_lead: brand_name + website для формы /for-brands/placement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE landing_lead ADD COLUMN brand_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE landing_lead ADD COLUMN website VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE landing_lead DROP COLUMN brand_name');
        $this->addSql('ALTER TABLE landing_lead DROP COLUMN website');
    }
}
