<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * city_hub — кураторский SEO-контент городских хабов /{_locale}/cities/{slug}.
 * Ключ — slug из CitySlugger; нет строки → шаблон падает на формульную мету.
 * Засев плацдарма «Москва» (slug=moskva).
 */
final class Version20260619_city_hub extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'city_hub — кураторский SEO-контент городских хабов + засев Москвы (плацдарм)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS city_hub (
                id INT AUTO_INCREMENT NOT NULL,
                slug VARCHAR(255) NOT NULL,
                title VARCHAR(255) DEFAULT NULL,
                h1 VARCHAR(255) DEFAULT NULL,
                meta_title VARCHAR(255) DEFAULT NULL,
                meta_description VARCHAR(500) DEFAULT NULL,
                intro LONGTEXT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
                UNIQUE INDEX uniq_city_hub_slug (slug),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO city_hub (slug, title, h1, meta_title, meta_description, intro, is_active)
            VALUES (
                'moskva',
                'Москва',
                'Бренды одежды Москвы',
                'Бренды одежды Москвы — каталог местных марок | WEARBASE',
                'Российские бренды одежды из Москвы: independent-марки, шоурумы, streetwear и минимализм. Сайты, соцсети и контакты — покупайте напрямую у производителя, без наценки маркетплейсов.',
                '<p>Москва — центр независимой российской моды: здесь работают шоурумы и ателье, появляются streetwear-марки, минималистичные и ремесленные бренды. Многие продают напрямую — через собственные сайты и шоурумы, минуя маркетплейсы.</p><p>В каталоге WEARBASE собраны московские бренды одежды с проверенными сайтами, соцсетями и контактами. Покупая напрямую у производителя, вы платите честную цену без комиссии площадок, а в шоуруме вещь можно примерить.</p>',
                1
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS city_hub');
    }
}
