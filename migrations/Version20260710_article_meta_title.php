<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * article.meta_title — SEO-title для <title>/og:title (приём Т—Ж: «Топ-N лучших …
 * {год} года» при человеческом H1 без года в article.title). Nullable: старые
 * статьи без meta_title, шаблон блога делает fallback на title.
 *
 * Идемпотентно: колонка добавляется только если её ещё нет.
 */
final class Version20260710_article_meta_title extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'article.meta_title — SEO-title с годом (Т—Ж), H1 остаётся без года';
    }

    public function up(Schema $schema): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'article' AND column_name = 'meta_title'",
        );
        if ($exists === 0) {
            $this->addSql('ALTER TABLE article ADD meta_title VARCHAR(255) DEFAULT NULL AFTER title');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP COLUMN meta_title');
    }
}
