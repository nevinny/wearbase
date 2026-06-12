<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Country;
use App\Entity\ShippingRule;
use App\Repository\ShippingRuleRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

readonly class DeliveryService
{
    private const CDEK_API = 'https://api.cdek.ru/v2';
    private const BOXBERRY_API = 'https://api.boxberry.ru/json/v1';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ShippingRuleRepository $shippingRepo,
        private string $cdekAccount,
        private string $cdekPassword,
        private string $boxberryToken,
    ) {}

    /**
     * @return array{price: float, daysMin: int, daysMax: int}|null
     */
    public function calculate(
        string $carrier,
        Country $country,
        string $fromCity,
        string $toCity,
        float $weightKg,
        ?float $declaredValue = null,
    ): ?array {
        $rule = $this->shippingRepo->findByCarrier($carrier);
        $defaultRule = $rule[0] ?? null;

        $apiResult = match ($carrier) {
            ShippingRule::CARRIER_CDEK => $this->calculateCdek($fromCity, $toCity, $weightKg, $declaredValue),
            ShippingRule::CARRIER_BOXBERRY => $this->calculateBoxberry($toCity, $weightKg),
            default => null,
        };

        if ($apiResult !== null) {
            return $apiResult;
        }

        // Fallback: static rule from DB
        if ($defaultRule) {
            return [
                'price'   => (float) $defaultRule->getPriceRub(),
                'daysMin' => $defaultRule->getDaysMin(),
                'daysMax' => $defaultRule->getDaysMax(),
            ];
        }

        return null;
    }

    /**
     * @return array{price: float, daysMin: int, daysMax: int}|null
     */
    public function calculateCdek(string $from, string $to, float $weightKg, ?float $declaredValue = null): ?array
    {
        if ($this->cdekAccount === '' || $this->cdekPassword === '') {
            return null;
        }

        try {
            $token = $this->getCdekToken();
            if (!$token) return null;

            $payload = [
                'tariff_code' => '483', // Интернет-магазин (посылка)
                'from_location' => ['address' => $from],
                'to_location'   => ['address' => $to],
                'packages' => [[
                    'weight' => max(1, (int) ($weightKg * 1000)), // граммы
                ]],
            ];

            if ($declaredValue !== null) {
                $payload['declared_value'] = $declaredValue;
            }

            $response = $this->httpClient->request('POST', self::CDEK_API . '/calculator/tariff', [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
            ]);

            $data = $response->toArray();

            if (isset($data['total_sum'])) {
                return [
                    'price'   => (float) $data['total_sum'] / 100, // копейки → рубли
                    'daysMin' => (int) ($data['delivery_period_min'] ?? 1),
                    'daysMax' => (int) ($data['delivery_period_max'] ?? 7),
                ];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{price: float, daysMin: int, daysMax: int}|null
     */
    public function calculateBoxberry(string $toCity, float $weightKg): ?array
    {
        if ($this->boxberryToken === '') {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', self::BOXBERRY_API . '/DeliveryCalculation', [
                'query' => [
                    'token'     => $this->boxberryToken,
                    'weight'    => $weightKg,
                    'target'    => $toCity,
                    'target_start' => 'Москва',
                ],
            ]);

            $data = $response->toArray();

            if (isset($data[0]['price'])) {
                return [
                    'price'   => (float) $data[0]['price'],
                    'daysMin' => (int) ($data[0]['delivery_period'] ?? 1),
                    'daysMax' => (int) ($data[0]['delivery_period'] ?? 7),
                ];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getCdekToken(): ?string
    {
        try {
            $response = $this->httpClient->request('POST', self::CDEK_API . '/oauth/token', [
                'body' => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->cdekAccount,
                    'client_secret' => $this->cdekPassword,
                ],
            ]);

            $data = $response->toArray();
            return $data['access_token'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
