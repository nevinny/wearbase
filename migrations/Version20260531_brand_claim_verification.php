<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Расширяет brand_claim полями для self-serve верификации владения
 * (метод, код на email, OAuth-токен/state для VK, лимиты и аудит).
 *
 * NOTE: MySQL не поддерживает ADD COLUMN IF NOT EXISTS — идемпотентность
 * обеспечивается системой миграций (каждая миграция запускается ровно один раз).
 */
final class Version20260531_brand_claim_verification extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BrandClaim: verification method, email code, VK oauth state, rate-limit + audit fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE brand_claim
                ADD COLUMN method             VARCHAR(20)  DEFAULT NULL COMMENT 'email_code|vk_admin|document|marketplace|manual',
                ADD COLUMN verification_code   VARCHAR(12)  DEFAULT NULL,
                ADD COLUMN verification_token  VARCHAR(128) DEFAULT NULL COMMENT 'state/nonce для OAuth',
                ADD COLUMN code_expires_at     DATETIME     DEFAULT NULL,
                ADD COLUMN code_sent_at        DATETIME     DEFAULT NULL,
                ADD COLUMN code_sends          INT NOT NULL DEFAULT 0,
                ADD COLUMN code_attempts       INT NOT NULL DEFAULT 0,
                ADD COLUMN verified_via        VARCHAR(20)  DEFAULT NULL COMMENT 'vk_admin|email_code|...'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE brand_claim
                DROP COLUMN method,
                DROP COLUMN verification_code,
                DROP COLUMN verification_token,
                DROP COLUMN code_expires_at,
                DROP COLUMN code_sent_at,
                DROP COLUMN code_sends,
                DROP COLUMN code_attempts,
                DROP COLUMN verified_via
        SQL);
    }
}
