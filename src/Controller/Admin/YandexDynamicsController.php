<?php

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Factory\AdminContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Динамика Яндекс.Вебмастера (yandex_history): страницы в поиске + показы/клики по месяцам.
 * Отвечает на вопрос «дают ли правки сайта эффект». ⚠️ Данные на Mac (синк — крон Mac),
 * как GSC; на проде таблица пустая → панель информативна на Mac-админке.
 */
#[Route('/admin/yandex-dynamics', name: 'admin_yandex_dynamics')]
class YandexDynamicsController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly AdminContextFactory $adminContextFactory,
        private readonly DashboardController $dashboard,
    ) {
    }

    #[Route('', name: '')]
    public function index(Request $request): Response
    {
        if (!$request->attributes->has(EA::CONTEXT_REQUEST_ATTRIBUTE)) {
            $request->attributes->set(EA::CONTEXT_REQUEST_ATTRIBUTE, $this->adminContextFactory->create($request, $this->dashboard, null));
        }

        // Помесячно: страницы в поиске = значение на последний день месяца; показы/клики = сумма.
        $monthly = $this->db->fetchAllAssociative(
            "SELECT DATE_FORMAT(day, '%Y-%m') AS mo,
                    CAST(SUBSTRING_INDEX(GROUP_CONCAT(pages_in_search ORDER BY day), ',', -1) AS UNSIGNED) AS pages,
                    COALESCE(SUM(shows), 0) AS shows,
                    COALESCE(SUM(clicks), 0) AS clicks
               FROM yandex_history
              WHERE day >= DATE_SUB(CURDATE(), INTERVAL 13 MONTH)
              GROUP BY mo HAVING shows > 0 OR pages > 0 ORDER BY mo",
        );

        // CTR по месяцам (клики/показы) — падение = растём в показах, но не в кликах.
        foreach ($monthly as &$m) {
            $m['ctr'] = (int) $m['shows'] > 0 ? round(100 * (int) $m['clicks'] / (int) $m['shows'], 2) : 0.0;
        }
        unset($m);

        return $this->render('admin/yandex_dynamics.html.twig', [
            'monthly'  => $monthly,
            'maxShows' => max(1, ...array_map(static fn($m) => (int) $m['shows'], $monthly ?: [['shows' => 1]])),
            'maxPages' => max(1, ...array_map(static fn($m) => (int) $m['pages'], $monthly ?: [['pages' => 1]])),
        ]);
    }
}
