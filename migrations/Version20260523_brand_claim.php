<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * brand_claim — заявки пользователей на владение брендом.
 *
 * Статусы: pending | email_verified | approved | rejected
 *
 * Типы FK-колонок намеренно INT (не UNSIGNED), т.к. brand.id и client.id — INT AUTO_INCREMENT.
 */
final class Version20260523_brand_claim extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add brand_claim table for brand ownership requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_claim (
                id               INT          NOT NULL AUTO_INCREMENT,
                brand_id         INT          NOT NULL,
                user_id          INT          NOT NULL,
                reviewed_by_id   INT          DEFAULT NULL,

                status           VARCHAR(20)  NOT NULL DEFAULT 'pending'
                                     COMMENT 'pending | email_verified | approved | rejected',
                comment          LONGTEXT     DEFAULT NULL COMMENT 'Комментарий заявителя',
                email_domain_match TINYINT(1) NOT NULL DEFAULT 0,
                admin_note       LONGTEXT     DEFAULT NULL COMMENT 'Комментарий администратора',

                reviewed_at      DATETIME     DEFAULT NULL COMMENT 'Дата решения',
                created_at       DATETIME     NOT NULL,
                updated_at       DATETIME     NOT NULL,

                PRIMARY KEY (id),
                KEY idx_brand_claim_brand  (brand_id),
                KEY idx_brand_claim_user   (user_id),
                KEY idx_brand_claim_status (status),

                CONSTRAINT fk_brand_claim_brand
                    FOREIGN KEY (brand_id)       REFERENCES brand(id)  ON DELETE CASCADE,
                CONSTRAINT fk_brand_claim_user
                    FOREIGN KEY (user_id)        REFERENCES client(id) ON DELETE CASCADE,
                CONSTRAINT fk_brand_claim_reviewer
                    FOREIGN KEY (reviewed_by_id) REFERENCES client(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS brand_claim');
    }
}
