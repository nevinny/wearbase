<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525174000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make payment.subscription_id nullable to allow order payments without subscription';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment CHANGE subscription_id subscription_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment CHANGE subscription_id subscription_id INT NOT NULL');
    }
}
