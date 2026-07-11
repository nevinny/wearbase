<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Семейный гардероб:
 * - family — семья (owner = создатель, client.id signed INT);
 * - family_invite — одноразовые приглашения взрослых/подросших со своей почтой;
 * - wardrobe_transfer — append-only журнал передач вещей внутри семьи;
 * - client: family_id / family_role / family_claim_token / claimed_at / birth_date
 *   (managed-дети — реальные строки client с синтетическим email @family.wearbase.local);
 * - wardrobe_item: wear_status ('active'|'reserve'|'outgrown'|'given_away') +
 *   original_owner_id (immutable при передачах) с бэкфиллом из user_id.
 *
 * Идемпотентно: CREATE TABLE IF NOT EXISTS + ADD COLUMN через information_schema
 * (MySQL не поддерживает ADD COLUMN IF NOT EXISTS).
 */
final class Version20260711_family_wardrobe extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Семейный гардероб: family, family_invite, wardrobe_transfer + поля client и wardrobe_item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS family (
                id INT AUTO_INCREMENT NOT NULL,
                owner_id INT NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_family_owner (owner_id),
                CONSTRAINT fk_family_owner FOREIGN KEY (owner_id) REFERENCES client (id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS family_invite (
                id INT AUTO_INCREMENT NOT NULL,
                family_id INT NOT NULL,
                accepted_by_id INT DEFAULT NULL,
                role VARCHAR(10) NOT NULL,
                token VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                accepted_at DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_family_invite_token (token),
                INDEX idx_family_invite_family (family_id),
                INDEX idx_family_invite_accepted_by (accepted_by_id),
                CONSTRAINT fk_family_invite_family FOREIGN KEY (family_id) REFERENCES family (id),
                CONSTRAINT fk_family_invite_accepted_by FOREIGN KEY (accepted_by_id) REFERENCES client (id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS wardrobe_transfer (
                id INT AUTO_INCREMENT NOT NULL,
                item_id INT NOT NULL,
                from_user_id INT NOT NULL,
                to_user_id INT NOT NULL,
                actor_id INT NOT NULL,
                transferred_at DATETIME NOT NULL,
                note LONGTEXT DEFAULT NULL,
                INDEX idx_wt_item (item_id),
                INDEX idx_wt_from_user (from_user_id),
                INDEX idx_wt_to_user (to_user_id),
                INDEX idx_wt_actor (actor_id),
                CONSTRAINT fk_wt_item FOREIGN KEY (item_id) REFERENCES wardrobe_item (id),
                CONSTRAINT fk_wt_from_user FOREIGN KEY (from_user_id) REFERENCES client (id),
                CONSTRAINT fk_wt_to_user FOREIGN KEY (to_user_id) REFERENCES client (id),
                CONSTRAINT fk_wt_actor FOREIGN KEY (actor_id) REFERENCES client (id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        // --- client: поля семьи ---
        if (!$this->columnExists('client', 'family_id')) {
            $this->addSql('ALTER TABLE client ADD COLUMN family_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE client ADD INDEX idx_client_family (family_id)');
            $this->addSql('ALTER TABLE client ADD CONSTRAINT fk_client_family FOREIGN KEY (family_id) REFERENCES family (id)');
        }
        if (!$this->columnExists('client', 'family_role')) {
            $this->addSql('ALTER TABLE client ADD COLUMN family_role VARCHAR(10) DEFAULT NULL');
        }
        if (!$this->columnExists('client', 'family_claim_token')) {
            $this->addSql('ALTER TABLE client ADD COLUMN family_claim_token VARCHAR(64) DEFAULT NULL');
            $this->addSql('ALTER TABLE client ADD UNIQUE INDEX uniq_client_family_claim_token (family_claim_token)');
        }
        if (!$this->columnExists('client', 'claimed_at')) {
            $this->addSql('ALTER TABLE client ADD COLUMN claimed_at DATETIME DEFAULT NULL');
        }
        if (!$this->columnExists('client', 'birth_date')) {
            $this->addSql('ALTER TABLE client ADD COLUMN birth_date DATE DEFAULT NULL');
        }

        // --- wardrobe_item: статус носки + исходный владелец ---
        if (!$this->columnExists('wardrobe_item', 'wear_status')) {
            $this->addSql("ALTER TABLE wardrobe_item ADD COLUMN wear_status VARCHAR(12) NOT NULL DEFAULT 'active'");
        }
        if (!$this->columnExists('wardrobe_item', 'original_owner_id')) {
            $this->addSql('ALTER TABLE wardrobe_item ADD COLUMN original_owner_id INT DEFAULT NULL');
            $this->addSql('ALTER TABLE wardrobe_item ADD INDEX idx_wardrobe_original_owner (original_owner_id)');
            $this->addSql('ALTER TABLE wardrobe_item ADD CONSTRAINT fk_wardrobe_original_owner FOREIGN KEY (original_owner_id) REFERENCES client (id)');
        }

        // Бэкфилл: у существующих вещей исходный владелец = текущий (идемпотентно по WHERE)
        $this->addSql('UPDATE wardrobe_item SET original_owner_id = user_id WHERE original_owner_id IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wardrobe_item DROP FOREIGN KEY fk_wardrobe_original_owner');
        $this->addSql('ALTER TABLE wardrobe_item DROP COLUMN original_owner_id');
        $this->addSql('ALTER TABLE wardrobe_item DROP COLUMN wear_status');
        $this->addSql('ALTER TABLE client DROP FOREIGN KEY fk_client_family');
        $this->addSql('ALTER TABLE client DROP COLUMN family_id');
        $this->addSql('ALTER TABLE client DROP COLUMN family_role');
        $this->addSql('ALTER TABLE client DROP COLUMN family_claim_token');
        $this->addSql('ALTER TABLE client DROP COLUMN claimed_at');
        $this->addSql('ALTER TABLE client DROP COLUMN birth_date');
        $this->addSql('DROP TABLE IF EXISTS wardrobe_transfer');
        $this->addSql('DROP TABLE IF EXISTS family_invite');
        $this->addSql('DROP TABLE IF EXISTS family');
    }

    private function columnExists(string $table, string $column): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column],
        ) > 0;
    }
}
