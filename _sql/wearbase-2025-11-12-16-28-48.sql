# ************************************************************
# Sequel Ace SQL dump
# Версия 20095
#
# https://sequel-ace.com/
# https://github.com/Sequel-Ace/Sequel-Ace
#
# Хост: localhost (MySQL 9.1.0)
# База данных: wearbase
# Время формирования: 2025-11-12 13:28:48 +0000
# ************************************************************


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
SET NAMES utf8mb4;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE='NO_AUTO_VALUE_ON_ZERO', SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


# Дамп таблицы brand
# ------------------------------------------------------------

DROP TABLE IF EXISTS `brand`;

CREATE TABLE `brand` (
  `id` int NOT NULL AUTO_INCREMENT,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent` int DEFAULT NULL,
  `ord` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anons` longtext COLLATE utf8mb4_unicode_ci,
  `website_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` longtext COLLATE utf8mb4_unicode_ci,
  `instagram_url` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telegram_url` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vkontakte_url` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `youtube_url` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug_unique_idx` (`slug`),
  KEY `IDX_1C52F958DE12AB56` (`created_by`),
  KEY `IDX_1C52F95816FE72E1` (`updated_by`),
  CONSTRAINT `FK_1C52F95816FE72E1` FOREIGN KEY (`updated_by`) REFERENCES `user` (`id`),
  CONSTRAINT `FK_1C52F958DE12AB56` FOREIGN KEY (`created_by`) REFERENCES `user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=338 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `brand` WRITE;
/*!40000 ALTER TABLE `brand` DISABLE KEYS */;

INSERT INTO `brand` (`id`, `created_by`, `updated_by`, `slug`, `title`, `parent`, `ord`, `created_at`, `updated_at`, `status`, `logo`, `anons`, `website_url`, `email`, `phone`, `address`, `description`, `instagram_url`, `telegram_url`, `vkontakte_url`, `youtube_url`, `city`)
VALUES
	(1,1,1,'telodvigeniya','Телодвижения',0,0,'2025-11-05 18:51:30','2025-11-08 12:14:48','active','/api/files/brands/iv55p4rtv4zwlfw/telodvizheniya_logo_2tkJa7WGzU.jpg','«Телодвижения» - российский бренд одежды с собственным производством, предлагающий широкий выбор товаров для создания повседневного гардероба.\r\n\r\nБолее 9 лет мы создаем качественную базовую одежду и объединяем людей, которые ценят как комфорт и практичность, так и стремление к новым горизонтам и амбициозность.','https://telodvigeniya.ru/','info@telodvigeniya.ru','+79003134281','Саратов','О компании\r\nИстория компании началась в 2014 году, когда один из основателей компании посетил молодёжный форум - там зародилась идея создания бренда, в основе которого лежат лидерство, энергичность и желание двигаться вперед. Воплотить мечту в реальность помогла команда единомышленников: полная самоотдача и уверенность в будущем успехе помогли построить большую компанию, внутри которой, так же как и тогда, живут и ценятся трудолюбие и стремление к постоянному развитию. Таким образом, была сформулирована миссия компании - помогать людям раскрывать свой потенциал.\r\n\r\nСегодня компания насчитывает 16 производственных площадок в разных городах России, а над её созданием трудится больше 1 000 человек.\r\n\r\nФраза, некогда сказанная одним из основателей – «Для тех, у кого всё будет», стала слоганом бренда.',NULL,'https://t.me/+AKAc-lXliRBmZWYy','https://vk.com/telodvigeniya','https://m.youtube.com/@TELODVIGENIYA',NULL),
	(2,1,1,'de4444th','DE4444TH',0,0,'2025-11-07 06:11:31','2025-11-08 12:14:48','active','/api/files/brands/iv2tm8m799enjwr/26fbf770d97143ceb9734f03559b5672_Pk7WhWXdld.jpg','Бренд стритвир одежды. Cоздаем мерч для современных исполнителей.\r\n\r\nОдной из главных наших особенностей являются кастомные вещи, выполненные вручную. Эти изделия могут быть как в единичных экземплярах, так и в большом тираже.\r\nКаждая из них также окрашивается художником, придавая вещам неповторимость.\r\n\r\nБренд стал популярным благодаря одежде\r\nс изображениями культовых рэперов, ушедших из жизни, таких как Lil Peep, XXXTentacion и 2Pac. Эти вещи до сих пор вызывают большой интерес и обсуждения среди поклонников.','https://de4444th.com',NULL,NULL,'Красноярск',NULL,NULL,NULL,NULL,NULL,NULL),
	(3,1,1,'tisval','TISVAL',0,0,'2025-11-07 08:10:08','2025-11-08 12:14:48','active','/api/files/brands/esbn8kzeuw6ydy0/tisval_logo_9paSTNuBWE.jpg','В TISVAL мы строим всё на премиальном качестве — от выбора материалов до обслуживания клиентов. Каждая деталь, каждая строчка и каждый элемент дизайна проходят строгий контроль, чтобы вещи служили долго и выглядели безупречно.\r\n\r\nМы создаем нашу одежду в России, со следами разных культур, все модели выполнены oversize крое.\r\n\r\nНо для нас важно не только качество вещей, но и твой опыт взаимодействия с брендом. Мы стремимся создать сервис, который соответствует нашему продукту: внимательный, открытый и всегда на высоте.','https://tisval.ru/',NULL,NULL,'Москва','TISVAL — это бренд, основанный двумя друзьями, которые однажды приняли решение отказаться от наёмной работы, чтобы обрести свободу и создать нечто по-настоящему своё. С самого начала мы стремились к вещам, в которых соединяются точность, характер и комфорт. Мы не гнались за эффектностью — нас интересовало другое: как должна выглядеть одежда, чтобы отражать внутреннюю собранность и вкус, при этом быть живой, пластичной и уместной в разных контекстах.\r\n\r\n\r\n\r\nВ TISVAL особое внимание уделяется выбору материалов. Мы работаем только с тканями высокого качества, тщательно проверяем посадку, лично тестируем каждую модель. Производство TISVAL находится в России. Это наш принципиальный выбор — быть рядом с процессом, лично контролировать качество и участвовать в создании каждой вещи на всех этапах. Мы сотрудничаем с опытными мастерами, небольшими цехами и студиями, где ценят точность, аккуратность и внимание к деталям. Такой формат позволяет не просто выпускать одежду, а выстраивать устойчивую, прозрачную систему работы — без лишнего, без компромиссов.\r\n\r\n\r\n\r\nРаботая здесь, мы поддерживаем развитие современной фэшен-культуры в России, создаём рабочие места и инвестируем в качество, которое рождается внутри страны. Для нас это не про «удобно» или «дешевле». Это про ответственность — перед продуктом, перед командой, перед теми, кто выбирает TISVAL. Крой мы продумываем так, чтобы одежда не сковывала движения и не требовала усилий при носке — силуэты чистые, немного расслабленные, но при этом собранные, сдержанные и выразительные.\r\n\r\nПринты разрабатываются в коллаборации с художниками и графическими дизайнерами, и становятся частью визуального языка бренда, а не просто украшением.\r\n\r\n\r\n\r\nВажной частью нашей философии является сервис. Мы внимательно относимся к каждому клиенту — от первого контакта до момента, когда вещь оказывается в руках. Доставка работает быстро и аккуратно, упаковка продумана до мелочей, а обратная связь всегда остаётся открытой. Мы выстраиваем общение так, чтобы человек чувствовал: он не просто оформил заказ, а стал частью пространства, где ценят внимание, вкус и уважение к личному выбору.\r\n\r\n\r\n\r\nTISVAL — это одежда для тех, кто мыслит точно, выбирает внимательно и чувствует себя свободно в каждом решении. Нам близки люди, которые умеют замечать форму, ценят эстетику повседневного, и не нуждаются в лишних словах. Мы не создаём моду — мы создаём одежду, в которой удобно быть собой.',NULL,NULL,NULL,NULL,NULL),
	(4,1,1,'anteater','Anteater',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/fr4709f4aoe4bwd/cdacb41b4ca6403794dd2a0b05f78ea6f15b9e7d_1000x1000_TE8azXeJ2Y.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(5,1,1,'mysiberia','MySiberia',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/m0qp759nk53n9of/1709451183095_mysiberia_logo_8MR90jlfh3.jpg',NULL,NULL,NULL,NULL,'Кемерово',NULL,NULL,NULL,NULL,NULL,NULL),
	(6,1,1,'breakdownbrand','Break Down',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/5lyfdfjxmebs8tm/1709449703488_breakdown_logo_gDKHwzPOAk.jpg',NULL,NULL,NULL,NULL,'Белгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(7,1,1,'synopticstore','SYNOPTIC',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/0vq8qpvcodf6073/1707204488233_synoptic_logo_EJXqURgbgA.jpg',NULL,NULL,NULL,NULL,'Екатеринбург',NULL,NULL,NULL,NULL,NULL,NULL),
	(8,1,1,'syntheticsclub','synthetics.club',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/mzo9dok06af1mr6/1707204161472_synthetics_logo_FUtiJFgrK9.jpg',NULL,NULL,NULL,NULL,'Саратов',NULL,NULL,NULL,NULL,NULL,NULL),
	(9,1,1,'udus','UDUS',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/zu55emoy909impm/1706429916731_udus_logo_c83MQorlky.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(10,1,1,'resplendentclo','Resplendent',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/qs0ricf97i37lbh/1705682092176_resplendent0_LY9dtWt4ma.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(11,1,1,'zatmenie','Zatmenie',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/wa8qjmqm7v5mj9g/1705342704310_zatmenie_logo_pXhrQA77Ao.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(12,1,1,'menomerch','MENO',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/4y6cjj838km3nj1/1704021462389_meno_logo_c8Rt4zZaMO.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(13,1,1,'streetrepublic','Street Republic',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/6rsuzrook5n7zp0/1701794616189_srwear_logo_WJ80vxmEdG.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(14,1,1,'formasquad','Forma Squad',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/2g04edny8q4m173/1701117039914_formsquad_logo_STurfCCM0Z.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(15,1,1,'netoclothes','NETO',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/dhs1hp69h55r3y1/1699652832375_neto_logo_6ikdbVjqQt.jpg',NULL,NULL,NULL,NULL,'Нальчик',NULL,NULL,NULL,NULL,NULL,NULL),
	(16,1,1,'defeez','Defeez',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ggafs07bkcsc3jc/1698388343707_defeez_logo_3ZIR6qvgr4.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(17,1,1,'bit-tmn','BIT',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/a6tsv5om5pvt611/1697385229108_bit_logo_lmtRyp9yI6.jpg',NULL,NULL,NULL,NULL,'Тюмень',NULL,NULL,NULL,NULL,NULL,NULL),
	(18,1,1,'upgradez','UPGRADEZ',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/mj6y46lgdznffeg/1695714331484_upgradez_logo_XmgUQUICNT.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(19,1,1,'bolgari','Bolgari',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/fsfbszg51zdht04/1691048453416_bolgari_logo_lJS4082zVU.jpg',NULL,NULL,NULL,NULL,'Казань',NULL,NULL,NULL,NULL,NULL,NULL),
	(20,1,1,'faqory','ФАКОРИ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/05xoik8r1nxn5ly/1690828664106_img_8199_SzFlIlG2jd.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(21,1,1,'iscariot','ISCARIOT',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/4j64w8ucqw8vsa8/1690010555475_nvcp_gzv_eqt4_kpdKH58vcR.jpg',NULL,NULL,NULL,NULL,'Екатеринбург',NULL,NULL,NULL,NULL,NULL,NULL),
	(22,1,1,'snrgwear','SNRG',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/d3pq032yglt4hax/1689496012107_snrg_logo_rvi3rTqOmn.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(23,1,1,'mallwalkers','MALLWALKERS',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/i6jilw7bd38osn6/1689495686488_0h_zh6_qe_rno_KDrv4EHmFK.jpg',NULL,NULL,NULL,NULL,'Пенза',NULL,NULL,NULL,NULL,NULL,NULL),
	(24,1,1,'heymate-gang','HEYMATE GANG',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/te5xof574cus9ac/1689230152748_heymatelogo_WavJKAz0cF.jpg',NULL,NULL,NULL,NULL,'Новосибирск',NULL,NULL,NULL,NULL,NULL,NULL),
	(25,1,1,'43freedom','43.FREEDOM',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/l9f7wa4f18q2q2p/1689060801209_43_logo_QBoOW1JmuA.jpg',NULL,NULL,NULL,NULL,'Казань',NULL,NULL,NULL,NULL,NULL,NULL),
	(26,1,1,'squadblessed','BLESSED',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/cvrxr1dgm1jees9/1687721242049_kjq_mm_o7u_j68_vVsqSB9Bko.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(27,1,1,'criminalquarters','CriminalQuarters',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ppk9yssm8iatkmb/1687720457414_z_g85_dt_jyk8_1_N21E3e4YgC.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(28,1,1,'xcnx','xcnx',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/bedunbu199m70ic/1687719954635_xcnx_logo_F9bGjDSqGS.jpg',NULL,NULL,NULL,NULL,'Казань',NULL,NULL,NULL,NULL,NULL,NULL),
	(29,1,1,'demetros','DMTRS (Demetros Design)',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/2l9j3ude030266j/1688929105544_2_ts8_jqsu_wmg_Rd9pXY9Xge.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(30,1,1,'ucxod','Исход',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/ukhk7ve68x1zbs6/1687115447502_photo_2023_06_18_22_08_09_p3zqW2ZKRn.jpeg',NULL,NULL,NULL,NULL,'Магнитогорск',NULL,NULL,NULL,NULL,NULL,NULL),
	(31,1,1,'wemaone','WEMA',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/9lig8ltho5etibm/1685554918412_img_5954_Eic53U8KyZ.PNG',NULL,NULL,NULL,NULL,'Пенза',NULL,NULL,NULL,NULL,NULL,NULL),
	(32,1,1,'yudzi','YUDZI',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/f0p2it1sv7tb5zh/1684952680082_ju_suy2_n4_t9_m_pIXPV4MEHh.jpg',NULL,NULL,NULL,NULL,'Краснодар',NULL,NULL,NULL,NULL,NULL,NULL),
	(33,1,1,'civilic','CIVIL | ЦИВИЛ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/l1vaj4nkulugjo1/1684951925888_9l_rg5_gu3cd_a_Ba6qfCAbZ5.jpg',NULL,NULL,NULL,NULL,'Тверь',NULL,NULL,NULL,NULL,NULL,NULL),
	(34,1,1,'agapi-project','АГАПИ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/nsk2kz30rn8p4yx/1684138691419_photo_2023_05_15_11_06_01_yyoC7R6oZW.jpeg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(35,1,1,'nelevonid','Нелевонид',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/2jgdxdw0683s30o/1681052769694_dm2wj0_d1_b64_MKwMv7Wpjh.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(36,1,1,'deitied','DEITIED',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/5wbqcf06jb60xmj/1680206105806_8yv_up9_jvwf_a_HYNAHFtFcP.jpg',NULL,NULL,NULL,NULL,'Ростов-на-Дону',NULL,NULL,NULL,NULL,NULL,NULL),
	(37,1,1,'kislenkoracing','Кисленько',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/r4xp7gu9jufgpd5/1680205553886_6_okq4_bqkp_vm_MqhZSHjla9.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(38,1,1,'valueworldwide','VALUE WÖRLDWIDË',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/mo5vuz0qy8rvdsi/1678995045593_g9kh01_w_tg_r9mfy1YetJ.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(39,1,1,'setidesire','SETIDESIRE',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/td1rohmzo61nmc6/1678994080955_v_ztd_hn6_jwqk_lAHitWxHSY.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(40,1,1,'uchsumer','Уч-Сумер',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/lmai5o1cw0rtyfx/1677482488679_uchsumer_logo_ueaeusBn6d.jpg',NULL,NULL,NULL,NULL,'Барнаул',NULL,NULL,NULL,NULL,NULL,NULL),
	(41,1,1,'raspad663','RASPAD',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/n1ffjb3x3454aqo/1677482671487_bcy2rlm_vit8_HLtlii6qpf.jpg',NULL,NULL,NULL,NULL,'Самара',NULL,NULL,NULL,NULL,NULL,NULL),
	(42,1,1,'kosynkageniy','kosynkageniy',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/y8vjlegx96g0c8l/1676137741660_hz5_gw8_pv_tx_a_M50dtHscwm.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(43,1,1,'daheavenly','DA`HEAVENLY',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/c35kpousfy5zf9t/1676135401500_yebicza_u7_w4_uuLNeH4n3d.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(44,1,1,'zrd','ZRD streetwear',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/bmav3c4ohogkoat/1676137270104_u_l3t_qp_ayz_zw_wvwSKv3YHF.jpg',NULL,NULL,NULL,NULL,'Воронеж',NULL,NULL,NULL,NULL,NULL,NULL),
	(45,1,1,'shu','SHU',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/quy9uhudjf40t35/1672752946383_kpn_psch2_imo_Go8cEyjt16.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(46,1,1,'tonylo','TONY\'LO BRAND',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/xedsp1r9oopu8ah/1674396914431_tonylologo_YzcDuHawKc.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(47,1,1,'endy','ENDY KÓCH',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/ghk1qqi6zdezva7/1674396488330_l6z4j5_ny1ao_A4UT14jnur.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(48,1,1,'larusodejda','Ларус Одежда',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/b7dhejomq3132z3/1673288639304_4l_guw_hk_zo_eo_slegCwQXWL.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(49,1,1,'glebkostinsolutions','.solutions',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/ff32wx6ty417q0x/1673291546685_solutions_logo_kXkI5uEQ4J.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(50,1,1,'setner','Setner',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ups4hs1ybwngu7h/1673287696789_f_cj_l3v_vw5g_NYdfzo7aCS.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(51,1,1,'stylolabel','STYL-O LABEL',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/7mbolykjs9oj9g7/1672753604569_d_kd_ldnp_nek_m_QOVCmIOL70.jpg',NULL,NULL,NULL,NULL,'Екатеринбург',NULL,NULL,NULL,NULL,NULL,NULL),
	(52,1,1,'enchant','enchant.',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/x51187hymyj7qgk/1673287261257_qv_g3h_cti_kv0_exxiWRI8pP.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(53,1,1,'chukchabrand','Чукча',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/uah2wi1ej0ffbz9/1672756291405_6p_f64btxlo_H1S36WX9lD.jpg',NULL,NULL,NULL,NULL,'Казань',NULL,NULL,NULL,NULL,NULL,NULL),
	(54,1,1,'aaasergeidesev','AAA by Sergei Desev',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/x8kuly11b7fl31h/1672754860651_tg_n0_zap_h5_ce_tfZHk3G2WZ.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(55,1,1,'gamp','GAMP',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/6hhcbqzgfirloet/1672746550989_8_r8_ggg_antvg_MXjiYIxdXW.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(56,1,1,'gidroplan','Гидроплан',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/jh8k9y8w248yy7f/1672746248038_mq_eykiz7_ncc_DmxVCovpHp.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(57,1,1,'uniform','uniform',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/pfug73dl0zgsoj6/1672745665511_rwu1_yx_hlg_e_tIPALh6zq2.jpg',NULL,NULL,NULL,NULL,'Брянск',NULL,NULL,NULL,NULL,NULL,NULL),
	(58,1,1,'yep-yep','YEP-YEP Jewelry',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/91cti04ro357mgq/03ec3231ed854120b60a93caf3b7596e_rjQsNXFemZ.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(59,1,1,'provinceskateboards','Province.',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/07vj0kp5eah2qfj/96e2afef0210482387a2a4206496cf59_QXZCEjzZbm.jpg',NULL,NULL,NULL,NULL,'Новосибирск',NULL,NULL,NULL,NULL,NULL,NULL),
	(60,1,1,'boomerangs','BOOOMERANGS',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/m6ry6s6a1qryqku/03d5f343932b40059dff4b1397879630_dCHVEJJjMp.jpg',NULL,NULL,NULL,NULL,'Тула',NULL,NULL,NULL,NULL,NULL,NULL),
	(61,1,1,'orangegang','ORANGEGANG',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/qxaxynraqt3e2u2/95dffdc85863485b8ab1b9b89bdb2e79_EA2rnpBExw.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(62,1,1,'outfofame','OUT FO FAME',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/4zjpk4e3qkodmep/a6c384c9b4534aa2ae018df3508259ad_ybJRpd1vo1.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(63,1,1,'freak-butik','Фрик-бутик',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/swbl85ree12xu7r/b100922fc5234c87901175e8aa755e58_Xo0vW5TXIA.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(64,1,1,'lych-project','ЛУЧ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/9dcevjn66kyat37/8e6e9c66382248d28bdaccc039747204_Mo7JH2r9Ox.jpg',NULL,NULL,NULL,NULL,'Нижний Новгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(65,1,1,'antisocial','Antisocial',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/lf0amr2ne2d7lke/b546015744fe4165876811944371368c_PqH9Ap9GE4.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(66,1,1,'ammourworld','Ammour World',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/bhsi4n87z21nyun/f8bb81f13a0d4fc0b93c9db7498ebc1f_owFwE4np6w.jpg',NULL,NULL,NULL,NULL,'',NULL,NULL,NULL,NULL,NULL,NULL),
	(67,1,1,'dont-care','Don’t care',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/4vdv60c1virysig/6ae01b3ee83a4ae69b32566a0bc9f92c_oD47uEkG7Z.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(68,1,1,'astronautics1961','Astronautics1961',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/xckbba9s1m82vs9/5b103960ec174223b7fcf295a4170188_iBKA66ZDFP.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(69,1,1,'rktkt-rockit','RKTKT&ROCKIT',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/0rvsa2ukmultuz7/4aab5e1f03d84beaa07097d00e95fa68_yjRi02CTfd.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(70,1,1,'igan-designer','Igan Designer',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ym8qexwyl9i8eh8/c52080403fd04728b7d628720662e51c_AwXhy5oYM5.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(71,1,1,'pzhwear','PZHWear',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/f20iwhcu6ugk71z/27a8dd2cc5234e5093b6077370ef15d4_KSR4vXGMfZ.jpg',NULL,NULL,NULL,NULL,'Курск',NULL,NULL,NULL,NULL,NULL,NULL),
	(72,1,1,'whatevs','whatevs',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/s86zijygyqqg6yh/ef914263e35f46ffa0022f9c14992f3c_LQCVy2wnhi.jpg',NULL,NULL,NULL,NULL,'Нижний Новгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(73,1,1,'advolatum','Advolatum',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/68c9co2ofpz9xs0/b298f35f93f941898f00eb1a75a70022_RtTNzMlhqC.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(74,1,1,'saint-sample','SAINT SAMPLE',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/anivda63p6t30by/7363fbb421274e5eb2b97aa8740aab4f_1WmdT4Wn4s.jpg',NULL,NULL,NULL,NULL,'Омск',NULL,NULL,NULL,NULL,NULL,NULL),
	(75,1,1,'vice','Vice',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/5rlxajdbwyxwwep/5f386130395442a2b57a2191d82e6a98_0hDZJ3GUuQ.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(76,1,1,'gorky','Город Горький',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/5mqszmsqy0485gq/e3c88fca92b44e2b9698e37b54de230f_oN0neneYYP.jpg',NULL,NULL,NULL,NULL,'Нижний Новгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(77,1,1,'konwa','konwa',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/84f9bkcrllq32ir/3142e3e202a34a1d82fbfcda0eb5f443_cbB61wdkIj.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(78,1,1,'deluge','DELUGE',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/1ouk13ahhjci3w0/62c5c34ab0c9496ea7df701c1d77d894_nsB84ey0Pt.jpg',NULL,NULL,NULL,NULL,'Челябинск',NULL,NULL,NULL,NULL,NULL,NULL),
	(79,1,1,'unfort','UNFORT',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/0k77njy60vwquh1/264232c9e0aa4924b5c25dd8f2d90297_7NKgZhgnGL.jpg',NULL,NULL,NULL,NULL,'Новосибирск',NULL,NULL,NULL,NULL,NULL,NULL),
	(80,1,1,'saint-mob','Saint Mob',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/8736stu5box56f9/6edb143958254ff2ade995fef2b29a11_i5CsyI2NEF.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(81,1,1,'fable','FABLE',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/letrjc2hhzb6bss/d00d2919c484434b87b482e501fab330_ITedACzJJg.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(82,1,1,'helgi-ingvarr','HELGI INGVARR',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/o20u531q2kro3c2/c8d6fbcfaf5446ee9abeb7aa94574955_wTzknIDOU4.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(83,1,1,'unaffected','UNAFFECTED',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/gn3ojtzczw4fn7z/3001d79370254d47b6f5b8d8285b5f95_Lyczah98Xn.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(84,1,1,'tsep','TSEP /// ЦЕП',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/69zu7xilv5p5uq1/f99599998ce4429d94ee4856f65bc2dc_WCv7CxiAAF.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(85,1,1,'omut-custom','Омут!Кастом',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/6lmnbg1uu6cvwrr/6185b5ca7bcf4fc2906aa5539642ba56_Mw9Ymld1Tl.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(86,1,1,'nado-hats','Nado_hats',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/mz2z8vplomkq64j/086e990108434f378054c8789e876d04_lkjF4vNB7n.jpg',NULL,NULL,NULL,NULL,'Уфа',NULL,NULL,NULL,NULL,NULL,NULL),
	(87,1,1,'afour','AFOUR Custom Footwear',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/y4bsvmq5ln9u85z/7e1ceb26e1b64006aec624998b51d78a_JVbuEngXXA.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(88,1,1,'farage','FARAGE',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/zwj52dpi3ued4su/1d6f76ade8864e058bce33430d9424c6_1Hg90LDtMt.jpg',NULL,NULL,NULL,NULL,'Нижний Новгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(89,1,1,'marales','Marales',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/px0u86zexof9yac/5074132edd014ddabf17389d667abf68_E43Sfqc3Lt.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(90,1,1,'old-lizard','Old Lizard',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/1xxwkbbz8zlmmj4/8ef9b4e6027e48a2bcf98b10742c1d9f_8rImfsmirS.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(91,1,1,'tang-tong','tang tong',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/7vttiwfqrxtn01s/7af0c2b689fd410fb9e9798191a80984_ofbxntOYZV.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(92,1,1,'ack-items','ACK ITEMS',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/mso15kzj82h5p9q/2060a476ffcb4beb9b1025be95ac810e_gNxMDS6CXy.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(93,1,1,'mirba','MIRBA',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/y4345xpmtz051zj/ca58f49ae60b4a0e9fe3aa4e75ba85c4_dCylxFQaJL.jpg',NULL,NULL,NULL,NULL,'Киров',NULL,NULL,NULL,NULL,NULL,NULL),
	(94,1,1,'lachaise','ЛАШАЗ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/lrft10a71z08bb6/30c20f50ce8ccd865537494e0230342faee9bd8f_1080x1080_2W5pPKaR8o.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(95,1,1,'awesome-chains','Awesome Chains',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ththa4cwqef3bqi/2428b3868c6cca0f7f40f32ede7afd5aec9e8306_1890x1890_Sh7yyueiZY.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(96,1,1,'jogger-street','Jogger Street',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/lzafil2s18av73g/1d28021f1ee9857c3201273b450efff9730c39c3_2000x2000_kovHbQbkMj.jpg',NULL,NULL,NULL,NULL,'Красноярск',NULL,NULL,NULL,NULL,NULL,NULL),
	(97,1,1,'z-n-w-r','Z N W R',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/tp8nyswlqcz8l7c/d89bd059051f70a07a7c9cc198dd99c1b102cb52_640x640_8Yqa9O6rUC.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(98,1,1,'coeval','Coeval',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/diy8mhsjm8ep7iq/ab6742b33f6058874cde457c66b79e153624f931_2000x2000_GZh9yEoLVb.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(99,1,1,'decade-clothes','Decade Clothes',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/dhg2bmnmq55a9ur/62ccada9ec91b82252ab18ffe2fc9ed859705769_320x320_ThAE83N6vM.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(100,1,1,'dagettla','dagettla',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/x9lxzurm12lyb0m/aaff63f3714bc1a6ace992710490c6fb662dbf34_2560x2560_BkuYSfeeSs.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(101,1,1,'kardar','KARDAR',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/qhcyely82ezphqr/kardar_logo_yjow515qrM.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(102,1,1,'nikifilini','NikiFilini',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/4tyi0m4pmzx7xea/a565c3a00390c69d14c24e9476c2ff400f1cbb7c_2560x2560_uAsHfZERjP.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(103,1,1,'lfk-vir','ЛФК.ВИР',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/bqo50qeb1vexes9/1274ee2e1134312e05971f4263a4fb6a36644fb5_2160x2160_rFZIA4x9eH.jpg',NULL,NULL,NULL,NULL,'Калуга',NULL,NULL,NULL,NULL,NULL,NULL),
	(104,1,1,'hard','HARD',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/vqyr7mv61gvbrlr/2503e3ac44f4e1232692180aff6348ccdef7fdc1_887x887_iFybJaAt7V.jpg',NULL,NULL,NULL,NULL,'Екатеринбург',NULL,NULL,NULL,NULL,NULL,NULL),
	(105,1,1,'all-city-pigeon','All City Pigeon',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/9xnysiyh6zq4m5u/8ffdca8cc48033ea49fdf9415b7c62f8d60e3810_1000x1000_WSdN1cyB7z.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(106,1,1,'spb-shield','Питерский Щит',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/8xwh1nh4x2fuewt/3e8ed134a25c67d960f16de1ea68ddd8d87a167f_2160x2160_GiFVC8qjNg.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(107,1,1,'sintetika','синтетика',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/9l3f6v7rzxs87em/8921f200fba9df01f009db8bcdd7b35f2e03aadc_2000x2000_e7VQtlxdG2.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(108,1,1,'fuzzz','FUZZZ',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/uzixp97xr2azz17/2c5017564ec4f2a9a89c4f9e9084191b079730d4_500x500_Ww3yb8Lj1w.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(109,1,1,'maksim-maksakov','МАКСИМ МАКСАКОВ',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/b2y5gq6ue2viwwy/3382c6564317e15520aefaf68e8b28f363374fcc_2143x2160_NW1V2p8RGh.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(110,1,1,'sheilone','SHEILONE',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/rtjxvazr3nxkhu7/60fbd0ff650042f0503caff022d922d02abee6ea_1000x1000_fd6avNykBg.jpg',NULL,NULL,NULL,NULL,'Владивосток',NULL,NULL,NULL,NULL,NULL,NULL),
	(111,1,1,'horseboard','O.VRAG',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/jlk89am6mm54c5g/77ce2a07b51d96c061c6969a06a62b89970d696c_1138x1138_o3DjJpKkXv.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(112,1,1,'acreal','ACREAL',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/qym97c7aurpqz3f/2c1552496f8b6a2e84d073add78a3ac7fb79fc28_1242x1242_cdmzTYra5y.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(113,1,1,'molodost','Молодость',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/eww2mmcbux45fb0/e4fdfd1e6d26e9b410438710a638a73592c9533e_1200x1200_Mg3w4GKz2f.jpg',NULL,NULL,NULL,NULL,'Пермь',NULL,NULL,NULL,NULL,NULL,NULL),
	(114,1,1,'sila-vetra','Сила ветра',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/3doy08w1a6cfwxl/df34fd2fa13ec688eb240c5498a9865fdaaddfdb_512x512_ueSY05aUXi.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(115,1,1,'npogp','НПОГП / NPOGP',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/h0nzqwuvc6h12zc/1221dce86b44a58ecf5e5107801a3183b1d9ac13_320x320_Fnxzinbwbi.jpg',NULL,NULL,NULL,NULL,'Тамбов',NULL,NULL,NULL,NULL,NULL,NULL),
	(116,1,1,'7apparel','С7МЬ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/bs3xnimo8m8zqdo/e8f2734968163ed2a6817ec232f0de81ea98cae4_440x374_4byTdzPLpd.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(117,1,1,'sai11114','SAi11114',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/2z7nrcwnxw4v3ra/d6c6fb1a825832b4a9be293eef7c25047b1c20e8_1619x2160_vQcohePEhD.jpg',NULL,NULL,NULL,NULL,'Екатеринбург',NULL,NULL,NULL,NULL,NULL,NULL),
	(118,1,1,'mydriasis','MYDRIASIS',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/dqn7u34xvndlwfk/215bccc569164866a8e4792146a6ce8d2848563e_2160x2160_cWWQi3SfW3.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(119,1,1,'master-of-chillin','Master Of Chillin\'\'\'',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/t4bul2sze5o711k/f4d2a3ae42bc946857fdddf3ddd8928367842857_2560x1811_yuTuMoEpIa.jpg',NULL,NULL,NULL,NULL,'Челябинск',NULL,NULL,NULL,NULL,NULL,NULL),
	(120,1,1,'symptoms','SYMPTOMS',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/erlitjqujt07s1g/4506c9b5ffbc0ab15818bcb1a011982dd21f7681_1000x1000_Yz8RKrymJs.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(121,1,1,'indiwd','Indiwd',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/amhmazmvsiy787p/50c52123304951c64d7578b1e436c64c1668d7cb_413x412_8nUWYf09B1.jpg',NULL,NULL,NULL,NULL,'Архангельск',NULL,NULL,NULL,NULL,NULL,NULL),
	(122,1,1,'wondernorthland','wondernorthland',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/paspitdmvxagovk/0618f253ffa84e4b56883ec7b78f6093b56e8d89_300x300_4pbP0lSnsJ.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(123,1,1,'issue','Issue',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/76lt4s2qtg1heee/e6b47b112f1d5fefdee0ccd3fa6b7744dcccc291_428x428_cEbaiFBpdm.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(124,1,1,'veriga','VERIGA',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/n9559qn7fkqmjph/27cdd943640e610fd7fc8b0499b831da5deca72c_1080x1080_YZaJStRX9W.jpg',NULL,NULL,NULL,NULL,'Астрахань',NULL,NULL,NULL,NULL,NULL,NULL),
	(125,1,1,'taina','ТАЙНА',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/nj5dj6odxpefq96/2e7d25a7e112766bd0909cbdbdf1f929bf36f876_1000x1000_grigUXkSW5.jpg',NULL,NULL,NULL,NULL,'Тверь',NULL,NULL,NULL,NULL,NULL,NULL),
	(126,1,1,'psikh','ПСИХ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/8nfw4pgayrepnib/67e1cb67b372c17bf27a9349685bb9945ac0559e_2560x1707_EpP98mNvjH.jpg',NULL,NULL,NULL,NULL,'Кирово-Чепецк',NULL,NULL,NULL,NULL,NULL,NULL),
	(127,1,1,'shead','SHEAD',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/egbrpjpm46eskb9/1f64afe4936afd01c6c410927928f1f943d828df_2160x2160_DgKIibFZA3.jpg',NULL,NULL,NULL,NULL,'Владивосток',NULL,NULL,NULL,NULL,NULL,NULL),
	(128,1,1,'indigo-stuff','INDIGO STUFF',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/2myzu73v89uhfhf/48b44c1c48e3e6cc6e180789b854807ffb8ace12_1200x1200_CzgSo99CKj.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(129,1,1,'enstore','ênstore',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/f50f0ha6twslhsk/25d47b6d9bafbc8eb352eec9529f2c8741b1bfbe_1280x720_pYVKbaPi6U.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(130,1,1,'oktopus','OKTOPUS',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ygroki48xp4vifa/0aea55976918dfc1e16143cc665922558f7f529a_640x719_khLz4jidai.jpg',NULL,NULL,NULL,NULL,'Рязань',NULL,NULL,NULL,NULL,NULL,NULL),
	(131,1,1,'empty-studios','Empty→studios',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/y5494yf7u2bq55t/4a3c87e4804036f5e23fe74f1d66882dd0702f77_719x719_ULSaARvsT4.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(132,1,1,'miracle-apparel','Miracle Apparel',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/xrkru838pho7dm0/02f493633319289c92dfcf4a0f5d0c08e0cfdca8_300x300_6D2N4s0Zhm.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(133,1,1,'liars-collective','Liars Collective',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/tezarbf09qigvxf/bc5ba80bcb673243889a699208b4da442c4c56b9_2000x2000_0ezIcDlQU9.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(134,1,1,'silence-clothing','SILENCE CLOTHING',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/g392q3213gt9i2u/f4df6ea9e5385095344b3d636ad9eeedd76d23e7_2222x2160_lZKyXJGD7f.jpg',NULL,NULL,NULL,NULL,'Дмитров',NULL,NULL,NULL,NULL,NULL,NULL),
	(135,1,1,'surfer-raincoats','Surfer Raincoats',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ix92vax3z9tw0ec/0b0dc6471f2da22ee46e9560ecbf1d6364210fb9_1363x1363_E9tChimpe8.jpg',NULL,NULL,NULL,NULL,'Сочи',NULL,NULL,NULL,NULL,NULL,NULL),
	(136,1,1,'mother-russia','Mother Russia',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ezpsonnl333idwv/53b9457f3cebb736e1c93330ed2f8ad2fa00d140_1175x1175_vl8gR9aFCx.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(137,1,1,'union-outfit','UNION outfit',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/r56q96gjtbokin0/d8b0f3e439644c2eb07d69c34eebed2cc826969d_1080x1080_7yWzeNOkph.jpg',NULL,NULL,NULL,NULL,'Екатеринбург',NULL,NULL,NULL,NULL,NULL,NULL),
	(138,1,1,'moe-made-on-earth','MOE (Made On Earth)',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/cl42cl29z8103l9/8ee6860bf88687a37ff99ae93938eaa2b8f3f0c8_2188x2160_3a9J4mXWGb.jpg',NULL,NULL,NULL,NULL,'Екатеринбург',NULL,NULL,NULL,NULL,NULL,NULL),
	(139,1,1,'rice','Rice',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ol2iuwj5z6m1tjq/61ba0dde73f5402dd787caf42c4ecb4a62e17983_1536x1536_Xdq91gSeRw.jpg',NULL,NULL,NULL,NULL,'Екатеринбург',NULL,NULL,NULL,NULL,NULL,NULL),
	(140,1,1,'1377','1377',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/i4d38kzp0nkvlc7/fe1b301a4cd7d134fc7d3e6562e86f23461b6f5f_1796x1796_wpWS9yYNW0.jpg',NULL,NULL,NULL,NULL,'Волгоград',NULL,NULL,NULL,NULL,NULL,NULL),
	(141,1,1,'dend','DEND',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/8aufxqc8vawo4u6/d84848d89a28ed0db834d0ea14a7978465176b90_1280x1280_HI8GJNyqpV.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(142,1,1,'tyaga-k-sportu','Тяга к спорту',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/ldyg2qh45ycfwgj/d8a75dbea22bf1a1373bb4baca8f4733f0b48c44_1000x1000_uawbuRnl5X.jpg',NULL,NULL,NULL,NULL,'Краснодар',NULL,NULL,NULL,NULL,NULL,NULL),
	(143,1,1,'fos-clothes','FOS clothes',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/tmt7uh9yuvsab7v/8c8364cde768db28f4f9d299d520fae3feb4fe45_1500x1500_b7NfOVYnV1.jpg',NULL,NULL,NULL,NULL,'Киров',NULL,NULL,NULL,NULL,NULL,NULL),
	(144,1,1,'called-a-garment','Called a Garment',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/qdmeike4s5yx68x/eeca98e24d282e6973445a2095ca17c6bcccde32_449x449_ypeb3iE6YC.jpg',NULL,NULL,NULL,NULL,'Нижний Новгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(145,1,1,'nika-wear','NIKA-WEAR',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/lkd1wxwfjoys6kj/0f3d30e2f85738a10d796a48c3e16a41ed7ddb41_2160x2160_hynlI6qO3k.jpg',NULL,NULL,NULL,NULL,'Новосибирск',NULL,NULL,NULL,NULL,NULL,NULL),
	(146,1,1,'overvest','OV (Overvest)',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/pnw0jktb8r2l09r/b5d4627d24f2841ae36f98377dc35d35a93fcc18_2000x2000_an5v02nubL.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(147,1,1,'daniil-kim','DANIIL KIM',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/4q9n3bmq2kmn239/88cc74431c31b20a5b1af6883f6786893f6b4032_2160x2160_rwFhdjP9sJ.jpg',NULL,NULL,NULL,NULL,'',NULL,NULL,NULL,NULL,NULL,NULL),
	(148,1,1,'bykov-atelier','BYKOV ATELIER',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/b8ukziyc5h5xs79/861ec091435c5ab9cd8d4942b5a01b0521023908_843x596_2pZqMYXPBP.jpg',NULL,NULL,NULL,NULL,'Тверь',NULL,NULL,NULL,NULL,NULL,NULL),
	(149,1,1,'yuge-yudzh-y-w','YUGE ЮДЖ *Y-----W )))',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/jub5rwzr5bxl18s/a3eae440ebdc04dd4625b781e0fa46f801cd0174_400x400_FtAAjGAYRu.jpg',NULL,NULL,NULL,NULL,'Краснодар',NULL,NULL,NULL,NULL,NULL,NULL),
	(150,1,1,'meow-one','meow\'one',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/wgl7g5wpblw79ob/75d0d1c8e2f3f0193d97f60816ea02b1ae664b02_602x602_2Tso3tHsuq.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(151,1,1,'podpol','ПОДПОЛЬЕ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/af5tu71mz1xiuph/d0c79c9630e91ff94118ff15b7fcbdf46a5e06a7_1620x2160_J9gRhCcAGo.jpg',NULL,NULL,NULL,NULL,'Самара',NULL,NULL,NULL,NULL,NULL,NULL),
	(152,1,1,'one-two','ONE TWO',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/44m2fjni1yejme9/1eaa24891b3d95cecdff35ad55d9cc65857d5c4b_836x836_yNiWeukjVP.jpg',NULL,NULL,NULL,NULL,'Новомосковск',NULL,NULL,NULL,NULL,NULL,NULL),
	(153,1,1,'boomzi','Boomzi',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/y8hwvn4sypp0eds/9b03147e428c1dd1c34e4dfc85a7dc7834b3e0f4_1280x783_8PKIveBqBq.jpg',NULL,NULL,NULL,NULL,'Грозный',NULL,NULL,NULL,NULL,NULL,NULL),
	(154,1,1,'zny','ZNY',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/iqeiuid6ichuhpi/a0104751ea0b9f73acca1b4f1b753bf3f15dbd02_998x998_RqukVvYAkm.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(155,1,1,'sergey-chernov','SERGEY CHERNOV',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/d63b0b1guoy7sj2/bc5daf4426f431e069a610fef016839687291a5d_828x795_OVhIxCtQPl.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(156,1,1,'chernoe-moloko','Черное Молоко',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/y5jw6421z3hb4sh/ed2c9c42221a4d5b92fd6142575ec7207d6bd92d_2160x2160_6fJahQXBUH.png',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(157,1,1,'antyqua','Antyqua',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/kddv3d037xu3t2f/a06f0b77c4c06a03c2f2278b72f523da8daf522a_1080x1080_t909PiO3zK.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(158,1,1,'anton-an-wearable','ANTON AN / AN.WEARABLE',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/g5ljp14kzk0n2d3/da65a4dc11dfd0c2f626df53131ea97203a458bd_2000x2000_88EwjygFaE.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(159,1,1,'ssanaya-tryapka','Ssanaya Tryapka',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/93spbnwrsv6ku3d/a8577efb03139f66d4d57a5b44a3958cc2407f71_1100x1100_TaU35aM4gl.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(160,1,1,'blk-crown','BLK CROWN',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/nht307l0rlve5cn/503201e277c6e94da1d84a11a5e50f4d73239464_1280x1278_6C57hMs4hG.jpg',NULL,NULL,NULL,NULL,'Тамбов',NULL,NULL,NULL,NULL,NULL,NULL),
	(161,1,1,'bad-habit','BAD HABIT',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/vmf1nxrpj7zgyi1/aeea5e9d78047b07bd80eca2895851754491c4ff_2083x2083_eNNBvvcAFd.jpg',NULL,NULL,NULL,NULL,'Ижевск',NULL,NULL,NULL,NULL,NULL,NULL),
	(162,1,1,'levitacia','_levitacia^',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/xntakfd1b4s4hl5/4fda2c3da4d29882d5ae2240df616a71e2e48061_1276x1280_0GZVFwb1j6.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(163,1,1,'sever','СЕВЕР',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/nwvap7ue8fsc31j/f4f6ba1dc4d211ce134bb2507463e64e493b0087_960x960_FixBJFhKEj.jpg',NULL,NULL,NULL,NULL,'Новосибирск',NULL,NULL,NULL,NULL,NULL,NULL),
	(164,1,1,'itsntfny','it\'s not funny',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/lnmc4rb2xbwr8os/7cf5fd4f6f44ff07e55ccf92fb8052fcede43432_1080x1080_f8xzTxciHh.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(165,1,1,'dyoubell','ДЮБЕЛЬ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/6k0wo77zjvd8qoc/2191c8eb45eba3ab30264cc2cc6935468d2ab126_2560x2105_q7OJie7vO7.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(166,1,1,'kolos','колос',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/lyoagqm7jphl5vv/30be739bcad2ec6f72b63742b1e0749375442cc3_1080x1080_ESKT4ZV579.jpg',NULL,NULL,NULL,NULL,'Красноярск',NULL,NULL,NULL,NULL,NULL,NULL),
	(167,1,1,'ping-tablet','PING TABLET',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/9lsp2sgfsps8aaa/e5e6fa7077f6aaef12b6ef369cea572d9a2cb361_1080x1080_DiAqdtYAdk.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(168,1,1,'ruslan-sayfutdinov','RUSLAN SAYFUTDINOV',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/o1d9ossytfr0t9e/a006290fe733e35fb1310ee5376c4d637079bf9e_1080x1080_YhCXPXMgWl.jpg',NULL,NULL,NULL,NULL,'Самара',NULL,NULL,NULL,NULL,NULL,NULL),
	(169,1,1,'belle','BELLE',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/9exwizrwv2kuxo2/887b2eb42273b5a8146c6c9ee0db10ac8a1fb5a0_1080x1082_jaIK3WgdXE.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(170,1,1,'murmurizm','MURMURIZM',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/cqhx60guoezhs1a/e15dab7f2311535d7c24f37fa61b1571b1107726_750x750_AkFORgykWO.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(171,1,1,'creepy-clothing','CREEPY CLOTHING',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/thwbjearuqjw0v1/837c1db5a232c370b1bb1fd2556687fb5db5e940_1244x1244_JNZAnFyJ6H.png',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(172,1,1,'rassvet','РАССВЕТ',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/n5fanua106va9l9/09f3a91069771988b56d1c53177b40257272de0d_1080x1080_qfrZwj1Nt6.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(173,1,1,'siyanie','СИЯНИЕ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/qbnkh2wbajo1zld/d75fee0f8d4102ebcaa7113041feee8143dcda2f_2560x2141_RQcNey1m9z.png',NULL,NULL,NULL,NULL,'Нижний Новгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(174,1,1,'arrier-gritte','Arrier Gritte',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/szgvvkkig9agxvp/91897a8d6be375d8416175b56d1f2a174402dd80_1174x1080_MDdIpLvHyz.jpg',NULL,NULL,NULL,NULL,'',NULL,NULL,NULL,NULL,NULL,NULL),
	(175,1,1,'torch','Torch',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/4b5i7fg1si7i6bn/192e12809c79bc24de00168364306dc0864e6c04_2160x2160_zjDWge6PMP.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(176,1,1,'scenario','Сценарий',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/b2iexup4gqdafas/2c2ae8a7f06e20958acb99fa428d4255702f5433_1080x1080_hecY8wC5i2.jpg',NULL,NULL,NULL,NULL,'Тюмень',NULL,NULL,NULL,NULL,NULL,NULL),
	(177,1,1,'province','ПРОВИНЦИЯ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/qz4s7o85yu9ffzp/51fd2d43d1ee79250e11b94598d6ab044f22c092_1080x1080_iOHMaPGY7z.jpg',NULL,NULL,NULL,NULL,'Нижний Новгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(178,1,1,'heartz','HEARTZ',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/bafld1brdfyg4fx/5140281af9b987e3caee992670bf66aa0e2f593a_1920x1920_cVbmuuRWQF.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(179,1,1,'yalta-94','YALTA 94',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/ntabn4iizj6csph/8fef0aac9b70e2af50a9477d95e0f9a043e6c3a1_907x1080_cAcVesLiki.jpg',NULL,NULL,NULL,NULL,'Пермь',NULL,NULL,NULL,NULL,NULL,NULL),
	(180,1,1,'omenboyz','Омен Бойз',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/svj8d6ca8bicbmo/0977e87f076c82b1d5abc309f4ee20368640f684_1280x1280_WRtBvSsYhO.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(181,1,1,'pepel-moikh-vragov','ПЕПЕЛ МОИХ ВРАГОВ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/udx3q0vule93nu4/89a72fc5c4f5928f647b28c92f6086fdace97eac_2560x1707_jVYpwN97il.jpg',NULL,NULL,NULL,NULL,'Самара',NULL,NULL,NULL,NULL,NULL,NULL),
	(182,1,1,'myza','MYZA',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/pnp9hxxzjr4wlbe/61cc1695c4b1f25582da13f172b716b0088062e8_1024x1024_mnhaocZk4B.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(183,1,1,'last-seen','LAST SEEN',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/e0z06dkssuma1kc/5cffc829409032d7bcfe61aadb58e898a90119b8_1080x1080_nj4OucbXc8.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(184,1,1,'haliky','Haliky Clothing',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/itgce6q7ubytcey/80b8225831bbd125275b50d4ab781c428a887538_1080x1080_V0BGMo2LLW.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(185,1,1,'fuckingsquare','FUCKINGSQUARE',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/c2mxdh1h4kbvoz9/c3ff450d5bae92079986c88194e893a82ecb0703_1080x1080_WOUtyARbiB.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(186,1,1,'ymkashix','YMKASHIX',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/nqh687c3v9ocwf0/71fa74a0cd782afb674807a17a46d414dbb7d70b_1000x1000_jWS1zl9F2s.png',NULL,NULL,NULL,NULL,'Екатеринбург',NULL,NULL,NULL,NULL,NULL,NULL),
	(187,1,1,'polosa','ПОЛОСА',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/anm8sys1sb1bd96/0b03a756aca58eaf38c86eff3143e13647598666_1000x1000_euO67ybWsQ.jpg',NULL,NULL,NULL,NULL,'Нижний Новгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(188,1,1,'victorian-fall','VICTORIAN FALL',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ei7r25r2radlgfq/01efa813501f43acc72e12987fe289bbf335a8b4_1080x1080_4O1P119YuY.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(189,1,1,'automatic-vertical','AUTOMATIC VERTICAL',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/j97v5ylridm18zh/4efc8d4b2765fbbd9df9444b07930c22022a7c3c_800x800_JVoCWKrJlX.jpg',NULL,NULL,NULL,NULL,'Екатеринбург',NULL,NULL,NULL,NULL,NULL,NULL),
	(190,1,1,'bat-norton','Bat Norton',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ndpmca572s0q5aa/fbaf8935f01bb35405d23553e6c9ee66ff1f81d4_2000x2000_u1Pl77kMlH.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(191,1,1,'gore','Горе',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/qlb5ssta5o2ch19/6a539ba5e3b37b3ba48bbe46b11bad4bb76594fc_1049x1050_GXeuZaDAfT.jpg',NULL,NULL,NULL,NULL,'Тула',NULL,NULL,NULL,NULL,NULL,NULL),
	(192,1,1,'paradiz','Парадиз',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/wvfwl89rqubuqeq/572c7631befc888a0928ec35b81aa935b9fb55f7_1280x1024_f1vjKl2Iq2.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(193,1,1,'kruzhok','Кружок',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/20vzrcolta0k0vb/f7d2f6c8e64775f87a8f94329955ed9d8d290df3_2000x2000_swnr5JRWEK.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(194,1,1,'volchok','ВОЛЧОК',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/x5dgn0d06ejyws5/904a49bddaa683c9918645cdc311e0e7386ce03a_1080x1080_scOy3OdIlA.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(195,1,1,'yunost','ЮНОСТЬ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/f3lmfxl0uck5ejq/60e222025251e30e3c4c06aa74a40de2ab184a94_1080x1080_a573p498f8.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(196,1,1,'fiction','FICTION',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/jyvggw0qtksm6vc/30567e0a9301f2ecd313b0c67543dd69c8844220_807x807_RWxOaingDL.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(197,1,1,'by-matsumura','by Matsumura',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/tzhb9fdyc9b9k0r/ff3b74cbf6d13d5f7d0dc9207108981dfb2850f2_400x385_O5GHfluZCM.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(198,1,1,'snakejaws','Snakejaws',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/lc23795xwg1ysfk/820158216908e75aa34e3f0089978c2e27583923_807x807_cYCQL7Q2wX.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(199,1,1,'kultura','KUL\'TURA',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/tpv0czgcj9231u3/023a44ef37edfb525ed52f821dac4c3634b7e6f0_800x800_eiTfWg8rP0.jpg',NULL,NULL,NULL,NULL,'Краснодар',NULL,NULL,NULL,NULL,NULL,NULL),
	(200,1,1,'circle-of-unity','CIRCLE OF UNITY',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/gm06hvn3d7399uf/41c6db15976d95bd0807c3de53b0cccd767a164f_250x244_Y9PGVixIjI.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(201,1,1,'medooza','MEDOOZA',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/dcvzc84ebsdscte/86f7179d7ab78a35b35deee472b9f4b5a973e566_807x807_pFmIGiGiez.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(202,1,1,'creepy-crawl','CREEPY CRAWL',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/7aljpw48fm78yr1/4ea1d282c8f31306cb4987303005f4c5c883eca4_459x679_WiMtwcEJ8j.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(203,1,1,'molotov','MOLOTOV',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/lwym5wi8wtrlebe/359e36385623be677ecab103574c641b4a235138_1080x1080_7Zh9otKVsR.jpg',NULL,NULL,NULL,NULL,'Пермь',NULL,NULL,NULL,NULL,NULL,NULL),
	(204,1,1,'krakatau','KRAKATAU',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/b9pgv1ju56i9g7g/bce2cc23599a307e815498cbcf6f079d086cdc0d_1080x1080_CeE8xhbprC.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(205,1,1,'mech','МЕЧ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/t14neoa41aela40/0294699b046e6f52e5ded46f777ac187b10bf71f_1080x1080_tC59Bk06nG.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(206,1,1,'ritmika','RITMIKA',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/z6z7ogck9e5ouzm/39bb76f381b62ade8db56c389ae00813f5854d8c_1120x1080_bMrFecRQtk.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(207,1,1,'taiga','Тайга',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/mo5f9bhbu723gnh/0a80f20bae0785b1c11c7f5a5a7a2e4f54017761_682x1024_uoRHLKXhBx.jpg',NULL,NULL,NULL,NULL,'',NULL,NULL,NULL,NULL,NULL,NULL),
	(208,1,1,'codered','CODERED',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/56t16v9ycrgqykt/86b621ec0a7fac94c9684c80b5afa7512df2c16c_944x944_ZyWWJKsbjq.jpg',NULL,NULL,NULL,NULL,'',NULL,NULL,NULL,NULL,NULL,NULL),
	(209,1,1,'sputnik1985','СПУТНИК1985',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ceir609s418qoi5/fe2c436b44e48040c157a28e01aa553b516a3986_1080x1080_aRgbkC7A5Y.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(210,1,1,'bich','БИЧ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/58qc9mfcd5wonwv/818fd97068d257c7d38112857b258f516b6fed7d_1080x1080_aveGmKGzre.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(211,1,1,'anton-lisin','АНТОН ЛИСИН',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/hed2zvir90o8z84/efd9b538da9604b73a09d2fb65ab8c004287c93c_2160x2160_soutHVI2DL.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(212,1,1,'wolee','Wolee',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/8mgpx47p554d79w/93ca9a3927399e049ce9389f4a2fbef58c4168a8_720x720_8m6TV6zWdp.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(213,1,1,'cu8e','NAAP* ARCHIVE',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/id7txdlcgdarzqf/a7756a5096ff717c1816b5b60db4baa723be6d8d_604x859_XXjwQ6q8fE.jpg',NULL,NULL,NULL,NULL,'Иркутск',NULL,NULL,NULL,NULL,NULL,NULL),
	(214,1,1,'marcelo-miracles','Marcelo Miracles',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ksn7tcsd6yv7v77/c00c136587217aced70d2db5e6ee566c35066051_2160x2160_M4Pv3Kpx8E.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(215,1,1,'some-people','Some People',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/p22uzn5t0y6nqti/ae54014865a54fcc1bf07bdf4eee023e366d5755_320x320_29eaRYwuhb.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(216,1,1,'icon-fire','ICONEFIRE',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/c2guqd229h2xam5/blob_K3YnqnjeFO.png',NULL,NULL,NULL,NULL,'Владивосток',NULL,NULL,NULL,NULL,NULL,NULL),
	(217,1,1,'elapse-space','Elapse Space',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/7xfgopd2cmlqjf3/1687685534008_0_ios_l_xece_a_xRm2xEroqa.jpg',NULL,NULL,NULL,NULL,'Омск',NULL,NULL,NULL,NULL,NULL,NULL),
	(218,1,1,'swagga-luv','SWAGGA LUV',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/cxw4p919rovhazf/swagga_logo_UMIZ64JTf1.png',NULL,NULL,NULL,NULL,'Калининград',NULL,NULL,NULL,NULL,NULL,NULL),
	(219,1,1,'decharge','DECHARGE',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/y4sfrle2tyu01w7/1688239507613_dolyame_logo_svg_U4mJxEcl5y.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(220,1,1,'iodes-brand','Iodes brand',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/t4icz3wsqvegz3u/1688494334318_j_bca_it_aqk_ji_zejAenFQxx.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(221,1,1,'ascension','ASCENSION',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/p9pxlv38acv92lc/1688883331553_img_7952_EquzmRTXIB.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(222,1,1,'4someclo','Fosome',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/l980svw1x05ga7u/1688884252677_forsom_logo_qDmXtaDJEf.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(223,1,1,'wearenotdvos','D\'VOS',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/r5j0lo8t80nkhyp/1689710162859_r_q_rt_q6_eu_y_qF6CkxafQS.jpg',NULL,NULL,NULL,NULL,'Ярославль',NULL,NULL,NULL,NULL,NULL,NULL),
	(224,1,1,'onyspb','ONYSPB',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/kjaow5iw00li3dm/1693892114699_ony_logo_6Aae5UJiYH.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(225,1,1,'parsec-design','Parsec Design',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/4bipf77n0i8rp61/1697371955957_logo_4v4ZmGDyC0.jpg',NULL,NULL,NULL,NULL,'Нижний Новгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(226,1,1,'blackfisk','BLACKFISK',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/8218vbi86lwtvnx/1698755835633_blackfisk_logo_sYIhwP7Aqk.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(227,1,1,'current','current',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/2jdh147nw3cu88f/1701805698411_current_logo_8vKRsN1EbM.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(228,1,1,'elyseewaylone','Élysée Waylone',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/r7h8iimoru4tjne/1702498746902_waylone_logo_KH5CkaoUUh.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(229,1,1,'streetvir','StreetVir',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/q92m53rragvctim/1705341109856_streetvir_logo_pU5gAoxIRN.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(230,1,1,'riverspot','Ривер Спот',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/5fq85pocht4sy1d/1705341681973_riverspot_logo_v1VsSiYni5.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(231,1,1,'stormwear','шторм.',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/z8n50akjb0s8yid/1706514887526_storm_logo_nBMVemNVeK.jpg',NULL,NULL,NULL,NULL,'Иркутск',NULL,NULL,NULL,NULL,NULL,NULL),
	(232,1,1,'brulerdamour','Bruler d’Amour',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/z5j0q66jytabdv6/1709453865622_bruler_logo_ioqqkxWNnp.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(233,1,1,'hokudynasty','HOKU DYNASTY',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/8qaq27xqv9ltb0j/1706897451629_hokudynasty_logo_XbVF0IEuc4.jpg',NULL,NULL,NULL,NULL,'Ноябрьск',NULL,NULL,NULL,NULL,NULL,NULL),
	(234,1,1,'navajofrommoscow','NAVAJO',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/21bx9u0qi9yjvhg/navajo_logo_x9H9qDySVC.jpg',NULL,NULL,NULL,NULL,'Видное',NULL,NULL,NULL,NULL,NULL,NULL),
	(235,1,1,'qsswear','QSS',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/6cy3flookv8dvtj/qss_6SqLpao796.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(236,1,1,'blanblan','BlanBlan',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/un6urko1ks5pzao/blanblan_logo_GeK0dCniB3.jpg',NULL,NULL,NULL,NULL,'Саратов',NULL,NULL,NULL,NULL,NULL,NULL),
	(237,1,1,'heartburn-moscow','HEARTBURN',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/9a6c9pifh8wfvxt/heartburn_logo_UBz7Feao9A.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(238,1,1,'gardendollbones','Garden Doll Bones',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/zuwqshz19tnymrv/gdb_logo_GhwisakjZQ.jpeg',NULL,NULL,NULL,NULL,'Уфа',NULL,NULL,NULL,NULL,NULL,NULL),
	(239,1,1,'avese','AVESE',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/3ftvxw81070xhfw/avese_logo_Cs2EiHKChg.png',NULL,NULL,NULL,NULL,'Великие Луки',NULL,NULL,NULL,NULL,NULL,NULL),
	(240,1,1,'yagatex','YAGA',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/7y92eacj9kx0jdg/yaga_logo_5IHn9Ul4PX.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(241,1,1,'ne-baza','NE BAZA',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/mlqzepx1bf4xgn7/nebaza_logo_8hWQDKsdqa.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(242,1,1,'dap86','DAP’86',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/nbcwfrfbcn3dodh/dap86_logo_nvyVEAAbAl.jpg',NULL,NULL,NULL,NULL,'Владивосток',NULL,NULL,NULL,NULL,NULL,NULL),
	(243,1,1,'estrinstore','ESTRIN',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/fs3u0mqeeiygrj2/estrin_logo_DLH0d6fyw5.jpg',NULL,NULL,NULL,NULL,'Ростов-на-Дону',NULL,NULL,NULL,NULL,NULL,NULL),
	(244,1,1,'maori-industrial','MAORI INDUSTRIAL',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/dzi3704xt6egt22/maori_logo_yEienjZSVZ.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(245,1,1,'artinclo','ART IN CLO',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/7vhdx8wtrgkj3zw/artinclo_logo_bJZ1Rbjsp6.jpg',NULL,NULL,NULL,NULL,'Иваново',NULL,NULL,NULL,NULL,NULL,NULL),
	(246,1,1,'mysh-goroshek','Мышиный горошек',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/cx875yxhint1pra/mysh_logo_aMKX62pGw3.jpg',NULL,NULL,NULL,NULL,'Тюмень',NULL,NULL,NULL,NULL,NULL,NULL),
	(247,1,1,'silencepact','Silence Pact',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/hv48fzsjbbqr6bz/silencepact_logo_LmMKhkvotm.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(248,1,1,'borozna','borozna',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/d6pbripuqj0hbaq/boronza_logo_UiRrz7GE1N.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(249,1,1,'horosho-studio','Заново',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/6cngtbe62rqmij6/photo_2025_03_08_16_03_36_9ISVTeR0yv.jpeg',NULL,NULL,NULL,NULL,'Петрозаводск',NULL,NULL,NULL,NULL,NULL,NULL),
	(250,1,1,'industryporchi','INDUSTRY PORCHI',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/snp2v05dd23rq6j/photo_2024_05_18_09_21_49_kGfrEif78m.jpeg',NULL,NULL,NULL,NULL,'Хабаровск',NULL,NULL,NULL,NULL,NULL,NULL),
	(251,1,1,'wakewear','Wake Wear',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/8k5r9muls7djoep/photo_2024_05_26_21_20_34_4JdCCLtYLi.jpeg',NULL,NULL,NULL,NULL,'Ростов-на-Дону',NULL,NULL,NULL,NULL,NULL,NULL),
	(252,1,1,'frostyclothes','unterwens.',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/zwy4wlyixilkil8/blob_rW3q2HWCFy.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(253,1,1,'legashion','LEGASHION',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/yvmvxf0mnt73h66/photo_2024_06_08_13_09_23_vBpuH27Vp0.jpeg',NULL,NULL,NULL,NULL,'Воронеж',NULL,NULL,NULL,NULL,NULL,NULL),
	(254,1,1,'bright-moda','BRIGHT',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/360uqpbd6hvytxh/photo_2024_06_08_13_14_29_GYe4MXxx86.jpeg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(255,1,1,'yo-privet','ПРИВЕТ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/amzvfvvciwueuse/privet2_ARFTRjZZx8.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(256,1,1,'young-wild-free','YOUNG WILD FREE',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/y5zl2umctab5lwi/young_wild_free_logo_FGrzYt08Ao.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(257,1,1,'4dalocals','4DALOCALS',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/6h6wv6fst67mmsi/4dalocals_logo_kBVgAPsbQb.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(258,1,1,'dasupcycling','/DAS/',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/cx24aqw9skg79vt/das_logo_CFu5700Vmr.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(259,1,1,'rubingear','RubinGear',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/2qc4ztudlczdbf5/photo_2024_07_07_10_12_16_ofbNc3QhQr.jpeg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(260,1,1,'expodiumstudios','Expodium Studios',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/xqyhx7l53rs6v48/photo_2024_12_17_19_52_12_4MjYKCuWCN.jpeg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(261,1,1,'lessthanthree','Less Than Three',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/h95d6htx4irx9mz/lessthantree_logo_Ncir04evKb.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(262,1,1,'twice2five','2х2=5',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/3p5pue0bb5k4gs4/photo_2024_07_25_13_20_59_z8ZhbkafmL.jpeg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(263,1,1,'rodina','Родина',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/drzkzn8r2pvgahs/rodina_logo_x5GqgQ4U1y.png',NULL,NULL,NULL,NULL,'Нижний Новгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(264,1,1,'pirosmanistudio','Pirosmani',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/kyojhnrxfx2mpst/pirosmani_logo_7cMirpR9TI.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(265,1,1,'hellcatbrand','HellCat Brand',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/opvgecefztgqkg2/photo_2024_08_09_17_13_27_3pta8sWxei.jpeg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(266,1,1,'murkafam','MURKA',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/0adnl88lx9kch6s/ava2_T6Hxs9QDZZ.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(267,1,1,'forzeclo','FORZE',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/e0cruh9hxukc3w0/forzeclo_logo_HcnLiOOJ0J.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(268,1,1,'dissonanspokoleniy','Диссонанс Поколений',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/2fqxtc5x1s0nb3p/dissonans_pok_logo_YfoLeFCz1b.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(269,1,1,'panelkawear','Панелька',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/nxu5jq57d0t12wm/panelka_logo_S60ukv5ExQ.jpg',NULL,NULL,NULL,NULL,'Нижний Новгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(270,1,1,'plstorage','PL STORAGE',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/530ije6vvbd6sbe/pl_storage_logo_FozVb82NLB.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(271,1,1,'xbrandru','XBRAND',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/q55tcsc71iuzyga/xbrand_logo_A8xMsBl6nj.jpg',NULL,NULL,NULL,NULL,'Хабаровск',NULL,NULL,NULL,NULL,NULL,NULL),
	(272,1,1,'gaduka','Гадюка',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/2c6la3tnerfc9jy/gadyuka_logo_apKEaTjnSL.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(273,1,1,'ruff-global','RUFF GLOBAL',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/u251on65v6i8ygo/ruff_logo_RmAXMqoxYL.jpg',NULL,NULL,NULL,NULL,'Нижний Новгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(274,1,1,'hardlunch','HARDLUNCH',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/nj8ytea1rz1a2rd/hardlunch_logo_jBUi2ZKqgR.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(275,1,1,'lebedinskiii','LEBEDINSKI',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/qstypw5d6gz8bwj/lebedinskii_logo_9rlr94vnu7.jpg',NULL,NULL,NULL,NULL,'Саратов',NULL,NULL,NULL,NULL,NULL,NULL),
	(276,1,1,'suburban-society','Suburban Society',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/91d0ehz0g9e53pu/subboy_logo_sQv18n20UB.png',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(277,1,1,'zagon','ZAGON',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/so1bdqp96nv26zu/zagon_logo_dbnHEWAB3Y.jpg',NULL,NULL,NULL,NULL,'Казань',NULL,NULL,NULL,NULL,NULL,NULL),
	(278,1,1,'etonus','ETONUS',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/9rjpaiit2yykdqa/etonus_logo_cKC51EMmBd.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(279,1,1,'knives-wear','Knives',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/d0e1uipo1y8su34/knives_logo_0Una7gmnZd.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(280,1,1,'dvor','ДВОР',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/75l40jr723u3vs5/dvor_logo_Q3cvv8v2Fg.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(281,1,1,'zloe','ЗЛОЕ',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/tu25z3pptsdsrpf/zloe_logo_aLdI91knes.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(282,1,1,'hrdcr-clothes','HRDCR',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/7pp1xmc2pu3iz45/blob_dBDgrhew8R.jpg',NULL,NULL,NULL,NULL,'Пермь',NULL,NULL,NULL,NULL,NULL,NULL),
	(283,1,1,'anemoia','Anemoя',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/xreql1ygsuzhtp1/anemoia_logo_kjF3KOJ1PA.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(284,1,1,'yuzhka','yuzhka',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/3xbbfjlfqxppcxj/photo_2024_11_25_17_45_32_kYSmbUUcvO.jpeg',NULL,NULL,NULL,NULL,'Пенза',NULL,NULL,NULL,NULL,NULL,NULL),
	(285,1,1,'marideniz','MariDeniz',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/y1b2klgpp980wu5/marideniz_CAeHJB6RUG.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(286,1,1,'fearcorp','FEAR',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/opm85j9ld1hi2vw/fear_brand_logo_OXlnvIF2LU.png',NULL,NULL,NULL,NULL,'Брянск',NULL,NULL,NULL,NULL,NULL,NULL),
	(287,1,1,'anblush','AnBlush',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/u2swmburo7isytr/anblush_logo_3LC3CPreXu.jpeg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(288,1,1,'livingchill-club','Living & Chill Club',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/8yllg2sycomt1h4/livingchill_logo_uIBeYz13cB.jpg',NULL,NULL,NULL,NULL,'Челябинск',NULL,NULL,NULL,NULL,NULL,NULL),
	(289,1,1,'puntaderizo','PUNTA DE RIZO',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/vyiubsdf2qgd82y/puntaderizo_logo_F9nZpIEmgz.jpg',NULL,NULL,NULL,NULL,'',NULL,NULL,NULL,NULL,NULL,NULL),
	(290,1,1,'4est-clothes','ЧЕСТЬ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/cplbjzb1wfimskc/chest_logo_Xx3oe7drfm.png',NULL,NULL,NULL,NULL,'Санкт-Петербург ',NULL,NULL,NULL,NULL,NULL,NULL),
	(291,1,1,'abnormal','Аномальные',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/ibz6c962alcwlrv/abnormal_logo_ycpUQK0yq3.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(292,1,1,'genki-apparel','Genki',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/0wu5m1jg5e9apzx/genki_logo_TXbZvpCysc.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(293,1,1,'ciem','CIEM',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/jabjqh6w4fdl3yy/photo_2025_01_09_12_09_02_6ZmUIpe7ie.jpeg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(294,1,1,'silentmove','SILENT MOVE',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/pu4y1odfobdctbb/silent_move_logo_zo7VAF7USE.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(295,1,1,'solarhead','Solarhead',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/nhpctbu5s7z3lmw/solarhead_logo_1mqbYLMprU.png',NULL,NULL,NULL,NULL,'Екатеринбург',NULL,NULL,NULL,NULL,NULL,NULL),
	(296,1,1,'tumsoev','TUMSOEV',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/uxcvfs4ukceyu66/tumsoev_logo_tas32Nd3om.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(297,1,1,'materiabrand','Materia Lab',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/zoxoznvuji6w8w3/materialab_logo_5vzsULBgTc.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(298,1,1,'rat-province','Rat Province',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/2hihxwacw12ynqy/rat_province_logo_g0FpHsqmrb.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(299,1,1,'abc-clothes','ABC Clothes',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/3e0so7vfsi51typ/abc_logo_ZcAJvT8k37.jpeg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(300,1,1,'drainbrand','D\'RAIN',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/arbjn2hke9xjm9g/drain_logo_yXq7RwJhRa.jpeg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(301,1,1,'brightfuture','BRIGHT FUTURE',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/4ow0jpvpkx8pf25/bright_future_logo_CwvPWgb6lH.jpeg',NULL,NULL,NULL,NULL,'Казань',NULL,NULL,NULL,NULL,NULL,NULL),
	(302,1,1,'biogothica','BIOGOTHICA',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/klqag8evb13og76/biogothica_logo_A0Q7cwRJtG.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(303,1,1,'bezdna','БЕЗДNА',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/vzpjhva10uczm5y/bezdna_logo_hRVeAxF2pn.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(304,1,1,'ollytechrus','OLLYTECH',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/k47xs1ta5rerl56/ollytech_logo_Y2AlIOIIIX.jpeg',NULL,NULL,NULL,NULL,'Стерлитамак',NULL,NULL,NULL,NULL,NULL,NULL),
	(305,1,1,'why-shmot','Why Shmot',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/hasjk8xefcjh22r/why_shmot_logo_aFHXToYja4.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(306,1,1,'bonnetion','bonnetion',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/04rpmu1d1fqwa1g/img_7454_xEmzf1WAsI.jpeg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(307,1,1,'chebabusiki','CHEBABUSIKI',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/fmcnoaglg3mh5yj/chebabusiki_logo_wA9cYF0y06.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(308,1,1,'street-svet','Street CBET',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/p54b6g1xehjj788/street_svet_logo_Jr4umdOZbZ.jpeg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(309,1,1,'ghosta','Ghosta',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/mzchtxjits2r8bq/ghosta_logo_rorlOlVgcZ.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(310,1,1,'dieyoungwear','die young',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/tr2fxfucqmo0c07/dieyoung_logo_LOoDpfWnIG.png',NULL,NULL,NULL,NULL,'Пермь',NULL,NULL,NULL,NULL,NULL,NULL),
	(311,1,1,'lakbi','Lakbi',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/l6t8wgm1a3eln3t/lakbi_logo_ZhTOpnbiN2.png',NULL,NULL,NULL,NULL,'Смоленск',NULL,NULL,NULL,NULL,NULL,NULL),
	(312,1,1,'nishtyakbratok','«Ништяк Браток»',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/lq620h8zxklsqr9/nb_logo_F6D5gjZw4C.jpg',NULL,NULL,NULL,NULL,'Йошкар-Ола',NULL,NULL,NULL,NULL,NULL,NULL),
	(313,1,1,'o-teplo','о.тепло',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/zp2lk6udtlrwvbt/o_teplo_j3igDWi6BC.png',NULL,NULL,NULL,NULL,'Йошкар-Ола',NULL,NULL,NULL,NULL,NULL,NULL),
	(314,1,1,'detsroycl','detsroy',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/qbagajcrurr1vco/detsroy_logo_yGWXeIIe62.jpeg',NULL,NULL,NULL,NULL,'Североморск',NULL,NULL,NULL,NULL,NULL,NULL),
	(315,1,1,'cortisolj','Кортизол',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/jgxn05ouxbb9zfa/kortizol_logo_UDhpmShuSW.jpeg',NULL,NULL,NULL,NULL,'Нижний-Новгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(316,1,1,'matteone','matteone',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/pjjuixxbrb5bdbe/matteone_logo_black_1hCGieTuQT.jpg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(317,1,1,'zemlyaki','ЗЕМЛЯКИ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/j7u98ctycbzi2c2/zemlyaki_logo_lp9qASHF5U.jpeg',NULL,NULL,NULL,NULL,'Киров',NULL,NULL,NULL,NULL,NULL,NULL),
	(318,1,1,'zipatch','ZIPATCH',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/cthg8mbke0h9qzu/zipatch_logo_for_rswc_EBE7Th661l.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(319,1,1,'wahhid','wahhid',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/i7guo0fk5k0nzka/img_1491_JehJBjNlgB.png',NULL,NULL,NULL,NULL,'Махачкала',NULL,NULL,NULL,NULL,NULL,NULL),
	(320,1,1,'mesaj1980','MESAJ 19/80',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/usz3hh0w603ipzf/mesaj_1_XgkmG9awVH.jpeg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(321,1,1,'ylll','YLLL',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/6q66qdo19ikzim7/ylll_7_qq8La3euTt.jpeg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(322,1,1,'octopusbones','OCTOPUS BONES',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/7kierrnwtls8zuk/octopusbones_logo_1WsfHynzi8.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(323,1,1,'foursideis','Four Sides',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/rff1in0j8e7pwub/foursideis_7_ghnrnnX6Yx.jpeg',NULL,NULL,NULL,NULL,'Тверь',NULL,NULL,NULL,NULL,NULL,NULL),
	(324,1,1,'ssswear','SSSWEAR',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/yfj9r2ah0mmbh6m/ssswear_1_temVw26G9T.jpeg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(325,1,1,'strongwill','STRONGWILL',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/xkot6wgaeb87f0c/strongwill_7_EjhCTgGzTV.jpg',NULL,NULL,NULL,NULL,'Санкт-Петербург',NULL,NULL,NULL,NULL,NULL,NULL),
	(326,1,1,'splashgear','Splash!',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/ep3649lrp0b2e4i/photo_2025_07_24_07_38_12_L0cq4P81pG.jpeg',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(327,1,1,'luna-moscow','LÚNA MOSCOW',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ltpyh79g39vtamx/luna_logo_ETeBNIwrNc.png',NULL,NULL,NULL,NULL,'Москва',NULL,NULL,NULL,NULL,NULL,NULL),
	(328,1,1,'smlerch','Мэрч',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/x2yl06tprrx00ao/logo_maerch_xgyujVSQZk.png',NULL,NULL,NULL,NULL,'Брянск',NULL,NULL,NULL,NULL,NULL,NULL),
	(329,1,1,'enkomin','Enkomin',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/cwgw13kseapbdmr/photo_2025_08_15_22_30_37_BRUlqMmvVD.jpeg',NULL,NULL,NULL,NULL,'Ставрополь',NULL,NULL,NULL,NULL,NULL,NULL),
	(330,1,1,'vityaz','ВИТЯЗЬ',0,0,'2025-11-07 09:15:14','2025-11-08 12:14:48','active','/api/files/brands/rvl3i9h905jdzsr/photo_2025_09_30_10_51_09_iVoZZOzcdw.jpeg',NULL,NULL,NULL,NULL,'Краснодар',NULL,NULL,NULL,NULL,NULL,NULL),
	(331,1,1,'sobachka-brand','SOBACHKA BRAND',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/5pkzify9mryu1dy/photo_2025_09_30_10_59_05_uWgHpMDrkY.jpeg',NULL,NULL,NULL,NULL,'Тула',NULL,NULL,NULL,NULL,NULL,NULL),
	(332,1,1,'comfers','Comfers',0,0,'2025-11-07 09:15:14','2025-11-07 09:15:14','active','/api/files/brands/ivy3j5w2lb1ln7c/logo_comfers_2025_black_HAAnXBHUIR.png',NULL,NULL,NULL,NULL,'Нижний Новгород',NULL,NULL,NULL,NULL,NULL,NULL),
	(333,2,2,'gate31','GATE31',0,0,'2025-11-07 09:32:52','2025-11-07 10:11:32','active',NULL,'GATE31 — это минималистичный бренд одежды из Санкт–Петербурга. \r\n\r\nМы стремимся к инновациям и создаем современную классику для женщин и мужчин всех возрастов, ведь продуманный базовый гардероб актуален для любого поколения.\r\n\r\nЭто концепция чистоты и минимализма, коллекции предельно красивых в своей простоте вещей, где главными становятся содержание и функция.','https://www.gate31.ru/','shop@gate31.ru','8 800 333 72 31','Россия, г. Санкт-Петербург','GATE31 — это минималистичный бренд одежды из Санкт-Петербурга\r\nМы начинали как магазин российских и корейских дизайнеров. Сегодня GATE31 — это полностью локальная марка с собственным производством.\r\n\r\nИСТОРИЯ\r\nGATE31 был основан Денисом Шевченко в 2015 году. В основе бренда идея о том, что мода должна быть простой и продуманной.\r\n\r\n«В начале думал, потом анализировал, затем делал то, что хотел видеть. Так получился GATE31 — не тренд, а образ мысли и жизни.»\r\n\r\nНАША ЦЕЛЬ\r\nМы ежедневно работаем над тем, чтобы способствовать развитию отрасли в нашем регионе. Ведь главное — это возможность создавать продукт внутри страны, а вместе с ним — рабочие места в России. Мы выпускаем современную одежду, ценим честное отношение, дарим положительные эмоции и получаем удовольствие от работы.\r\n\r\nНАШИ КОЛЛЕКЦИИ\r\nНаша философия сочетает современный дизайн с классическим стилем. Красота в простоте, но функция выходит на первый план.\r\n\r\nИНДИВИДУАЛЬНОСТЬ\r\nМы не делим коллекции на привычные сезоны, а создаем изделия здесь и сейчас, выпуская новые модели каждую неделю. Нам важно слышать наших единомышленников и оперативно создавать то, что вы хотите видеть. Все наши модели изготавливаются небольшими партиями, что делает сам продукт уникальным и позволяет избежать перепроизводства.\r\n\r\nНАШИ ДИЗАЙН-ЦЕННОСТИ\r\nВневременная, но современная, наша эстетика дизайна интерпретирует классические элементы для реальных потребностей повседневной жизни. Просто — это ещё не значит дёшево. Мы стараемся не нагромождать наши изделия излишними деталями и не гонимся за дизайном ради дизайна. Для нас дизайн — это практичность. И считаем, что меньше — лучше.\r\n\r\nНАШЕ ВДОХНОВЕНИЕ\r\n\r\nТри столпа, которые вдохновили нас на создание GATE31:\r\n\r\nЭто путешествия.\r\nЭто современное искусство и пространства.\r\nЭто современная архитектура и дизайн.',NULL,NULL,NULL,NULL,NULL),
	(334,2,2,'therebel','THEREBEL',0,0,'2025-11-07 10:28:22','2025-11-07 10:28:22','active','therebel-690dc9c6738bf527403817.png',NULL,'https://therebel.ru/','info@therebel.ru','+79296239999','Россия, г. Москва',NULL,NULL,NULL,NULL,NULL,NULL);

/*!40000 ALTER TABLE `brand` ENABLE KEYS */;
UNLOCK TABLES;


# Дамп таблицы doctrine_migration_versions
# ------------------------------------------------------------

DROP TABLE IF EXISTS `doctrine_migration_versions`;

CREATE TABLE `doctrine_migration_versions` (
  `version` varchar(191) COLLATE utf8mb3_unicode_ci NOT NULL,
  `executed_at` datetime DEFAULT NULL,
  `execution_time` int DEFAULT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `doctrine_migration_versions` WRITE;
/*!40000 ALTER TABLE `doctrine_migration_versions` DISABLE KEYS */;

INSERT INTO `doctrine_migration_versions` (`version`, `executed_at`, `execution_time`)
VALUES
	('DoctrineMigrations\\Version20251105182318','2025-11-05 18:28:58',96),
	('DoctrineMigrations\\Version20251106172057','2025-11-06 17:21:03',44),
	('DoctrineMigrations\\Version20251106173526','2025-11-06 17:35:29',36),
	('DoctrineMigrations\\Version20251107081526','2025-11-07 08:15:31',73),
	('DoctrineMigrations\\Version20251107123352','2025-11-07 12:34:15',46),
	('DoctrineMigrations\\Version20251107123721','2025-11-07 12:37:24',45),
	('DoctrineMigrations\\Version20251108131634','2025-11-08 13:16:53',90),
	('DoctrineMigrations\\Version20251108131921','2025-11-08 13:19:25',53),
	('DoctrineMigrations\\Version20251108132620','2025-11-08 13:26:23',24);

/*!40000 ALTER TABLE `doctrine_migration_versions` ENABLE KEYS */;
UNLOCK TABLES;


# Дамп таблицы main
# ------------------------------------------------------------

DROP TABLE IF EXISTS `main`;

CREATE TABLE `main` (
  `id` int NOT NULL AUTO_INCREMENT,
  `parent_id` int DEFAULT NULL,
  `entity_type_id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `template` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ord` int DEFAULT NULL,
  `is_node` tinyint(1) DEFAULT NULL,
  `entity_id` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `path_unique_idx` (`full_path`),
  KEY `IDX_BF28CD64727ACA70` (`parent_id`),
  KEY `IDX_BF28CD645681BEB0` (`entity_type_id`),
  CONSTRAINT `FK_BF28CD645681BEB0` FOREIGN KEY (`entity_type_id`) REFERENCES `section_type` (`id`),
  CONSTRAINT `FK_BF28CD64727ACA70` FOREIGN KEY (`parent_id`) REFERENCES `main` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Дамп таблицы product
# ------------------------------------------------------------

DROP TABLE IF EXISTS `product`;

CREATE TABLE `product` (
  `id` int NOT NULL AUTO_INCREMENT,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `price` double DEFAULT NULL,
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent` int DEFAULT NULL,
  `ord` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `brand_id` int DEFAULT NULL,
  `description` longtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `IDX_D34A04ADDE12AB56` (`created_by`),
  KEY `IDX_D34A04AD16FE72E1` (`updated_by`),
  KEY `IDX_D34A04AD44F5D008` (`brand_id`),
  CONSTRAINT `FK_D34A04AD16FE72E1` FOREIGN KEY (`updated_by`) REFERENCES `user` (`id`),
  CONSTRAINT `FK_D34A04AD44F5D008` FOREIGN KEY (`brand_id`) REFERENCES `brand` (`id`),
  CONSTRAINT `FK_D34A04ADDE12AB56` FOREIGN KEY (`created_by`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Дамп таблицы section_link
# ------------------------------------------------------------

DROP TABLE IF EXISTS `section_link`;

CREATE TABLE `section_link` (
  `id` int NOT NULL AUTO_INCREMENT,
  `parent_type_id` int NOT NULL,
  `child_type_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_B31275FAB704F8D5` (`parent_type_id`),
  KEY `IDX_B31275FAA7F8C488` (`child_type_id`),
  CONSTRAINT `FK_B31275FAA7F8C488` FOREIGN KEY (`child_type_id`) REFERENCES `section_type` (`id`),
  CONSTRAINT `FK_B31275FAB704F8D5` FOREIGN KEY (`parent_type_id`) REFERENCES `section_type` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Дамп таблицы section_type
# ------------------------------------------------------------

DROP TABLE IF EXISTS `section_type`;

CREATE TABLE `section_type` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `template` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` longtext COLLATE utf8mb4_unicode_ci,
  `is_node` tinyint(1) NOT NULL DEFAULT '1',
  `entity_class` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `crud_controller_class` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `controller_class` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



# Дамп таблицы user
# ------------------------------------------------------------

DROP TABLE IF EXISTS `user`;

CREATE TABLE `user` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `roles` json NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_verified` tinyint(1) NOT NULL,
  `first_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` longtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_IDENTIFIER_EMAIL` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

LOCK TABLES `user` WRITE;
/*!40000 ALTER TABLE `user` DISABLE KEYS */;

INSERT INTO `user` (`id`, `email`, `roles`, `password`, `is_verified`, `first_name`, `last_name`, `phone`, `address`)
VALUES
	(1,'nevinny@gmail.com','[\"ROLE_USER\", \"ROLE_ADMIN\"]','$2y$13$Ms6uzy1zfl859CUU9cpuIeuk4Co.7R3Wv1MfmFJJVVqpDFmDUWe/C',1,NULL,NULL,NULL,NULL),
	(2,'alay@mail.ru','[\"ROLE_USER\", \"ROLE_ADMIN\"]','$2y$13$0OrT4/XSMc1/vWaMSiyvvecWpEDKy/x1Ynk4ZvZ83mMwGGHO5s8NK',1,NULL,NULL,NULL,NULL);

/*!40000 ALTER TABLE `user` ENABLE KEYS */;
UNLOCK TABLES;



/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
