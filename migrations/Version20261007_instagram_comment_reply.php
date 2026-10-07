<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Дедуп ответов в директ на комментарии с кодовым словом (app:social:ig-comment-replies):
 * один comment_id — одна строка, UNIQUE гарантирует «один ответ на комментарий» (Private Reply в IG тоже один).
 */
final class Version20261007_instagram_comment_reply extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'instagram_comment_reply: журнал и дедуп ответов в директ на комментарии с кодовым словом';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS instagram_comment_reply (
                id INT AUTO_INCREMENT NOT NULL,
                post_id INT NOT NULL,
                comment_id VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                attempts INT NOT NULL DEFAULT 0,
                error LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_icr_comment (comment_id),
                INDEX idx_icr_post (post_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_icr_post FOREIGN KEY (post_id) REFERENCES social_post (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE=InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS instagram_comment_reply');
    }
}
