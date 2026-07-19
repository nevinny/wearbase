<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * mechanic_experiment: петля экспериментов над МЕХАНИКАМИ сайта (docs/mechanic_experiments.md).
 * Контур «гипотеза → правка механики → замер → вывод»: app:experiment:propose заводит строку
 * status=proposed (ICE-выбор из бэклога), --start переводит в running со снимком baseline,
 * app:experiment:evaluate после ends_at считает diff-in-diff когорт A/B → measured + рекомендация.
 * Саму Twig-правку MVP не автоматизирует — фиксирует что/где/когда (target) и меряет эффект.
 * Идемпотентно: CREATE TABLE IF NOT EXISTS.
 */
final class Version20260719_mechanic_experiment extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mechanic_experiment — эксперименты над механиками (proposed/running/measured/adopted/rolled_back)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS mechanic_experiment (
                id INT AUTO_INCREMENT NOT NULL,
                code VARCHAR(64) NOT NULL,
                hypothesis TEXT NOT NULL,
                target VARCHAR(255) NOT NULL,
                metric VARCHAR(32) NOT NULL,
                cohort_a JSON NOT NULL,
                cohort_b JSON NOT NULL,
                impact SMALLINT NOT NULL DEFAULT 1,
                confidence SMALLINT NOT NULL DEFAULT 1,
                ease SMALLINT NOT NULL DEFAULT 1,
                ice_score INT NOT NULL DEFAULT 1,
                period_days SMALLINT NOT NULL DEFAULT 21,
                status VARCHAR(16) NOT NULL DEFAULT 'proposed',
                started_at DATETIME DEFAULT NULL,
                ends_at DATETIME DEFAULT NULL,
                result_json JSON DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_mechexp_status (status),
                UNIQUE INDEX uq_mechexp_code (code),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mechanic_experiment');
    }
}
