<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Крон зеркалирования статусов публикации прод→Mac (app:brand:publish-sync):
 * прод-дрип флипает публикации НА ПРОДЕ, назад в Mac это не зеркалится, и
 * Mac-статусы/`published_at` со временем врут. Два раза в день: 08:45 — перед
 * утренней аналитикой (дайджест/советник/gap-лист видят свежие статусы),
 * 21:45 — закрыть дневные публикации дрипа.
 */
final class Version20260803_brand_publish_sync_cron extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: крон app:brand:publish-sync (прод→Mac, 08:45 и 21:45)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO scheduled_command (name, command, schedule, enabled)
            VALUES ('Синк статусов публикации (прод→Mac)',
                    'app:brand:publish-sync --no-debug',
                    '45 8,21 * * *', 1)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command = 'app:brand:publish-sync --no-debug'");
    }
}
