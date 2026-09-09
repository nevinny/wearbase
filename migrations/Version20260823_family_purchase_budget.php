<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260823_family_purchase_budget extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Monthly child budgets and estimated purchase request price';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_request ADD estimated_price NUMERIC(12, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_request_event ADD metadata JSON DEFAULT NULL');
        $this->addSql("CREATE TABLE family_budget (id INT AUTO_INCREMENT NOT NULL, subject_id INT NOT NULL, monthly_limit NUMERIC(12, 2) NOT NULL, updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_family_budget_subject (subject_id), CONSTRAINT FK_family_budget_subject FOREIGN KEY (subject_id) REFERENCES client (id) ON DELETE CASCADE, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE family_budget');
        $this->addSql('ALTER TABLE purchase_request_event DROP metadata');
        $this->addSql('ALTER TABLE purchase_request DROP estimated_price');
    }
}
