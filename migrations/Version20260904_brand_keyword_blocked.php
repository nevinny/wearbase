<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand_keyword.blocked_at / blocked_reason — метка «фраза отсеяна минус-словами».
 *
 * Зачем: Wordstat у неоднозначных имён отдаёт порно/пиратский шум («murka onlyfans»,
 * «alena bevza порно»), а фильтр релевантности его пропускает — имя бренда в фразе
 * есть. Строки помечаем, а не удаляем: правило проекта — только soft-delete, список
 * минус-слов правится (env KEYWORD_STOPWORDS), и по метке видно, что он отсёк.
 */
final class Version20260904_brand_keyword_blocked extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'brand_keyword.blocked_at/blocked_reason — soft-метка мусорных фраз';
    }

    public function up(Schema $schema): void
    {
        // ADD COLUMN IF NOT EXISTS в MySQL нет (это MariaDB) — идемпотентность через
        // введённую Doctrine схему, то есть introspection текущей БД.
        $this->skipIf(
            $schema->getTable('brand_keyword')->hasColumn('blocked_at'),
            'brand_keyword.blocked_at уже существует',
        );

        $this->addSql('ALTER TABLE brand_keyword ADD blocked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE brand_keyword ADD blocked_reason VARCHAR(64) DEFAULT NULL');
        // Читающие выборки идут по (brand_id, blocked_at IS NULL)
        $this->addSql('CREATE INDEX idx_bkw_brand_blocked ON brand_keyword (brand_id, blocked_at)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('brand_keyword')->hasColumn('blocked_at'),
            'brand_keyword.blocked_at отсутствует',
        );

        $this->addSql('DROP INDEX idx_bkw_brand_blocked ON brand_keyword');
        $this->addSql('ALTER TABLE brand_keyword DROP blocked_reason');
        $this->addSql('ALTER TABLE brand_keyword DROP blocked_at');
    }
}
