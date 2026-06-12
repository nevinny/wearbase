<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ЛК Бренда + ЛК Клиента: User, BrandUser, BrandInvite,
 * ProductCategory, ProductVariant, ProductImage,
 * Address, Cart, CartItem, Order, OrderItem, OrderStatusHistory,
 * Notification, NotificationSettings
 * + расширение таблицы product
 */
final class Version20260523_lk_accounts extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add front-end User, Brand LK, Customer LK entities';
    }

    public function up(Schema $schema): void
    {
        // -------------------------------------------------------
        // client — front-end пользователи (покупатели + менеджеры)
        // -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS client (
                id              INT AUTO_INCREMENT NOT NULL,
                email           VARCHAR(180)  NOT NULL,
                roles           JSON          NOT NULL,
                password        VARCHAR(255)  NOT NULL,
                first_name      VARCHAR(100)  DEFAULT NULL,
                last_name       VARCHAR(100)  DEFAULT NULL,
                phone           VARCHAR(20)   DEFAULT NULL,
                avatar          VARCHAR(255)  DEFAULT NULL,
                telegram_chat_id VARCHAR(50)  DEFAULT NULL,
                email_verified_at DATETIME    DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                status          VARCHAR(20)   NOT NULL DEFAULT 'active',
                created_at      DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at      DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_user_email (email),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // -------------------------------------------------------
        // brand_user — связь пользователь ↔ бренд с ролью
        // -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_user (
                id              INT AUTO_INCREMENT NOT NULL,
                user_id         INT           NOT NULL,
                brand_id        INT           NOT NULL,
                role            VARCHAR(20)   NOT NULL DEFAULT 'manager',
                invited_by_id   INT           DEFAULT NULL,
                invited_at      DATETIME      DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                accepted_at     DATETIME      DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at      DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_brand_user (brand_id, user_id),
                INDEX IDX_bu_user (user_id),
                INDEX IDX_bu_brand (brand_id),
                CONSTRAINT FK_bu_user    FOREIGN KEY (user_id)       REFERENCES client (id) ON DELETE CASCADE,
                CONSTRAINT FK_bu_brand   FOREIGN KEY (brand_id)      REFERENCES brand (id)    ON DELETE CASCADE,
                CONSTRAINT FK_bu_invited FOREIGN KEY (invited_by_id) REFERENCES client (id) ON DELETE SET NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // -------------------------------------------------------
        // brand_invite — приглашения в команду бренда
        // -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS brand_invite (
                id              INT AUTO_INCREMENT NOT NULL,
                brand_id        INT           NOT NULL,
                invited_by_id   INT           NOT NULL,
                email           VARCHAR(180)  NOT NULL,
                token           VARCHAR(64)   NOT NULL,
                role            VARCHAR(20)   NOT NULL DEFAULT 'manager',
                expires_at      DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                accepted_at     DATETIME      DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at      DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_invite_token (token),
                INDEX IDX_invite_brand (brand_id),
                CONSTRAINT FK_invite_brand    FOREIGN KEY (brand_id)      REFERENCES brand (id)    ON DELETE CASCADE,
                CONSTRAINT FK_invite_inv_by   FOREIGN KEY (invited_by_id) REFERENCES client (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // -------------------------------------------------------
        // product_category — дерево категорий товаров
        // -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS product_category (
                id              INT AUTO_INCREMENT NOT NULL,
                parent_id       INT           DEFAULT NULL,
                slug            VARCHAR(100)  NOT NULL,
                title           VARCHAR(255)  NOT NULL,
                icon            VARCHAR(50)   DEFAULT NULL,
                ord             INT           NOT NULL DEFAULT 0,
                status          VARCHAR(20)   NOT NULL DEFAULT 'active',
                UNIQUE INDEX UNIQ_pc_slug (slug),
                INDEX IDX_pc_parent (parent_id),
                CONSTRAINT FK_pc_parent FOREIGN KEY (parent_id) REFERENCES product_category (id) ON DELETE SET NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // Базовые категории
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO product_category (slug, title, ord, status) VALUES
                ('verhnyaya-odezhda', 'Верхняя одежда', 1, 'active'),
                ('tolstovki',         'Толстовки и худи', 2, 'active'),
                ('futbolki',          'Футболки и лонгсливы', 3, 'active'),
                ('bryuki',            'Брюки и джинсы', 4, 'active'),
                ('shorty',            'Шорты', 5, 'active'),
                ('aksessuary',        'Аксессуары', 6, 'active'),
                ('obuv',              'Обувь', 7, 'active'),
                ('sportivnaya',       'Спортивная одежда', 8, 'active'),
                ('golovnye-ubory',    'Головные уборы', 9, 'active'),
                ('sumki',             'Сумки и рюкзаки', 10, 'active')
        SQL);

        // -------------------------------------------------------
        // product — расширение существующей таблицы
        // title и slug уже существуют (добавлены ранними миграциями)
        // Используем PHP-интроспекцию чтобы не падать при повторном запуске
        // -------------------------------------------------------
        $sm = $this->connection->createSchemaManager();
        $productCols = array_map(
            static fn($c) => strtolower($c->getName()),
            $sm->listTableColumns('product')
        );
        $productIdxs = array_map('strtolower', array_keys($sm->listTableIndexes('product')));

        $newCols = [
            'category_id'      => 'INT DEFAULT NULL',
            'gender'           => 'VARCHAR(10) DEFAULT NULL',
            'anons'            => 'VARCHAR(500) DEFAULT NULL',
            'meta_title'       => 'VARCHAR(255) DEFAULT NULL',
            'meta_description' => 'VARCHAR(500) DEFAULT NULL',
        ];
        foreach ($newCols as $col => $def) {
            if (!in_array($col, $productCols, true)) {
                $this->connection->executeStatement("ALTER TABLE product ADD $col $def");
            }
        }

        if (!in_array('uniq_product_slug', $productIdxs, true)) {
            $this->connection->executeStatement('CREATE UNIQUE INDEX UNIQ_product_slug ON product (slug)');
        }

        // FK на category_id
        $fkNames = array_map('strtolower', array_map(
            static fn($fk) => $fk->getName(),
            $sm->listTableForeignKeys('product')
        ));
        if (in_array('fk_product_category', $fkNames, true)) {
            $this->connection->executeStatement('ALTER TABLE product DROP FOREIGN KEY FK_product_category');
        }
        $this->connection->executeStatement('ALTER TABLE product ADD CONSTRAINT FK_product_category FOREIGN KEY (category_id) REFERENCES product_category (id) ON DELETE SET NULL');

        if (!in_array('idx_product_category', $productIdxs, true)) {
            $this->connection->executeStatement('CREATE INDEX IDX_product_category ON product (category_id)');
        }

        // product_style — ManyToMany: product ↔ brand_style
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS product_style (
                product_id      INT NOT NULL,
                brand_style_id  INT NOT NULL,
                INDEX IDX_ps_product (product_id),
                INDEX IDX_ps_style   (brand_style_id),
                CONSTRAINT FK_ps_product FOREIGN KEY (product_id)     REFERENCES product     (id) ON DELETE CASCADE,
                CONSTRAINT FK_ps_style   FOREIGN KEY (brand_style_id) REFERENCES brand_style (id) ON DELETE CASCADE,
                PRIMARY KEY(product_id, brand_style_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // -------------------------------------------------------
        // product_variant — SKU: размер + цвет + цена + остаток
        // -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS product_variant (
                id              INT AUTO_INCREMENT NOT NULL,
                product_id      INT           NOT NULL,
                sku             VARCHAR(100)  DEFAULT NULL,
                size            VARCHAR(20)   DEFAULT NULL,
                color           VARCHAR(50)   DEFAULT NULL,
                color_hex       VARCHAR(7)    DEFAULT NULL,
                price           DECIMAL(10,2) NOT NULL,
                compare_price   DECIMAL(10,2) DEFAULT NULL,
                stock_qty       INT           NOT NULL DEFAULT 0,
                weight          INT           DEFAULT NULL COMMENT 'граммы',
                status          VARCHAR(20)   NOT NULL DEFAULT 'active',
                created_at      DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at      DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_pv_product (product_id),
                CONSTRAINT FK_pv_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // -------------------------------------------------------
        // product_image — фотографии товара (аналог brand_image)
        // -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS product_image (
                id              INT AUTO_INCREMENT NOT NULL,
                product_id      INT           NOT NULL,
                variant_id      INT           DEFAULT NULL,
                created_by      INT           DEFAULT NULL,
                updated_by      INT           DEFAULT NULL,
                preview         VARCHAR(255)  DEFAULT NULL,
                image           VARCHAR(255)  DEFAULT NULL,
                sort            INT           NOT NULL DEFAULT 0,
                is_main         TINYINT(1)    NOT NULL DEFAULT 0,
                status          VARCHAR(20)   NOT NULL DEFAULT 'active',
                created_at      DATETIME      NOT NULL,
                updated_at      DATETIME      NOT NULL,
                INDEX IDX_pi_product (product_id),
                INDEX IDX_pi_variant (variant_id),
                CONSTRAINT FK_pi_product FOREIGN KEY (product_id) REFERENCES product         (id) ON DELETE CASCADE,
                CONSTRAINT FK_pi_variant FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE SET NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // -------------------------------------------------------
        // address — адреса доставки покупателя
        // -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS address (
                id              INT AUTO_INCREMENT NOT NULL,
                user_id         INT           NOT NULL,
                label           VARCHAR(50)   DEFAULT NULL,
                full_name       VARCHAR(255)  NOT NULL,
                phone           VARCHAR(20)   NOT NULL,
                country         VARCHAR(2)    NOT NULL DEFAULT 'RU',
                city            VARCHAR(100)  NOT NULL,
                street          VARCHAR(255)  NOT NULL,
                building        VARCHAR(20)   DEFAULT NULL,
                apartment       VARCHAR(20)   DEFAULT NULL,
                zip             VARCHAR(10)   DEFAULT NULL,
                is_default      TINYINT(1)    NOT NULL DEFAULT 0,
                created_at      DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_addr_user (user_id),
                CONSTRAINT FK_addr_user FOREIGN KEY (user_id) REFERENCES client (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // -------------------------------------------------------
        // cart — корзина (авторизованные + гостевые)
        // -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cart (
                id              INT AUTO_INCREMENT NOT NULL,
                user_id         INT           DEFAULT NULL,
                session_id      VARCHAR(128)  DEFAULT NULL,
                created_at      DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at      DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_cart_user (user_id),
                INDEX IDX_cart_session (session_id),
                CONSTRAINT FK_cart_user FOREIGN KEY (user_id) REFERENCES client (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // -------------------------------------------------------
        // cart_item — позиции в корзине
        // -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cart_item (
                id              INT AUTO_INCREMENT NOT NULL,
                cart_id         INT           NOT NULL,
                variant_id      INT           NOT NULL,
                qty             INT           NOT NULL DEFAULT 1,
                added_at        DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_ci_cart    (cart_id),
                INDEX IDX_ci_variant (variant_id),
                CONSTRAINT FK_ci_cart    FOREIGN KEY (cart_id)    REFERENCES cart            (id) ON DELETE CASCADE,
                CONSTRAINT FK_ci_variant FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // -------------------------------------------------------
        // `order` — заказ (один бренд = один заказ)
        // -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `order` (
                id               INT AUTO_INCREMENT NOT NULL,
                order_number     VARCHAR(30)   NOT NULL,
                customer_id      INT           NOT NULL,
                brand_id         INT           NOT NULL,
                status           VARCHAR(20)   NOT NULL DEFAULT 'new',
                payment_status   VARCHAR(20)   NOT NULL DEFAULT 'pending',
                payment_method   VARCHAR(30)   DEFAULT NULL,
                delivery_method  VARCHAR(30)   DEFAULT NULL,
                tracking_number  VARCHAR(100)  DEFAULT NULL,
                shipping_address JSON          NOT NULL,
                subtotal         DECIMAL(10,2) NOT NULL DEFAULT '0.00',
                shipping_cost    DECIMAL(10,2) NOT NULL DEFAULT '0.00',
                discount_amount  DECIMAL(10,2) NOT NULL DEFAULT '0.00',
                total_amount     DECIMAL(10,2) NOT NULL DEFAULT '0.00',
                currency         VARCHAR(3)    NOT NULL DEFAULT 'RUB',
                customer_note    LONGTEXT      DEFAULT NULL,
                admin_note       LONGTEXT      DEFAULT NULL,
                created_at       DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at       DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                completed_at     DATETIME      DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_order_number (order_number),
                INDEX IDX_order_customer (customer_id),
                INDEX IDX_order_brand    (brand_id),
                INDEX IDX_order_status   (status),
                CONSTRAINT FK_order_customer FOREIGN KEY (customer_id) REFERENCES client (id) ON DELETE RESTRICT,
                CONSTRAINT FK_order_brand    FOREIGN KEY (brand_id)    REFERENCES brand    (id) ON DELETE RESTRICT,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // -------------------------------------------------------
        // order_item — позиции заказа (снапшот на момент покупки)
        // -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS order_item (
                id              INT AUTO_INCREMENT NOT NULL,
                order_id        INT           NOT NULL,
                variant_id      INT           DEFAULT NULL,
                product_title   VARCHAR(255)  NOT NULL,
                variant_title   VARCHAR(100)  DEFAULT NULL,
                sku             VARCHAR(100)  DEFAULT NULL,
                price           DECIMAL(10,2) NOT NULL,
                qty             INT           NOT NULL DEFAULT 1,
                total           DECIMAL(10,2) NOT NULL,
                INDEX IDX_oi_order   (order_id),
                INDEX IDX_oi_variant (variant_id),
                CONSTRAINT FK_oi_order   FOREIGN KEY (order_id)   REFERENCES `order`       (id) ON DELETE CASCADE,
                CONSTRAINT FK_oi_variant FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE SET NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // -------------------------------------------------------
        // order_status_history — история смен статуса
        // -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS order_status_history (
                id              INT AUTO_INCREMENT NOT NULL,
                order_id        INT           NOT NULL,
                created_by_id   INT           DEFAULT NULL,
                from_status     VARCHAR(20)   DEFAULT NULL,
                to_status       VARCHAR(20)   NOT NULL,
                comment         LONGTEXT      DEFAULT NULL,
                created_at      DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_osh_order (order_id),
                CONSTRAINT FK_osh_order      FOREIGN KEY (order_id)      REFERENCES `order`   (id) ON DELETE CASCADE,
                CONSTRAINT FK_osh_created_by FOREIGN KEY (created_by_id) REFERENCES client  (id) ON DELETE SET NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // -------------------------------------------------------
        // notification — in-app уведомления
        // -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS notification (
                id              INT AUTO_INCREMENT NOT NULL,
                recipient_id    INT           NOT NULL,
                type            VARCHAR(50)   NOT NULL,
                title           VARCHAR(255)  NOT NULL,
                body            LONGTEXT      DEFAULT NULL,
                data            JSON          DEFAULT NULL,
                is_read         TINYINT(1)    NOT NULL DEFAULT 0,
                channel         VARCHAR(20)   NOT NULL DEFAULT 'inapp',
                created_at      DATETIME      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                read_at         DATETIME      DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_notif_recipient (recipient_id),
                INDEX IDX_notif_is_read   (is_read),
                CONSTRAINT FK_notif_recipient FOREIGN KEY (recipient_id) REFERENCES client (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // -------------------------------------------------------
        // notification_settings — настройки каналов уведомлений
        // -------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS notification_settings (
                id                INT AUTO_INCREMENT NOT NULL,
                user_id           INT           NOT NULL,
                event_type        VARCHAR(50)   NOT NULL,
                channel_email     TINYINT(1)    NOT NULL DEFAULT 1,
                channel_telegram  TINYINT(1)    NOT NULL DEFAULT 0,
                channel_inapp     TINYINT(1)    NOT NULL DEFAULT 1,
                channel_push      TINYINT(1)    NOT NULL DEFAULT 0,
                UNIQUE INDEX UNIQ_ns_user_event (user_id, event_type),
                CONSTRAINT FK_ns_user FOREIGN KEY (user_id) REFERENCES client (id) ON DELETE CASCADE,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_product_category');
        $this->addSql('DROP INDEX IDX_product_category ON product');
        $this->addSql('DROP INDEX UNIQ_product_slug ON product');
        // title и slug не трогаем — они существовали до этой миграции
        $this->addSql('ALTER TABLE product DROP category_id, DROP gender, DROP anons, DROP meta_title, DROP meta_description');

        $this->addSql('DROP TABLE notification_settings');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE order_status_history');
        $this->addSql('DROP TABLE order_item');
        $this->addSql('DROP TABLE `order`');
        $this->addSql('DROP TABLE cart_item');
        $this->addSql('DROP TABLE cart');
        $this->addSql('DROP TABLE address');
        $this->addSql('DROP TABLE product_image');
        $this->addSql('DROP TABLE product_variant');
        $this->addSql('DROP TABLE product_style');
        $this->addSql('DROP TABLE product_category');
        $this->addSql('DROP TABLE brand_invite');
        $this->addSql('DROP TABLE brand_user');
        $this->addSql('DROP TABLE client');
    }
}
