<?php

namespace App\Service\Agent;

use App\Entity\Brand;
use App\Entity\BrandFaq;
use App\Entity\BrandKeyword;
use App\Entity\BrandStore;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Dev-сторона агент-API: собирает payload бренда для POST /api/v1/brands/upsert.
 * Логотип уходит base64-байтами (прод не видит LAN). Формат — см. BrandIngestController.
 */
class BrandPayloadAssembler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $projectDir,
    ) {
    }

    /** @return array<string,mixed> */
    public function assemble(Brand $brand): array
    {
        $payload = [
            'slug'            => $brand->getSlug(),
            'title'           => $brand->getTitle(),
            'city'            => $brand->getCity(),
            'description'     => $brand->getDescription(),
            'anons'           => $brand->getAnons(),
            'meta'            => [
                'title'       => $brand->getMetaTitle(),
                'description' => $brand->getMetaDescription(),
                'keywords'    => $brand->getMetaKeywords(),
            ],
            'contacts'        => array_filter([
                'email'   => $brand->getEmail(),
                'phone'   => $brand->getPhone(),
                'address' => $brand->getAddress(),
            ]),
            'external_id'     => $brand->getId(),
            'content_version' => $brand->getContentVersion() + 1,
        ];

        $payload['keywords'] = array_map(static fn(BrandKeyword $k) => [
            'keyword'       => $k->getKeyword(),
            'type'          => $k->getType(),
            'monthly_shows' => $k->getMonthlyShows(),
        ], $this->em->getRepository(BrandKeyword::class)->findBy(['brand' => $brand]));

        $payload['faq'] = array_map(static fn(BrandFaq $f) => [
            'question' => $f->getQuestion(),
            'answer'   => $f->getAnswer(),
            'position' => $f->getPosition(),
        ], $this->em->getRepository(BrandFaq::class)->findByBrandOrdered($brand));

        $links = [];
        foreach ($brand->getLinks() as $link) {
            if ($link->getLinkUrl()) {
                $links[] = ['type' => $link->getLinkType() ?? 'other', 'url' => $link->getLinkUrl()];
            }
        }
        $payload['links'] = $links;

        // Извлечённые атрибуты (краул→extract) — характеристики на странице бренда.
        $payload['attributes'] = array_map(static fn(\App\Entity\BrandAttribute $a) => [
            'name'  => $a->getName(),
            'value' => $a->getValue(),
        ], $this->em->getRepository(\App\Entity\BrandAttribute::class)->findByBrand($brand));

        // Магазины бренда (brand_store) — извлечённые из краула или добавленные владельцем.
        $payload['stores'] = array_map(static fn(BrandStore $s) => [
            'address'   => $s->getAddress(),
            'city'      => $s->getCity(),
            'phone'     => $s->getPhone(),
            'workHours' => $s->getWorkHours(),
            'source'    => $s->getSource(),
        ], $this->em->getRepository(BrandStore::class)->findBy(['brand' => $brand]));

        // Жёсткий граф перелинковки: исходящие рёбра слагами (id dev ≠ прод).
        // На проде приёмник скипнет slug'и, которых там ещё нет (доедут дрипом),
        // недостающие позиции добьёт weave() в publish-tick.
        $payload['related'] = $this->em->getConnection()->fetchAllAssociative(
            'SELECT b2.slug, r.position, r.source FROM brand_related r
             JOIN brand b2 ON b2.id = r.related_brand_id
             WHERE r.brand_id = :id ORDER BY r.position',
            ['id' => $brand->getId()],
        );

        // Логотип: плоское хранение public_html/images/logos (см. vich_uploader.yaml)
        if ($brand->getLogo()) {
            $path = $this->projectDir . '/public_html/images/logos/' . $brand->getLogo();
            if (is_file($path) && filesize($path) <= 5 * 1024 * 1024) {
                $payload['logo'] = [
                    'filename'       => basename($path),
                    'content_base64' => base64_encode((string) file_get_contents($path)),
                ];
            }
        }

        return $payload;
    }
}
