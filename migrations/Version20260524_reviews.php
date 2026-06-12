<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260524_reviews extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create review table for product reviews and ratings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS review (
                id          INT AUTO_INCREMENT NOT NULL,
                product_id  INT NOT NULL,
                user_id     INT NOT NULL,
                rating      SMALLINT NOT NULL,
                title       VARCHAR(255) DEFAULT NULL,
                body        TEXT DEFAULT NULL,
                status      VARCHAR(20) DEFAULT 'pending' NOT NULL,
                is_verified TINYINT(1) DEFAULT 0 NOT NULL,
                created_at  DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                updated_at  DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
                INDEX IDX_REVIEW_PRODUCT (product_id),
                INDEX IDX_REVIEW_USER (user_id),
                INDEX IDX_REVIEW_STATUS (status),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE review
                ADD CONSTRAINT FK_REVIEW_PRODUCT
                FOREIGN KEY (product_id) REFERENCES product (id)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE review
                ADD CONSTRAINT FK_REVIEW_USER
                FOREIGN KEY (user_id) REFERENCES client (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS review');
    }
}
