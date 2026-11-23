<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Утренний дайджест (docs/commands.md «Утренний дайджест»): информационные кроны Mac
 * (environment='dev') переходят с отдельных TG-сообщений на --digest (секция во входящих),
 * app:report:daily собирает всё одним заходом в 09:30, недельный отчёт — в 09:15 пн.
 *
 * Строки матчим по (environment, command LIKE), НЕ по id — id на Mac и проде разные.
 * Идемпотентно: --digest добавляется только туда, где его нет; расписание меняется только со
 * старого значения. next_run_at обнуляем у строк со сменой расписания: иначе догон
 * диспетчера (next_run_at в прошлом) запустил бы их ещё раз по старому времени.
 */
final class Version20261123_morning_digest_schedule extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: утренний дайджест — --digest у информационных кронов, daily 09:30, weekly пн 09:15, yandex:sync без --report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE scheduled_command SET schedule = '30 9 * * *', next_run_at = NULL WHERE environment = 'dev' AND command LIKE 'app:report:daily%' AND schedule = '10 9 * * *'");
        $this->addSql("UPDATE scheduled_command SET schedule = '15 9 * * 1', next_run_at = NULL WHERE environment = 'dev' AND command LIKE 'app:report:weekly%' AND schedule = '0 10 * * 1'");

        foreach (['app:report:weekly', 'app:advisor:tick', 'app:outreach:warm-refresh'] as $cmd) {
            $this->addSql("UPDATE scheduled_command SET command = REPLACE(command, '{$cmd}', '{$cmd} --digest') WHERE environment = 'dev' AND command LIKE '{$cmd}%' AND command NOT LIKE '%--digest%'");
        }
        foreach (['app:competitor:watch', 'app:seo:gap-report', 'app:seo:aio-remediate', 'app:social:evaluate'] as $cmd) {
            $this->addSql("UPDATE scheduled_command SET command = REPLACE(command, '--notify', '--digest') WHERE environment = 'dev' AND command LIKE '{$cmd}%' AND command LIKE '%--notify%'");
        }

        $this->addSql("UPDATE scheduled_command SET command = REPLACE(command, ' --report', '') WHERE environment = 'dev' AND command LIKE 'app:yandex:sync%' AND command LIKE '% --report%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE scheduled_command SET schedule = '10 9 * * *', next_run_at = NULL WHERE environment = 'dev' AND command LIKE 'app:report:daily%' AND schedule = '30 9 * * *'");
        $this->addSql("UPDATE scheduled_command SET schedule = '0 10 * * 1', next_run_at = NULL WHERE environment = 'dev' AND command LIKE 'app:report:weekly%' AND schedule = '15 9 * * 1'");

        foreach (['app:report:weekly', 'app:advisor:tick', 'app:outreach:warm-refresh'] as $cmd) {
            $this->addSql("UPDATE scheduled_command SET command = REPLACE(command, ' --digest', '') WHERE environment = 'dev' AND command LIKE '{$cmd}%' AND command LIKE '% --digest%'");
        }
        foreach (['app:competitor:watch', 'app:seo:gap-report', 'app:seo:aio-remediate', 'app:social:evaluate'] as $cmd) {
            $this->addSql("UPDATE scheduled_command SET command = REPLACE(command, '--digest', '--notify') WHERE environment = 'dev' AND command LIKE '{$cmd}%' AND command LIKE '%--digest%'");
        }

        $this->addSql("UPDATE scheduled_command SET command = REPLACE(command, 'app:yandex:sync', 'app:yandex:sync --report') WHERE environment = 'dev' AND command LIKE 'app:yandex:sync%' AND command NOT LIKE '%--report%'");
    }
}
