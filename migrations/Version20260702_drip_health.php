<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * drip_health — единственная строка (id=1): сигнал здоровья индексации для дрип-публикатора.
 * Mac-синк Яндекс.Вебмастера считает multiplier по динамике yandex_history (усваивает ли Яндекс
 * новые страницы) и пушит сюда через agent-API /api/v1/drip-health. PublishTickCommand (прод)
 * читает и троттлит темп. Решает Mac↔прод разрыв: сенсор (Яндекс-данные) на Mac, дрип — на проде.
 */
final class Version20260702_drip_health extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'drip_health — сигнал здоровья индексации (Яндекс) для дрипа, Mac→прод';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS drip_health (
                id INT NOT NULL,
                multiplier DECIMAL(3,2) NOT NULL DEFAULT 1.00,
                pages_in_search INT DEFAULT NULL,
                note VARCHAR(255) DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS drip_health');
    }
}
