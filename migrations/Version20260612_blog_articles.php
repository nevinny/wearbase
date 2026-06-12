<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612_blog_articles extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create article table for SEO blog';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS article (
                id INT AUTO_INCREMENT NOT NULL,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                locale VARCHAR(5) DEFAULT 'ru' NOT NULL,
                excerpt LONGTEXT DEFAULT NULL,
                content LONGTEXT NOT NULL,
                published_at DATETIME DEFAULT NULL,
                status VARCHAR(20) DEFAULT 'active' NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                UNIQUE INDEX UNIQ_23A0E66989D9B62 (slug),
                INDEX IDX_article_published (status, locale, published_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS article');
    }
}
