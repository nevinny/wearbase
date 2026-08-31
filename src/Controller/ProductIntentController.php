<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Entity\ProductIntentClick;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Сигнал «Хочу купить» на карточке товара бренда без настроенного приёма онлайн-оплаты
 * (App\Twig\BrandSaleExtension::canSell() == false — кнопка «В корзину» заменена на эту).
 *
 * Гость должен мочь нажать — PUBLIC_ACCESS по умолчанию (маршрут не попадает ни под один
 * префикс access_control в security.yaml, main firewall пускает анонимов на непокрытые пути,
 * как /catalog и /product уже сегодня). CSRF-защита + rate-limit + отсев ботов — как в
 * OutboundClickController (тот же лимитер, тот же UA-паттерн).
 */
class ProductIntentController extends AbstractController
{
    private const BOT_UA = '~(GoogleImageProxy|YahooMailProxy|bot|crawler|spider|preview|monitor|HeadlessChrome|python-requests|curl)~i';

    #[Route('/product/{uuid}/want', name: 'product_intent_click', methods: ['POST'])]
    public function want(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Product $product,
        Request $request,
        EntityManagerInterface $em,
        RateLimiterFactory $outboundClickLimiter,
    ): Response {
        if ($product->getStatus() !== Statuses::Active || $product->getBrand()->getStatus() !== Statuses::Active) {
            throw $this->createNotFoundException('Товар не найден');
        }

        if (!$this->isCsrfTokenValid('product_intent', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Недействительный токен. Обновите страницу.');
            return $this->redirectToRoute('product_show', ['uuid' => $product->getUuid()]);
        }

        if (!$outboundClickLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new Response('Too many requests', 429);
        }

        $ua = (string) $request->headers->get('User-Agent', '');
        if (preg_match(self::BOT_UA, $ua) !== 1) {
            $ref = $request->headers->get('Referer');

            $click = new ProductIntentClick();
            $click->setBrand($product->getBrand());
            $click->setProduct($product);
            $click->setLocale($request->getLocale());
            $click->setReferer($ref !== null ? mb_substr($ref, 0, 255) : null);
            $click->setUaHash($ua !== '' ? hash('sha256', $ua) : null);
            $click->setCreatedAt(new \DateTime());

            $em->persist($click);
            $em->flush();
        }

        $this->addFlash('success', 'Спасибо — мы передали ваш интерес бренду');

        return $this->redirectToRoute('product_show', ['uuid' => $product->getUuid()]);
    }
}
