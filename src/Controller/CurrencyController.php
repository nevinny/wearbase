<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CurrencyRepository;
use App\Repository\ExchangeRateRepository;
use App\Service\CurrencyConverter;
use App\Service\CurrencySession;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/currency', name: 'currency_')]
class CurrencyController extends AbstractController
{
    public function __construct(
        private readonly CurrencyRepository     $currencyRepo,
        private readonly ExchangeRateRepository $rateRepo,
        private readonly CurrencySession        $session,
        private readonly CurrencyConverter      $converter,
    ) {}

    /**
     * Переключение валюты пользователем.
     * POST /currency/switch  с  { code: 'USD' }
     * или GET /currency/switch?code=USD&redirect=/some/page
     */
    #[Route('/switch', name: 'switch', methods: ['GET', 'POST'])]
    public function switch(Request $request): Response
    {
        $code = $request->request->get('code')
            ?? $request->query->get('code')
            ?? 'RUB';

        $referer  = $request->headers->get('referer', '/');
        $redirect = $request->query->get('redirect', $referer);

        $response = new RedirectResponse($redirect);
        $this->session->setCurrency((string) $code, $response);

        return $response;
    }

    // ── API ──────────────────────────────────────────────────────────────────

    /**
     * GET /api/currencies
     * Возвращает список активных валют.
     *
     * Response:
     * [
     *   { "code": "RUB", "symbol": "₽", "nameRu": "Российский рубль", "isBase": true },
     *   { "code": "USD", "symbol": "$",  "nameRu": "Доллар США",       "isBase": false },
     *   ...
     * ]
     */
    #[Route('/api/currencies', name: 'api_list')]
    public function apiList(): JsonResponse
    {
        $currencies = $this->currencyRepo->findActive();
        $current    = $this->session->getCurrentCode();

        $data = array_map(static fn($c) => [
            'code'           => $c->getCode(),
            'symbol'         => $c->getSymbol(),
            'symbolPosition' => $c->getSymbolPosition(),
            'nameRu'         => $c->getNameRu(),
            'nameEn'         => $c->getNameEn(),
            'decimalPlaces'  => $c->getDecimalPlaces(),
            'isBase'         => $c->isBase(),
            'isCurrent'      => $c->getCode() === $current,
        ], $currencies);

        return $this->json($data);
    }

    /**
     * GET /api/exchange-rates[?base=RUB]
     * Возвращает актуальные курсы для базовой валюты.
     *
     * Response:
     * {
     *   "base": "RUB",
     *   "date": "2026-05-23",
     *   "rates": { "USD": 0.01083, "EUR": 0.01003, ... }
     * }
     */
    #[Route('/api/exchange-rates', name: 'api_rates')]
    public function apiRates(Request $request): JsonResponse
    {
        $baseCode = strtoupper($request->query->get('base', 'RUB'));
        $base     = $this->currencyRepo->findByCode($baseCode);

        if (!$base) {
            return $this->json(['error' => "Currency '{$baseCode}' not found"], 404);
        }

        $rates    = $this->rateRepo->findLatestForBase($base);
        $ratesMap = [];
        $date     = null;

        foreach ($rates as $rate) {
            $ratesMap[$rate->getTargetCurrency()->getCode()] = (float) $rate->getRate();
            $date ??= $rate->getRateDate()->format('Y-m-d');
        }

        return $this->json([
            'base'   => $base->getCode(),
            'date'   => $date ?? date('Y-m-d'),
            'source' => 'cbr',
            'rates'  => $ratesMap,
        ]);
    }

    /**
     * GET /api/convert?amount=1500&from=RUB&to=USD
     * Конвертирует сумму.
     *
     * Response: { "from": "RUB", "to": "USD", "amount": 1500, "result": 16.245, "formatted": "$16.25" }
     */
    #[Route('/api/convert', name: 'api_convert')]
    public function apiConvert(Request $request): JsonResponse
    {
        $amount = (float) $request->query->get('amount', 0);
        $from   = strtoupper($request->query->get('from', 'RUB'));
        $to     = strtoupper($request->query->get('to', 'USD'));

        $result    = $this->converter->convert($amount, $from, $to);
        $formatted = $this->converter->format($amount, $from, $to);

        if ($result === null) {
            return $this->json([
                'error' => "Exchange rate not found for {$from}→{$to}",
            ], 404);
        }

        return $this->json([
            'from'      => $from,
            'to'        => $to,
            'amount'    => $amount,
            'result'    => round($result, 8),
            'formatted' => $formatted,
        ]);
    }
}
