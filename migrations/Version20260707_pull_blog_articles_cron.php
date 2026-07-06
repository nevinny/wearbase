<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Крон синка статей блога прод→Mac (app:blog:pull-articles): оживляет closed-loop
 * «статья проиндексирована → уведомление "публикуй в Дзен"» — до 06:00 (app:gsc:sync).
 */
final class Version20260707_pull_blog_articles_cron extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: крон app:blog:pull-articles (ежедневно 05:30, Mac)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO scheduled_command (name, command, schedule, enabled)
            VALUES ('Синк статей блога (прод→Mac)', 'app:blog:pull-articles --no-debug', '30 5 * * *', 1)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE name = 'Синк статей блога (прод→Mac)'");
    }
}
