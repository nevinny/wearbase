<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Атрибуция регистрации (docs/registration_sources_2026_09.md): откуда пришёл визитёр
 * до регистрации — из куки wb_src (SignupAttributionListener, первое касание).
 * signup_utm хранит «сырой» JSON {utm_source,utm_medium,utm_campaign}, чтобы можно было
 * переклассифицировать позже без повторного визита пользователя.
 */
final class Version20260920_signup_attribution extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'client.signup_source/utm/referrer/landing/first_seen_at/ym_uid — атрибуция первого касания при регистрации';
    }

    public function up(Schema $schema): void
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client' AND COLUMN_NAME = 'signup_source'"
        );
        $this->skipIf($exists > 0, 'signup_source column already exists');

        $this->addSql('ALTER TABLE client ADD COLUMN signup_source VARCHAR(50) DEFAULT NULL, ADD COLUMN signup_utm VARCHAR(255) DEFAULT NULL, ADD COLUMN signup_referrer VARCHAR(255) DEFAULT NULL, ADD COLUMN signup_landing VARCHAR(255) DEFAULT NULL, ADD COLUMN signup_first_seen_at DATETIME DEFAULT NULL, ADD COLUMN signup_ym_uid VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP COLUMN signup_source, DROP COLUMN signup_utm, DROP COLUMN signup_referrer, DROP COLUMN signup_landing, DROP COLUMN signup_first_seen_at, DROP COLUMN signup_ym_uid');
    }
}
