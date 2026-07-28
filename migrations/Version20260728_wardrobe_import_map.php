<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * wardrobe_import_map — идемпотентность переноса гардероба между инсталляциями
 * (app:wardrobe:restore-backup, формат `wearbase.wardrobe` v1).
 *
 * Почему не дедуп по item_no: номер сквозной внутри пользователя и на приёмнике может
 * быть уже занят ЧУЖОЙ вещью — совпадение номера ничего не доказывает. Ключ переноса —
 * тройка (отпечаток источника, id пользователя в источнике, id вещи в источнике).
 *
 * source_fingerprint = sha256(--source), а НЕ хеш файла бэкапа: повторный экспорт того же
 * гардероба даёт другой exported_at и другой хеш файла, и повторный импорт создал бы дубли.
 */
final class Version20260728_wardrobe_import_map extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'wardrobe_import_map — карта «вещь в источнике → вещь на приёмнике» для идемпотентного переноса';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wardrobe_import_map (
                id INT AUTO_INCREMENT NOT NULL,
                source_fingerprint CHAR(64) NOT NULL COMMENT 'sha256 от --source',
                source_user_id INT NOT NULL,
                source_item_id INT NOT NULL,
                wardrobe_item_id INT NOT NULL,
                imported_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_wardrobe_import_source (source_fingerprint, source_user_id, source_item_id),
                UNIQUE INDEX uniq_wardrobe_import_target (wardrobe_item_id),
                CONSTRAINT fk_wardrobe_import_item FOREIGN KEY (wardrobe_item_id) REFERENCES wardrobe_item (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS wardrobe_import_map');
    }
}
