<?php

namespace App\Service\Agent;

use App\Entity\Brand;
use App\Entity\BrandFaq;
use App\Entity\BrandKeyword;
use App\Entity\BrandLink;
use App\Entity\BrandStore;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;

/**
 * Прод-сторона агент-API: применяет payload агента-генератора к локальной БД.
 * Upsert по slug (dev brand.id ≠ прод; external_id — только аудит), вся операция
 * в транзакции. Новый бренд: status='new' + publish_pending=1 → скрыт из
 * каталога/sitemap (публичные запросы фильтруют status='active'), публикует
 * дрип-крон app:brand:publish-tick.
 *
 * agent_sync_version: payload с версией ≤ текущей пропускается (ре-доставка/гонки).
 * Картинки: v1 принимает только логотип (base64-байты — прод не видит LAN).
 */
class BrandIngestService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param array<string,mixed> $payload см. формат в BrandIngestController
     * @return array{status:string, brand_id:int|null} status: created|updated|skipped
     */
    public function upsert(array $payload): array
    {
        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($slug === '' || trim((string) ($payload['title'] ?? '')) === '') {
            throw new \InvalidArgumentException('slug и title обязательны');
        }

        return $this->em->wrapInTransaction(function () use ($payload, $slug): array {
            /** @var Brand|null $brand */
            $brand = $this->em->getRepository(Brand::class)->findOneBy(['slug' => $slug]);
            $isNew = $brand === null;

            $version = (int) ($payload['agent_sync_version'] ?? 1);
            if (!$isNew && $version <= $brand->getAgentSyncVersion()) {
                return ['status' => 'skipped', 'brand_id' => $brand->getId()];
            }

            if ($isNew) {
                $brand = (new Brand())->setSlug($slug)->queue();   // → new, скрыт до дрип-публикации
                $this->em->persist($brand);
            }

            $brand->setTitle((string) $payload['title'])
                ->setAgentSyncVersion($version);

            // Скалярные поля — только присланные (не затираем не-присланное null'ами).
            foreach ([
                'city'         => 'setCity',
                'foundingYear' => 'setFoundingYear',
                'description'  => 'setDescription',
                'anons'        => 'setAnons',
            ] as $key => $setter) {
                if (array_key_exists($key, $payload)) {
                    $brand->{$setter}($payload[$key] !== null ? (string) $payload[$key] : null);
                }
            }

            $meta = $payload['meta'] ?? [];
            if (isset($meta['title'])) {
                $brand->setMetaTitle((string) $meta['title']);
            }
            if (isset($meta['description'])) {
                $brand->setMetaDescription((string) $meta['description']);
            }
            if (isset($meta['keywords'])) {
                $brand->setMetaKeywords((string) $meta['keywords']);
            }

            // Owner-guard: поля с provenance=owner (правка владельца через ЛК) НЕ затираем
            // ре-обогащением — guard независим от agent_sync_version (owner-правки на проде
            // версию не бампают). Дизайн: tasktracker «краудсорс-валидация».
            /** @var \App\Repository\BrandDatapointRepository $dpRepo */
            $dpRepo = $this->em->getRepository(\App\Entity\BrandDatapoint::class);

            $contacts = $payload['contacts'] ?? [];
            foreach (['email' => 'setEmail', 'phone' => 'setPhone', 'address' => 'setAddress'] as $key => $setter) {
                if (isset($contacts[$key])
                    && !$dpRepo->isOwnerProvenance($brand, \App\Entity\BrandDatapoint::TYPE_CONTACT, null, $key)) {
                    $brand->{$setter}((string) $contacts[$key]);
                }
            }

            if (isset($payload['logo']['filename'], $payload['logo']['content_base64'])) {
                $this->applyLogo($brand, (string) $payload['logo']['filename'], (string) $payload['logo']['content_base64']);
            }

            $this->em->flush(); // нужен brand.id для FK ниже

            if (array_key_exists('keywords', $payload)) {
                $this->replaceKeywords($brand, (array) $payload['keywords']);
            }
            if (array_key_exists('faq', $payload)) {
                $this->replaceFaq($brand, (array) $payload['faq']);
            }
            if (array_key_exists('links', $payload)) {
                $this->replaceLinks($brand, (array) $payload['links']);
            }
            if (array_key_exists('attributes', $payload)) {
                $this->replaceAttributes($brand, (array) $payload['attributes']);
            }
            if (array_key_exists('stores', $payload)) {
                $this->replaceStores($brand, (array) $payload['stores']);
            }
            if (array_key_exists('related', $payload)) {
                $this->replaceRelated($brand, (array) $payload['related']);
            }

            // Свежие данные приехали → забракованные голосами точки считаем ре-обогащёнными:
            // state=active, голоса устарели (удаляем — sumWeights иначе воскресит счётчики).
            foreach ($dpRepo->findBy(['brand' => $brand, 'state' => \App\Entity\BrandDatapoint::STATE_HIDDEN]) as $dp) {
                if ($dp->getProvenance() === \App\Entity\BrandDatapoint::PROV_OWNER) {
                    continue;
                }
                $dp->setRevalidatedAt(new \DateTime())
                    ->setState(\App\Entity\BrandDatapoint::STATE_ACTIVE)
                    ->setProvenance(\App\Entity\BrandDatapoint::PROV_ENRICHMENT)
                    ->setConfirmCount(0)->setRejectCount(0)->setRejectWindow(0);
                $this->em->createQuery('DELETE FROM App\Entity\BrandDatapointVote v WHERE v.datapoint = :dp')
                    ->setParameter('dp', $dp)->execute();
            }

            $this->em->flush();

            return ['status' => $isNew ? 'created' : 'updated', 'brand_id' => $brand->getId()];
        });
    }

    /**
     * Снятие бренда с публикации (агент-API, point 1 чистки прода). Soft: статус
     * в Disabled (каталог/sitemap фильтруют status IN ('active','new')) + снимаем
     * publish_pending, чтобы дрип-крон не активировал заново. published_at —
     * исторический след, не трогаем. Не физический delete (политика soft-delete).
     * Резолв по slug (dev brand.id ≠ прод); brand_id — только fallback.
     *
     * @param array<string,mixed> $payload {"slug": "..."} | {"brand_id": N}
     * @return array{status:string, brand_id:int|null} status: unpublished|not_found
     */
    public function unpublish(array $payload): array
    {
        $slug    = trim((string) ($payload['slug'] ?? ''));
        $brandId = isset($payload['brand_id']) ? (int) $payload['brand_id'] : 0;
        if ($slug === '' && $brandId <= 0) {
            throw new \InvalidArgumentException('slug или brand_id обязателен');
        }

        return $this->em->wrapInTransaction(function () use ($slug, $brandId): array {
            $repo = $this->em->getRepository(Brand::class);
            /** @var Brand|null $brand */
            $brand = $slug !== '' ? $repo->findOneBy(['slug' => $slug]) : $repo->find($brandId);
            if ($brand === null) {
                return ['status' => 'not_found', 'brand_id' => null];
            }

            $brand->unpublish();
            $this->em->flush();

            return ['status' => 'unpublished', 'brand_id' => $brand->getId()];
        });
    }

    /**
     * Приоритетная публикация (агент-API): ручные/важные бренды активируются сразу,
     * минуя случайную выборку дрип-крона. published_at ставим как у дрипа (МСК) —
     * publish-tick считает published_today по нему, так что приоритетная публикация
     * входит в дневной таргет ramp'а и не раздувает velocity. Идемпотентно.
     *
     * @param array<string,mixed> $payload {"slug": "..."} | {"brand_id": N}
     * @return array{status:string, brand_id:int|null, url?:string}
     *         status: published|already_published|not_found
     */
    public function publish(array $payload): array
    {
        $slug    = trim((string) ($payload['slug'] ?? ''));
        $brandId = isset($payload['brand_id']) ? (int) $payload['brand_id'] : 0;
        if ($slug === '' && $brandId <= 0) {
            throw new \InvalidArgumentException('slug или brand_id обязателен');
        }

        return $this->em->wrapInTransaction(function () use ($slug, $brandId): array {
            $repo = $this->em->getRepository(Brand::class);
            /** @var Brand|null $brand */
            $brand = $slug !== '' ? $repo->findOneBy(['slug' => $slug]) : $repo->find($brandId);
            if ($brand === null) {
                return ['status' => 'not_found', 'brand_id' => null];
            }

            $url = 'https://wearbase.ru/ru/brands/' . rawurlencode((string) $brand->getSlug());
            if ($brand->getStatus() === Statuses::Active) {
                return ['status' => 'already_published', 'brand_id' => $brand->getId(), 'url' => $url];
            }

            $brand->publish();   // new|disabled → active, published_at = МСК now
            $this->em->flush();

            return ['status' => 'published', 'brand_id' => $brand->getId(), 'url' => $url];
        });
    }

    /** Лого хранятся плоско в public_html/images/logos (см. vich_uploader.yaml). */
    private function applyLogo(Brand $brand, string $filename, string $base64): void
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '') {
            throw new \InvalidArgumentException('logo.content_base64 не декодируется');
        }
        if (strlen($bytes) > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException('logo больше 5MB');
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'], true)) {
            throw new \InvalidArgumentException("Недопустимое расширение логотипа: {$ext}");
        }

        // Имя генерим сами (slug-уникальное) — никакого доверия присланному filename (traversal).
        $safeName = $brand->getSlug() . '-' . substr(sha1($bytes), 0, 8) . '.' . $ext;
        $dir = $this->projectDir . '/public_html/images/logos';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Не создать каталог логотипов: {$dir}");
        }
        if (file_put_contents($dir . '/' . $safeName, $bytes) === false) {
            throw new \RuntimeException('Не записать файл логотипа');
        }

        $brand->setLogo($safeName);
    }

    /** @param array<int,array<string,mixed>> $rows delete-and-replace */
    private function replaceKeywords(Brand $brand, array $rows): void
    {
        $this->em->getRepository(BrandKeyword::class)->deleteForBrand($brand);
        foreach (array_slice($rows, 0, 200) as $row) {
            $phrase = trim((string) ($row['keyword'] ?? ''));
            if ($phrase === '') {
                continue;
            }
            $this->em->persist((new BrandKeyword())
                ->setBrand($brand)
                ->setKeyword(mb_substr($phrase, 0, 255))
                ->setType(in_array($row['type'] ?? '', [BrandKeyword::TYPE_ORIGIN, BrandKeyword::TYPE_RELATED], true) ? $row['type'] : BrandKeyword::TYPE_ORIGIN)
                ->setMonthlyShows(isset($row['monthly_shows']) ? (int) $row['monthly_shows'] : null));
        }
    }

    /** @param array<int,array<string,mixed>> $rows delete-and-replace */
    private function replaceFaq(Brand $brand, array $rows): void
    {
        $this->em->getRepository(BrandFaq::class)->deleteForBrand($brand);
        foreach (array_slice($rows, 0, 10) as $i => $row) {
            $q = trim((string) ($row['question'] ?? ''));
            $a = trim((string) ($row['answer'] ?? ''));
            if ($q === '' || $a === '') {
                continue;
            }
            $this->em->persist((new BrandFaq())
                ->setBrand($brand)
                ->setQuestion(mb_substr($q, 0, 500))
                ->setAnswer($a)
                ->setPosition((int) ($row['position'] ?? $i)));
        }
    }

    /** @param array<int,array<string,mixed>> $rows delete-and-replace (owner/crowd_confirmed сохраняются) */
    private function replaceAttributes(Brand $brand, array $rows): void
    {
        // Атрибуты, подтверждённые/правленные на проде (голоса), не затираем ре-доставкой.
        $this->em->getRepository(\App\Entity\BrandAttribute::class)->deleteEnrichmentForBrand($brand);
        foreach (array_slice($rows, 0, 80) as $row) {
            $name  = trim((string) ($row['name'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if ($name === '' || $value === '') {
                continue;
            }
            $this->em->persist((new \App\Entity\BrandAttribute())
                ->setBrand($brand)
                ->setName(mb_substr($name, 0, 40))
                ->setValue(mb_substr($value, 0, 255)));
        }
    }

    /** @param array<int,array<string,mixed>> $rows delete-and-replace (owner/manual сохраняются) */
    private function replaceStores(Brand $brand, array $rows): void
    {
        // Удаляем только enrichment-магазины; owner/manual не трогаем.
        $this->em->createQuery('DELETE FROM ' . BrandStore::class . ' s WHERE s.brand = :brand AND s.source = :source')
            ->setParameter('brand', $brand)
            ->setParameter('source', 'enrichment')
            ->execute();
        foreach (array_slice($rows, 0, 20) as $row) {
            $address = trim((string) ($row['address'] ?? ''));
            if ($address === '') {
                continue;
            }
            $this->em->persist((new BrandStore())
                ->setBrand($brand)
                ->setAddress(mb_substr($address, 0, 500))
                ->setCity(isset($row['city']) ? mb_substr((string) $row['city'], 0, 100) : null)
                ->setPhone(isset($row['phone']) ? mb_substr((string) $row['phone'], 0, 30) : null)
                ->setWorkHours(isset($row['workHours']) ? mb_substr((string) $row['workHours'], 0, 255) : null)
                ->setSource('enrichment'));
        }
    }

    /** @param array<int,array<string,mixed>> $rows delete-and-replace (owner-строки сохраняются) */
    private function replaceLinks(Brand $brand, array $rows): void
    {
        /** @var \App\Repository\BrandDatapointRepository $dpRepo */
        $dpRepo = $this->em->getRepository(\App\Entity\BrandDatapoint::class);
        foreach ($brand->getLinks() as $existing) {
            // Ссылку, внесённую/подтверждённую владельцем, ре-обогащение не трогает.
            if ($dpRepo->isOwnerProvenance($brand, \App\Entity\BrandDatapoint::TYPE_LINK, $existing->getId(), 'url')) {
                continue;
            }
            $this->em->remove($existing);
        }
        foreach (array_slice($rows, 0, 20) as $row) {
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '' || !str_starts_with($url, 'http')) {
                continue;
            }
            $type = isset($row['type']) ? mb_substr((string) $row['type'], 0, 32) : 'other';
            $link = (new BrandLink())
                ->setBrand($brand)
                ->setLinkUrl(mb_substr($url, 0, 255))
                ->setLinkType($type);
            $link->setTitle($type);
            // DefaultFields требует slug NOT NULL — генерируем из URL (паттерн enrich-contacts)
            $link->setSlug(substr(md5($type . $url), 0, 24));
            $link->setStatus(Statuses::Active);
            $this->em->persist($link);
        }
    }

    /**
     * Жёсткий граф перелинковки: delete-and-replace исходящих рёбер бренда.
     * Рёбра приходят слагами (id dev ≠ прод); slug'и, которых на проде ещё нет
     * (бренд не доехал/не опубликован), пропускаем — позиции добьёт weave()
     * в publish-tick. Системная операция — физический delete допустим.
     *
     * @param array<int,array<string,mixed>> $rows [{slug, position, source}]
     */
    private function replaceRelated(Brand $brand, array $rows): void
    {
        $db = $this->em->getConnection();

        // DELETE + повторная вставка — в одной транзакции: иначе при сбое (или
        // наложении weave() из publish-tick на тот же brand_id) бренд может
        // остаться с пустыми исходящими рёбрами между DELETE и INSERT.
        $db->transactional(function () use ($db, $brand, $rows): void {
            $db->executeStatement('DELETE FROM brand_related WHERE brand_id = :id', ['id' => $brand->getId()]);

            foreach (array_slice($rows, 0, \App\Service\BrandLinkGraphService::OUT_DEGREE) as $row) {
                $slug = trim((string) ($row['slug'] ?? ''));
                $position = (int) ($row['position'] ?? 0);
                if ($slug === '' || $slug === $brand->getSlug() || $position < 1) {
                    continue;
                }
                $targetId = $db->fetchOne('SELECT id FROM brand WHERE slug = :slug', ['slug' => $slug]);
                if ($targetId === false) {
                    continue;
                }
                $db->executeStatement(
                    'INSERT IGNORE INTO brand_related (brand_id, related_brand_id, position, source)
                     VALUES (:brand, :related, :pos, :source)',
                    [
                        'brand'   => $brand->getId(),
                        'related' => (int) $targetId,
                        'pos'     => min($position, \App\Service\BrandLinkGraphService::OUT_DEGREE),
                        'source'  => mb_substr((string) ($row['source'] ?? 'embedding'), 0, 20),
                    ],
                );
            }
        });
    }
}
