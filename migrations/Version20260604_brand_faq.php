<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FAQ-пары брендов (SEO задача C): вопросы из Wordstat-фраз, grounded-ответы 27b.
 * Рендер: аккордеон + FAQPage JSON-LD на странице бренда.
 * brand.id — signed INT (UNSIGNED-нюанс country тут неактуален).
 */
final class Version20260604_brand_faq extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Таблица brand_faq (question/answer/position/locale/source)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_faq (
                id INT AUTO_INCREMENT NOT NULL,
                brand_id INT NOT NULL,
                question VARCHAR(500) NOT NULL,
                answer LONGTEXT NOT NULL,
                position SMALLINT NOT NULL DEFAULT 0,
                locale VARCHAR(5) NOT NULL DEFAULT 'ru',
                source VARCHAR(16) NOT NULL DEFAULT 'wordstat',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_bfaq_brand (brand_id),
                PRIMARY KEY (id),
                CONSTRAINT FK_brand_faq_brand FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS brand_faq');
    }
}
