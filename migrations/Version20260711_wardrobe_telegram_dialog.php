<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * telegram_dialog_state — эфемерное состояние TG-диалога «Мой гардероб»:
 * черновик вещи (draft JSON), собираемый из фото + подписи/сообщений.
 * Сессионный скретч (не user-domain) — hard-delete разрешён, TTL 24ч (lazy-expiry).
 */
final class Version20260711_wardrobe_telegram_dialog extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'telegram_dialog_state — черновики Telegram-ввода «Мой гардероб» (эфемерные, TTL 24ч)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS telegram_dialog_state (
                id INT AUTO_INCREMENT NOT NULL,
                chat_id VARCHAR(32) NOT NULL,
                state VARCHAR(32) NOT NULL,
                draft JSON DEFAULT NULL,
                last_update_id BIGINT DEFAULT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_tg_dialog_chat (chat_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS telegram_dialog_state');
    }
}
