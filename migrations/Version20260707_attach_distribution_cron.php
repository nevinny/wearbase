<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Крон-подстраховка app:seo:attach-distribution (без аргумента — все площадки,
 * авто-обнаружение по var/seo/**\/*.md). app:seo:publish-blog уже привязывает
 * копии сразу при публикации, но копии под площадку часто дописывают ПОСЛЕ того,
 * как блог-оригинал уже live (генерация Дзен-варианта догоняет) — без крона такие
 * поздние файлы годами лежали бы непривязанными. 05:40 — через 10 мин после
 * «Синк статей блога (прод→Mac)» (05:30), чтобы source_file свежих статей уже был
 * на месте.
 */
final class Version20260707_attach_distribution_cron extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'scheduled_command: крон app:seo:attach-distribution (ежедневно 05:40, Mac)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO scheduled_command (name, command, schedule, enabled)
            VALUES ('Привязка копий статей под площадки', 'app:seo:attach-distribution --no-debug', '40 5 * * *', 1)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM scheduled_command WHERE name = 'Привязка копий статей под площадки'");
    }
}
