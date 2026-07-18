<?php

namespace App\Repository;

use Nevinny\AdminCoreBundle\Enum\Statuses;
use App\Entity\Brand;
use Doctrine\DBAL\Types\Types;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Brand>
 */
class BrandRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly PipelineQueueRepository $pipelineQueue,
    ) {
        parent::__construct($registry, Brand::class);
    }

    /**
     * Найти бренды по букве
     */
    public function findBrandsByLetter(string $letter): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', Statuses::Active)
            ->orderBy('b.title', 'ASC');

        if ($letter === '0-9') {
            $qb->andWhere('REGEXP(b.title, :regexp) = true')
                ->setParameter('regexp', '^[0-9]');
        } else {
            $qb->andWhere('UPPER(SUBSTRING(b.title, 1, 1)) = :letter')
                ->setParameter('letter', $letter);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Найти бренды по поисковому запросу
     */
    public function findBrandsBySearch(string $search): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->andWhere('b.title LIKE :search')
            ->setParameter('status', Statuses::Active)
            ->setParameter('search', '%' . $search . '%')
            ->orderBy('b.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Точный матч бренда по названию (регистронезависимо) — для app:seo:aio-remediate:
     * связка запроса GSC («{бренд} чей бренд») с существующей карточкой. Только
     * опубликованные активные бренды; foreign-фильтр — на вызывающей стороне (isForeignOrigin).
     */
    public function findOneActiveByTitle(string $title): ?Brand
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->andWhere('b.publishedAt IS NOT NULL')
            ->andWhere('LOWER(b.title) = LOWER(:title)')
            ->setParameter('status', Statuses::Active)
            ->setParameter('title', $title)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Все активные бренды
     */
    public function findAllActiveBrands(): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', Statuses::Active)
            ->orderBy('b.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Получить статистику по буквам
     */
    /**
     * Похожие бренды из жёсткого графа перелинковки (brand_related, app:brand:build-link-graph).
     * Порядок — по position; неактивные target отфильтрованы (рёбра на них чинит
     * BrandLinkGraphService::replaceDeadEdges, но между прогонами не показываем).
     *
     * @return Brand[]
     */
    public function findRelatedHard(Brand $brand): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT related_brand_id FROM brand_related WHERE brand_id = :id ORDER BY position',
            ['id' => $brand->getId()],
        );
        if ($ids === []) {
            return [];
        }

        $brands = [];
        foreach ($this->findBy(['id' => $ids, 'status' => Statuses::Active]) as $related) {
            $brands[$related->getId()] = $related;
        }

        // восстановить порядок position
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($brands[(int) $id])) {
                $ordered[] = $brands[(int) $id];
            }
        }

        return $ordered;
    }

    public function findSimilarBrands(Brand $brand, int $limit = 8): array
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->setParameter('status', Statuses::Active)
            ->andWhere('b.id != :excludeId')
            ->setParameter('excludeId', $brand->getId());

        $city = $brand->getCity();
        $styles = $brand->getStyles();

        if ($city) {
            $qb->orWhere('b.city = :city');
            $qb->setParameter('city', $city);
        }

        if (count($styles) > 0) {
            $qb->leftJoin('b.styles', 's')
               ->andWhere('s.id IN (:styleIds)')
               ->setParameter('styleIds', $styles->map(fn($s) => $s->getId())->toArray());
        }

        $qb->orderBy('b.created_at', 'DESC')
           ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Конкуренты для листикла «ТОП-N в нише» (app:seo:listicle): активные бренды
     * того же стиля (ниша = BrandStyle, т.к. category в каталоге не заполнена) с
     * непустым описанием (есть факты для секции), кроме целевого. Сортировка по
     * длине описания DESC — берём бренды с самым богатым фактическим материалом,
     * чтобы рейтинг не получился из пустышек. Офф-ниша (мебель/аптеки и т.п., попавшие
     * по ошибочному стилю) отсеивается по «одёжным» сигналам в описании.
     *
     * @return Brand[]
     */
    public function findListicleCompetitors(int $styleId, int $excludeBrandId, int $limit = 4, ?string $city = null, ?string $excludeTitle = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->innerJoin('b.styles', 's')
            ->where('s.id = :styleId')
            ->andWhere('b.status = :status')
            ->andWhere('b.id != :excludeId')
            ->andWhere('b.description IS NOT NULL')
            ->andWhere('LENGTH(b.description) > 100')
            ->andWhere("b.nicheStatus IS NULL OR b.nicheStatus != 'off'")  // ниша-гейт: не берём off
            ->setParameter('styleId', $styleId)
            ->setParameter('status', Statuses::Active)
            ->setParameter('excludeId', $excludeBrandId)
            ->groupBy('b.id')
            ->orderBy('LENGTH(b.description)', 'DESC')
            ->setMaxResults($limit * 5 + 10);   // запас под отсев офф-ниши

        // Гео-срез (для city×style листиклов «ТОП-N {стиль} брендов {город}»).
        if ($city !== null && trim($city) !== '') {
            $qb->andWhere('LOWER(b.city) LIKE :city')
               ->setParameter('city', '%' . mb_strtolower(trim($city), 'UTF-8') . '%');
        }

        $brands = $qb->getQuery()->getResult();

        // Отсев офф-ниши + дедуп по нормализованному имени (ТВОЕ↔Tvoe — дубли карточек
        // одного бренда; целевой тоже исключаем по имени, чтобы не попал в конкуренты).
        $seen = [];
        if ($excludeTitle !== null && trim($excludeTitle) !== '') {
            $seen[self::normName($excludeTitle)] = true;
        }
        $out = [];
        foreach ($brands as $b) {
            if (!self::looksLikeApparel((string) $b->getDescription())) {
                continue;
            }
            // решение B: только локальные. Если country заполнен (после app:brand:extract) —
            // это авторитет; иначе эвристика по имени/описанию.
            $country = mb_strtolower(trim((string) $b->getCountry()), 'UTF-8');
            $isForeign = $country !== ''
                ? (mb_strpos($country, 'росси') === false && mb_strpos($country, 'russia') === false)
                : self::isLikelyForeign((string) $b->getTitle(), (string) $b->getDescription());
            if ($isForeign) {
                continue;
            }
            $key = self::normName((string) $b->getTitle());
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $b;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** Список известных глобальных брендов в каталоге (нацпризнака в описании может не быть). */
    private const FOREIGN_DENYLIST = [
        'uniqlo', 'bershka', 'bettybarclay', 'anta', 'alessandromanzoni', 'bikkembergs', 'apc',
        'mango', 'zara', 'hm', 'pullandbear', 'massimodutti', 'oysho', 'stradivarius', 'cos', 'monki',
        'lacoste', 'levis', 'nike', 'adidas', 'puma', 'reebok', 'newbalance', 'columbia',
    ];

    /**
     * Бренд скорее иностранный (решение B — в подборках только локальные). По убыванию
     * надёжности: курируемый список глобалов → явный RU-маркер (тогда НЕ иностранный) →
     * явный нац-маркер происхождения в описании. Бренды без маркеров считаем локальными
     * (каталог преимущественно российский — не режем по умолчанию).
     */
    private static function isLikelyForeign(string $title, string $description): bool
    {
        if (in_array(self::normName($title), self::FOREIGN_DENYLIST, true)) {
            return true;
        }
        $t = mb_strtolower($description, 'UTF-8');
        foreach (['российск', 'русск', 'отечественн', 'локальн', 'из росси', 'московск', 'петербург', 'питерск'] as $ru) {
            if (mb_strpos($t, $ru) !== false) {
                return false;   // явно наш
            }
        }
        // нац-признак происхождения рядом с «марк/бренд/производ/мастер/мод/компан/дом»
        return (bool) preg_match(
            '/(японск|китайск|итальянск|испанск|француз|немецк|британск|английск|американск|корейск|турецк|европейск|финск|шведск|польск|вьетнамск)\w*\s+\w*\s*(марк|бренд|производ|мастер|мод|компан|дом|наслед)/u',
            $t,
        );
    }

    /** Нормализованное имя бренда для дедупа: транслит кириллицы → latin + только alnum. */
    private static function normName(string $s): string
    {
        static $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];

        return (string) preg_replace('/[^a-z0-9]+/', '', strtr(mb_strtolower(trim($s), 'UTF-8'), $map));
    }

    /**
     * Похоже ли описание на бренд ОДЕЖДЫ — отсев офф-ниши (мебель/аптека/авто и пр.,
     * затесавшиеся по ошибочному стилю). Сначала дени-лист явной офф-ниши (надёжнее
     * подстрок: «бель» матчит «меБЕЛЬ», «мода» — «коМОДА»), затем одёжные сигналы.
     */
    private static function looksLikeApparel(string $text): bool
    {
        $t = mb_strtolower($text, 'UTF-8');

        foreach ([
            'мебел', 'шкаф', 'диван', 'кухн', 'перегородк', 'межкомнатн', 'раздвижн', 'гардеробн комнат',
            'аптек', 'лекарств', 'фармац', ' бад', 'витамин',
            'автомобил', 'запчаст', 'шиномонт', 'недвижим', 'ремонт кварт', 'строительн',
        ] as $bad) {
            if (mb_strpos($t, $bad) !== false) {
                return false;
            }
        }

        foreach ([
            'одежд', 'ткан', 'носить', 'пошив', 'лукбук', 'модн', 'фасон',
            'футболк', 'платье', 'платья', 'куртк', 'джинс', 'обув', 'худи', 'свитшот', 'кроссовк',
            'трикотаж', 'пальто', 'юбк', 'брюк', 'рубашк', 'пиджак', 'костюм', 'бомбер', 'свитер',
            'джемпер', 'лонгслив', 'шорт', 'толстовк', 'гардероб одежд',
            'fashion', 'wear', 'clothing', 'apparel', 'sneaker', 'outfit',
        ] as $sig) {
            if (mb_strpos($t, $sig) !== false) {
                return true;
            }
        }

        return false;
    }

    public function findFeaturedBrands(int $limit = 12, bool $withLogo = false): array
    {
        // Сначала пытаемся найти бренды с описанием (для лучшего UX)
        $qb = $this->createQueryBuilder('b')
            ->where('b.status = :status')
            ->andWhere('b.description IS NOT NULL')
            ->andWhere('LENGTH(b.description) > 100')
            ->andWhere("b.nicheStatus IS NULL OR b.nicheStatus != 'off'")  // ниша-гейт: не берём off
            ->setParameter('status', Statuses::Active)
            ->orderBy('b.created_at', 'DESC')
            ->setMaxResults($limit);
        if ($withLogo) {
            $qb->andWhere('b.logo IS NOT NULL');
        }
        $results = $qb->getQuery()->getResult();

        // Фолбэк: любые активные бренды (чтобы каталог не выглядел пустым)
        if (empty($results)) {
            $qb = $this->createQueryBuilder('b')
                ->where('b.status = :status')
                ->andWhere("b.nicheStatus IS NULL OR b.nicheStatus != 'off'")  // ниша-гейт: не берём off
                ->setParameter('status', Statuses::Active)
                ->orderBy('b.created_at', 'DESC')
                ->setMaxResults($limit);
            if ($withLogo) {
                $qb->andWhere('b.logo IS NOT NULL');
            }
            $results = $qb->getQuery()->getResult();
        }

        return $results;
    }

    public function getLetterStats(): array
    {
        $stats = [];
        foreach (array_merge(range('A', 'Z'), ['0-9']) as $char) {
            $stats[$char] = 0;
        }

        // GROUP BY вместо гидрации ВСЕХ активных брендов в PHP (было: findAllActiveBrands()
        // + цикл). Возвращает ~десятки строк (по первому символу), бакетинг тот же:
        // ASCII-буква → её заглавная, иначе → '0-9'.
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT UPPER(SUBSTRING(title, 1, 1)) AS ch, COUNT(*) AS c
             FROM brand WHERE status = :active GROUP BY ch",
            ['active' => Statuses::Active->value],
        );

        foreach ($rows as $row) {
            $ch = (string) $row['ch'];
            if ($ch === '' || !ctype_alpha($ch)) {
                $ch = '0-9';
            }
            if (isset($stats[$ch])) {
                $stats[$ch] += (int) $row['c'];
            }
        }

        return $stats;
    }

    /**
     * Бренды, которые нужно обогатить контактами.
     *
     * Пропускает (без --force):
     *  - бренды со статусом 'enriched' / 'partial' / 'not_found'
     *  - бренды, у которых уже есть хотя бы одна ссылка в brand_link
     *    (данные внесены вручную до запуска обогащения)
     *  - ошибочные бренды, исчерпавшие лимит попыток
     *
     * Включает:
     *  - contactEnrichedAt IS NULL И нет ссылок в brand_link
     *  - завершились ошибкой и ещё есть попытки
     *
     * @param bool $force если true — возвращает все бренды без фильтрации
     */
    public function findForContactEnrichment(int $limit, bool $force = false, int $maxErrorAttempts = 3): array
    {
        if ($force) {
            return $this->createQueryBuilder('b')
                ->orderBy('b.id', 'ASC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
        }

        return $this->createQueryBuilder('b')
            // Бренды без маркера обогащения И без уже существующих ссылок
            ->leftJoin('b.links', 'l')
            ->where(
                '(' .
                    'b.contactEnrichedAt IS NULL AND l.id IS NULL' .
                ') OR (' .
                    'b.contactStatus = :error AND b.contactAttempts < :maxAttempts' .
                ')'
            )
            // ТОЛЬКО бренды с корпусом: enrich извлекает контакты из brand_source_document.
            // Без этого no-corpus бренды (нет ни одного документа) выбираются каждый прогон,
            // скипаются («нет корпуса»), не помечаются → крутятся по кругу и душат очередь
            // (O'STIN снова и снова). Появится корпус (fetch) — бренд снова станет eligible.
            ->andWhere('EXISTS (SELECT d.id FROM App\Entity\BrandSourceDocument d WHERE d.brand = b)')
            ->setParameter('error', 'error')
            ->setParameter('maxAttempts', $maxErrorAttempts)
            ->groupBy('b.id')
            ->orderBy('b.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Приоритетная выборка брендов для app:contacts:refresh.
     *
     * Порядок: bounced → partial → stale (TTL) → never enriched → остальные.
     *
     * @param int  $limit   макс брендов
     * @param int  $ttlDays через сколько дней после последнего обогащения считать устаревшим
     * @param bool $force   все бренды без фильтра
     * @return Brand[]
     */
    public function findForContactRefresh(int $limit, int $ttlDays = 180, bool $force = false): array
    {
        if ($force) {
            return $this->findBy([], ['id' => 'ASC'], $limit);
        }

        $conn = $this->getEntityManager()->getConnection();
        $ttl  = (new \DateTime())->modify("-{$ttlDays} days")->format('Y-m-d H:i:s');

        $ids = $conn->fetchFirstColumn(
            // TTL — настоящий гейт (в WHERE): свежеобогащённых (enriched_at >= ttl, не partial)
            // НЕ берём. not_found — терминальный (не повторяем). priority (brand_rag_pipeline)
            // — первичная сортировка (общий приоритет бренда: discover/контакты/outreach).
            "SELECT b.id FROM brand b
             LEFT JOIN brand_outreach o ON o.brand_id = b.id AND o.bounced_at IS NOT NULL
             LEFT JOIN brand_rag_pipeline p ON p.brand_id = b.id
             WHERE b.status IN ('active', 'new')
               AND (b.contact_status IS NULL OR b.contact_status <> 'not_found')
               AND (
                     o.bounced_at IS NOT NULL
                     OR b.contact_status = 'partial'
                     OR b.contact_enriched_at IS NULL
                     OR b.contact_enriched_at < :ttl
                   )
             ORDER BY
                 p.priority DESC,
                 CASE
                     WHEN o.bounced_at IS NOT NULL THEN 0
                     WHEN b.contact_status = 'partial'             THEN 1
                     WHEN b.contact_enriched_at IS NOT NULL
                          AND b.contact_enriched_at < :ttl          THEN 2
                     WHEN b.contact_enriched_at IS NULL             THEN 3
                     ELSE 4
                 END,
                 b.contact_enriched_at IS NULL DESC,
                 b.contact_enriched_at ASC
             LIMIT :limit",
            ['ttl' => $ttl, 'limit' => $limit],
            ['ttl' => Types::STRING, 'limit' => \PDO::PARAM_INT],
        );

        if ($ids === []) {
            return [];
        }

        return $this->findBy(['id' => $ids], ['id' => 'ASC']);
    }

    // =========================================================================
    // RAG pipeline stage finders — логика вынесена в PipelineQueueRepository (SRP).
    // Делегаторы сохраняют публичный API для существующих вызывающих
    // ($em->getRepository(Brand::class)->findForX()); каллер-миграция на прямой
    // вызов сервиса — отдельный безопасный follow-up.
    // =========================================================================

    public function findRegenFlagged(int $limit, int $shard = 0, int $total = 1): array
    {
        return $this->pipelineQueue->findRegenFlagged($limit, $shard, $total);
    }

    public function findWithDescriptionWithoutMeta(int $limit, int $shard = 0, int $total = 1): array
    {
        return $this->pipelineQueue->findWithDescriptionWithoutMeta($limit, $shard, $total);
    }

    public function findForScrape(int $limit, int $shard = 0, int $total = 1, int $maxAttempts = 3): array
    {
        return $this->pipelineQueue->findForScrape($limit, $shard, $total, $maxAttempts);
    }

    public function findForEmbed(int $limit, int $shard = 0, int $total = 1, int $maxAttempts = 3): array
    {
        return $this->pipelineQueue->findForEmbed($limit, $shard, $total, $maxAttempts);
    }

    public function findForGeneration(int $limit, int $shard = 0, int $total = 1, int $maxAttempts = 3): array
    {
        return $this->pipelineQueue->findForGeneration($limit, $shard, $total, $maxAttempts);
    }

    public function findForKeywords(int $limit, int $shard = 0, int $total = 1): array
    {
        return $this->pipelineQueue->findForKeywords($limit, $shard, $total);
    }

    public function countForKeywords(): int
    {
        return $this->pipelineQueue->countForKeywords();
    }

    public function findForExtract(int $limit, int $shard = 0, int $total = 1): array
    {
        return $this->pipelineQueue->findForExtract($limit, $shard, $total);
    }

    public function findPublishedMissingFields(int $limit, int $shard = 0, int $total = 1): array
    {
        return $this->pipelineQueue->findPublishedMissingFields($limit, $shard, $total);
    }

    public function findForWbEnrich(int $limit, int $shard = 0, int $total = 1): array
    {
        return $this->pipelineQueue->findForWbEnrich($limit, $shard, $total);
    }

    public function findForCrawl(int $limit, int $shard = 0, int $total = 1): array
    {
        return $this->pipelineQueue->findForCrawl($limit, $shard, $total);
    }

    public function findForFaq(int $limit, int $shard = 0, int $total = 1): array
    {
        return $this->pipelineQueue->findForFaq($limit, $shard, $total);
    }

    public function findForLogo(int $limit, int $shard = 0, int $total = 1, bool $force = false): array
    {
        return $this->pipelineQueue->findForLogo($limit, $shard, $total, $force);
    }

    public function findReadyToPush(int $limit, int $shard = 0, int $total = 1, int $maxAttempts = 3, bool $includePushed = false, bool $oldestFirst = false): array
    {
        return $this->pipelineQueue->findReadyToPush($limit, $shard, $total, $maxAttempts, $includePushed, $oldestFirst);
    }

    public function countReadyToPush(int $maxAttempts = 3, bool $includePushed = false): int
    {
        return $this->pipelineQueue->countReadyToPush($maxAttempts, $includePushed);
    }
}
