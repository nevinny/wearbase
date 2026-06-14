<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * scheduled_command — расписание кронов в БД (управляется из админки, исполняется
 * единой командой app:cron:run-scheduled из одной строки launchd).
 * Сидим тремя текущими задачами Mac-крона (gsc:sync / report:pipeline / report:daily).
 */
final class Version20260614_scheduled_command extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command — крон в БД + сид трёх текущих задач';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS scheduled_command (
                id INT AUTO_INCREMENT NOT NULL,
                environment VARCHAR(20) NOT NULL DEFAULT 'dev',
                name VARCHAR(120) NOT NULL,
                command VARCHAR(255) NOT NULL,
                schedule VARCHAR(100) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                timeout_sec INT NOT NULL DEFAULT 3600,
                last_run_at DATETIME DEFAULT NULL,
                next_run_at DATETIME DEFAULT NULL,
                last_exit_code INT DEFAULT NULL,
                last_duration_sec INT DEFAULT NULL,
                last_output LONGTEXT DEFAULT NULL,
                running_since DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uq_scheduled_command (command)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO scheduled_command (environment, name, command, schedule, enabled) VALUES
              ('dev', 'Синк GSC (Search Console)',  'app:gsc:sync --no-debug',        '0 8 * * *',    1),
              ('dev', 'RAG-конвейер: тик',          'app:report:pipeline --no-debug', '0 */3 * * *',  1),
              ('dev', 'Дайджест в Telegram',        'app:report:daily --no-debug',    '17 9 * * *',   1)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS scheduled_command');
    }
}
