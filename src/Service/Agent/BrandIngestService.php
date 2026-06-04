<?php

namespace App\Service\Agent;

use App\Entity\Brand;
use App\Entity\BrandFaq;
use App\Entity\BrandKeyword;
use App\Entity\BrandLink;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;

/**
 * Прод-сторона агент-API: применяет payload агента-генератора к локальной БД.
 * Upsert по slug (dev brand.id ≠ прод; external_id — только аудит), вся операция
 * в транзакции. Новый бренд: status='new' + publish_pending=1 → скрыт из
 * каталога/sitemap (публичные запросы фильтруют status='active'), публикует
 * дрип-крон app:brand:publish-tick.
 *
 * content_version: payload с версией ≤ текущей пропускается (ре-доставка/гонки).
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

            $version = (int) ($payload['content_version'] ?? 1);
            if (!$isNew && $version <= $brand->getContentVersion()) {
                return ['status' => 'skipped', 'brand_id' => $brand->getId()];
            }

            if ($isNew) {
                $brand = (new Brand())
                    ->setSlug($slug)
                    ->setStatus(Statuses::New)   // скрыт до дрип-публикации (каталог фильтрует active)
                    ->setPublishPending(true);
                $this->em->persist($brand);
            }

            $brand->setTitle((string) $payload['title'])
                ->setContentVersion($version);

            // Скалярные поля — только присланные (не затираем не-присланное null'ами).
            foreach ([
                'city'        => 'setCity',
                'description' => 'setDescription',
                'anons'       => 'setAnons',
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

            $contacts = $payload['contacts'] ?? [];
            foreach (['email' => 'setEmail', 'phone' => 'setPhone', 'address' => 'setAddress'] as $key => $setter) {
                if (isset($contacts[$key])) {
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

            $this->em->flush();

            return ['status' => $isNew ? 'created' : 'updated', 'brand_id' => $brand->getId()];
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

    /** @param array<int,array<string,mixed>> $rows delete-and-replace */
    private function replaceLinks(Brand $brand, array $rows): void
    {
        foreach ($brand->getLinks() as $existing) {
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
}
