<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * app:brand:publish-tick перестал спать (sleep до 45 мин) ВНУТРИ команды — джиттер теперь
 * решается через var/publish_tick_state.json (PublishTickJitter), а не блокирующим sleep,
 * который держал глобальный флок диспетчера app:cron:run-scheduled на весь час и вставал
 * поперёк всех прочих кронов (ежеминутная доставка писем, health-гейт деплоя). Расписание в
 * scheduled_command переводим с часового на пятиминутное — каждый тик теперь мгновенный,
 * публикация внутри часа по-прежнему ровно одна.
 */
final class Version20260830_publish_tick_five_minute_schedule extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: app:brand:publish-tick с 0 * * * * на */5 * * * * (джиттер без sleep)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE scheduled_command SET schedule = '*/5 * * * *' WHERE command LIKE 'app:brand:publish-tick%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE scheduled_command SET schedule = '0 * * * *' WHERE command LIKE 'app:brand:publish-tick%'");
    }
}
