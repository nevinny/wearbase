<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * seo_tech_finding — открытые и закрытые нарушения тех-чек-листа (app:seo:tech-audit).
 * Смысл таблицы — ДЕЛЬТА между прогонами: без неё еженедельный отчёт каждый раз
 * присылал бы один и тот же список, и «появилось новое» терялось бы в шуме.
 *
 * Soft-delete по правилам проекта: исправленное не удаляется, а помечается fixed_on —
 * так видно историю (что уже ловили и починили). Повторное появление сбрасывает
 * fixed_on в NULL и обновляет first_seen_on (см. SeoTechAuditCommand::persistFindings).
 *
 * `rule` — короткий код правила из SeoTechAuditCommand::RULES (label/severity живут
 * в коде: правила меняются вместе с проверками, справочник в БД был бы вторым источником).
 *
 * Крон: суббота — полный обход (гайд просит полный тех-аудит раз в неделю).
 */
final class Version20260728_seo_tech_finding extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'seo_tech_finding — findings тех-аудита с дельтой (+ крон app:seo:tech-audit, сб)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS seo_tech_finding (
                id INT AUTO_INCREMENT NOT NULL,
                url VARCHAR(512) NOT NULL COMMENT 'путь без домена, как его видел обход',
                rule VARCHAR(40) NOT NULL COMMENT 'код правила из SeoTechAuditCommand::RULES',
                detail VARCHAR(255) DEFAULT NULL,
                first_seen_on DATE NOT NULL,
                last_seen_on DATE NOT NULL,
                fixed_on DATE DEFAULT NULL COMMENT 'NULL = нарушение открыто; дата = исправлено (soft-delete)',
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_seo_tech_finding (url(191), rule),
                INDEX idx_seo_tech_finding_open (fixed_on, rule)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled)
            VALUES ('dev', 'SEO: тех-аудит сайта (чек-лист + сироты)', 'app:seo:tech-audit --notify --no-debug', '30 7 * * 6', 1)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE name = 'SEO: тех-аудит сайта (чек-лист + сироты)'");
        $this->addSql('DROP TABLE IF EXISTS seo_tech_finding');
    }
}
