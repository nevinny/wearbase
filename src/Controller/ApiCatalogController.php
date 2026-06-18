<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * /.well-known/api-catalog — машиночитаемый индекс публичных API (RFC 9727).
 *
 * Формат — application/linkset+json (RFC 9264): массив `linkset`, каждая запись
 * с `anchor` (база API) и связями service-desc (OpenAPI), service-doc (док для
 * агентов) и status (liveness-эндпоинт).
 *
 * Перечисляем только ПУБЛИЧНЫЙ currency API (без авторизации). Внутренний
 * content agent-API (/api/v1/*) аутентифицируется и в публичный каталог не идёт.
 */
class ApiCatalogController extends AbstractController
{
    #[Route('/.well-known/api-catalog', name: 'well_known_api_catalog')]
    public function apiCatalog(): JsonResponse
    {
        $base = rtrim((string) $this->getParameter('app.site_base_url'), '/');

        $linkset = [
            [
                'anchor'       => $base . '/currency/api',
                'service-desc' => [[
                    'href'  => $base . '/.well-known/openapi-currency.json',
                    'type'  => 'application/openapi+json',
                    'title' => 'WEARBASE Currency API — OpenAPI 3.1',
                ]],
                'service-doc'  => [[
                    'href'  => $base . '/llms.txt',
                    'type'  => 'text/markdown',
                    'title' => 'Обзор сайта и контента для агентов',
                ]],
                'status'       => [[
                    'href'  => $base . '/currency/api/currencies',
                    'type'  => 'application/json',
                    'title' => 'Список валют (liveness)',
                ]],
            ],
        ];

        return new JsonResponse(
            ['linkset' => $linkset],
            200,
            ['Content-Type' => 'application/linkset+json'],
        );
    }
}
