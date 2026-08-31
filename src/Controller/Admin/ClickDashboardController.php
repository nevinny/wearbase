<?php

namespace App\Controller\Admin;

use App\Entity\Brand;
use App\Repository\BrandOutboundClickRepository;
use App\Repository\ProductIntentClickRepository;
use App\Twig\BrandSaleExtension;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Factory\AdminContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Админ-дашборд исходящих кликов (/go/{id}): сколько посетителей ушло на сайты/соцсети/
 * маркетплейсы брендов, какие бренды и типы ссылок популярнее. Живые цифры из
 * brand_outbound_click (боты уже отфильтрованы на записи). Окно по умолчанию — 30 дней.
 */
#[Route('/admin/clicks', name: 'admin_clicks')]
class ClickDashboardController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly BrandOutboundClickRepository $clicks,
        private readonly ProductIntentClickRepository $intentClicks,
        private readonly BrandSaleExtension $saleGate,
        private readonly EntityManagerInterface $em,
        private readonly AdminContextFactory $adminContextFactory,
        private readonly DashboardController $dashboard,
    ) {
    }

    #[Route('', name: '')]
    public function index(Request $request): Response
    {
        $this->initAdminContext($request);

        $days = max(1, min(365, $request->query->getInt('days', 30)));

        $one = fn(string $sql, array $p = []) => (int) $this->db->fetchOne($sql, $p);
        $win = ['d' => $days];
        $winType = ['d' => $days, \PDO::PARAM_INT];

        // KPI: клики за разные окна + уникальные посетители (по ua_hash) за выбранное окно.
        $totals = [
            'all'   => $one('SELECT COUNT(*) FROM brand_outbound_click'),
            'today' => $one('SELECT COUNT(*) FROM brand_outbound_click WHERE created_at >= CURDATE()'),
            'd7'    => $one('SELECT COUNT(*) FROM brand_outbound_click WHERE created_at >= NOW() - INTERVAL 7 DAY'),
            'win'   => $one('SELECT COUNT(*) FROM brand_outbound_click WHERE created_at >= NOW() - INTERVAL :d DAY', $win),
            'visitors' => $one('SELECT COUNT(DISTINCT ua_hash) FROM brand_outbound_click WHERE created_at >= NOW() - INTERVAL :d DAY', $win),
            'brands'   => $one('SELECT COUNT(DISTINCT brand_id) FROM brand_outbound_click WHERE created_at >= NOW() - INTERVAL :d DAY', $win),
        ];

        // Разбивка по типу цели (website / instagram / marketplace / …).
        $byType = $this->db->fetchAllAssociative(
            'SELECT COALESCE(link_type, \'other\') AS link_type, COUNT(*) AS clicks
               FROM brand_outbound_click
              WHERE created_at >= NOW() - INTERVAL :d DAY
              GROUP BY link_type ORDER BY clicks DESC',
            $win, ['d' => \PDO::PARAM_INT],
        );

        // Клики по дням (для мини-графика).
        $byDay = $this->db->fetchAllAssociative(
            'SELECT DATE(created_at) AS day, COUNT(*) AS clicks
               FROM brand_outbound_click
              WHERE created_at >= NOW() - INTERVAL :d DAY
              GROUP BY DATE(created_at) ORDER BY day',
            $win, ['d' => \PDO::PARAM_INT],
        );

        // Топ хостов назначения.
        $topHosts = $this->db->fetchAllAssociative(
            'SELECT COALESCE(target_host, \'—\') AS host, COUNT(*) AS clicks
               FROM brand_outbound_click
              WHERE created_at >= NOW() - INTERVAL :d DAY
              GROUP BY target_host ORDER BY clicks DESC LIMIT 20',
            $win, ['d' => \PDO::PARAM_INT],
        );

        // Интент «Хочу купить» (гейт продажи для брендов без приёма оплаты): всего / за
        // 7 дней / топ-10 брендов с пометкой, настроен ли у бренда приём оплаты.
        $intentTotals = [
            'all' => $one('SELECT COUNT(*) FROM product_intent_click'),
            'd7'  => $one('SELECT COUNT(*) FROM product_intent_click WHERE created_at >= NOW() - INTERVAL 7 DAY'),
        ];
        $intentTopBrands = [];
        foreach ($this->intentClicks->topBrands($days, 10) as $row) {
            $brand = $this->em->find(Brand::class, (int) $row['brand_id']);
            $intentTopBrands[] = $row + ['payment_ready' => $brand !== null && $this->saleGate->canSell($brand)];
        }

        return $this->render('admin/click_dashboard.html.twig', [
            'days'      => $days,
            'totals'    => $totals,
            'byType'    => $byType,
            'byDay'     => $byDay,
            'topHosts'  => $topHosts,
            'topBrands' => $this->clicks->topBrands($days, 50),
            'intentTotals'    => $intentTotals,
            'intentTopBrands' => $intentTopBrands,
        ]);
    }

    private function initAdminContext(Request $request): void
    {
        if ($request->attributes->has(EA::CONTEXT_REQUEST_ATTRIBUTE)) {
            return;
        }
        $context = $this->adminContextFactory->create($request, $this->dashboard, null);
        $request->attributes->set(EA::CONTEXT_REQUEST_ATTRIBUTE, $context);
    }
}
