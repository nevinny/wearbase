<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Крон авторевью PR локальной моделью (app:review:pr), только Mac: команда ходит в
 * GitHub через авторизованный там `gh` и в ollama в LAN — на проде ни того, ни другого.
 *
 * Каждые 10 минут, по 2 PR за прогон: PR появляются пачками во время сессий, а один
 * дифф на gemma4:26b занимает GPU на минуту-полторы. Повторов нет — маркер
 * local-review:<sha> в комментарии; параллельный запуск отсекает flock.
 * Идемпотентно: INSERT IGNORE (uniq по command).
 */
final class Version20260727_review_pr_cron extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: крон app:review:pr (авторевью PR локальной моделью, Mac)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO scheduled_command (name, command, schedule, enabled, environment)
            VALUES ('Авторевью PR (локальная LLM)', 'app:review:pr --limit=2 --no-debug', '*/10 * * * *', 1, 'dev')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE command = 'app:review:pr --limit=2 --no-debug'");
    }
}
