<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RuSender шлёт событие «Доставлено» — фиксируем delivered_at
 * (воронка sent→delivered→opened→clicked честнее: видно недоставку без bounce).
 */
final class Version20260605_outreach_delivered extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_outreach.delivered_at — подтверждение доставки от SMTP-сервиса';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_outreach ADD delivered_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE brand_outreach DROP delivered_at');
    }
}
