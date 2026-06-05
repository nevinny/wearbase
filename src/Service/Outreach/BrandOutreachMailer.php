<?php

namespace App\Service\Outreach;

use App\Entity\Brand;
use App\Entity\BrandOutreach;
use App\Repository\BrandOutreachRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment;

/**
 * Activation-письмо владельцу бренда «ваша страница опубликована».
 *
 * Отправка через RuSender REST API (X-Api-Key) — SMTP-подключение RuSender
 * требует отдельной активации саппортом, API-ключ работает сразу.
 *
 * ЮРИДИЧЕСКИЕ РАМКИ (дизайн, ФЗ-38 ст.18): письмо — ЧИСТОЕ УВЕДОМЛЕНИЕ,
 * персонифицированное (ЕГО бренд, ЕГО данные), БЕЗ продвижения платных услуг.
 * Никаких цен/подписок в шаблоне. Suppression ПО EMAIL до отправки.
 *
 * From — поддомен mail.wearbase.ru (изоляция репутации от основного домена).
 * Fail-open: ошибки пишутся в attempts/last_error, наружу не летят.
 */
class BrandOutreachMailer
{
    private const API_ENDPOINT = 'https://api.beta.rusender.ru/api/v1/external-mails/send';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::OUTREACH_FROM)%')]
        private readonly ?string $from,          // "WEARBASE <hello@mail.wearbase.ru>"
        #[Autowire('%env(default::OUTREACH_BASE_URL)%')]
        private readonly ?string $trackBaseUrl,  // "https://wearbase.ru" (эндпоинты /e/* на проде)
        #[Autowire('%env(default::RUSENDER_API_KEY)%')]
        private readonly ?string $apiKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->from) !== ''
            && trim((string) $this->trackBaseUrl) !== ''
            && trim((string) $this->apiKey) !== '';
    }

    /**
     * @return bool true = письмо ушло в relay; false = пропущено (suppression/нет email/не настроен) или ошибка
     */
    public function sendFor(Brand $brand, bool $isRetry = false): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $email = trim((string) $brand->getEmail());
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        /** @var BrandOutreachRepository $repo */
        $repo = $this->em->getRepository(BrandOutreach::class);
        if ($repo->isSuppressed($email)) {
            return false;
        }

        $outreach = $repo->findByBrand($brand);
        if ($outreach !== null && $outreach->getSentAt() !== null && !$isRetry) {
            return false; // уже отправляли этому бренду
        }

        if ($outreach === null) {
            $outreach = (new BrandOutreach())
                ->setBrand($brand)
                ->setEmail($email)
                ->setSendToken(bin2hex(random_bytes(16)));
            $this->em->persist($outreach);
            $this->em->flush(); // нужна строка до отправки: retry-финдер видит провалы
        }

        $token = $outreach->getSendToken();
        $base  = rtrim((string) $this->trackBaseUrl, '/');

        $ctx = [
            'brand'     => $brand,
            'stores'    => $brand->getActiveStores()->slice(0, 2),
            'click_url' => $base . '/e/c/' . $token,
            'pixel_url' => $base . '/e/o/' . $token . '.gif',
            'unsub_url' => $base . '/e/u/' . $token,
        ];
        $html = $this->twig->render('email/outreach/brand_published.html.twig', $ctx);
        $text = $this->twig->render('email/outreach/brand_published.txt.twig', $ctx);

        // "WEARBASE <hello@mail.wearbase.ru>" → name + email для RuSender
        $fromName = 'WEARBASE';
        $fromEmail = (string) $this->from;
        if (preg_match('~^(.*?)\s*<([^>]+)>$~', (string) $this->from, $m)) {
            $fromName = trim($m[1]) ?: 'WEARBASE';
            $fromEmail = trim($m[2]);
        }

        try {
            $response = $this->httpClient->request('POST', self::API_ENDPOINT, [
                'headers' => ['X-Api-Key' => (string) $this->apiKey, 'Content-Type' => 'application/json'],
                'json'    => [
                    'idempotencyKey' => 'outreach-' . $token,
                    'mail' => [
                        'to'      => ['email' => $email],
                        'from'    => ['email' => $fromEmail, 'name' => $fromName],
                        'subject' => sprintf('%s — мы опубликовали страницу о вашем бренде на Wearbase', $brand->getTitle()),
                        'html'    => $html,
                        'text'    => $text,
                        'headers' => ['List-Unsubscribe' => sprintf('<%s/e/u/%s>', $base, $token)],
                    ],
                ],
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 300) {
                throw new \RuntimeException(sprintf('RuSender HTTP %d: %s', $status, mb_substr($response->getContent(false), 0, 300)));
            }
        } catch (\Throwable $e) {
            $outreach->setAttempts($outreach->getAttempts() + 1)->setLastError($e->getMessage());
            $this->em->flush();
            $this->logger->warning('outreach send failed', ['brand' => $brand->getId(), 'error' => $e->getMessage()]);

            return false;
        }

        $outreach->setSentAt($outreach->getSentAt() ?? new \DateTime())
            ->setAttempts($outreach->getAttempts() + 1)
            ->setLastError(null);
        $this->em->flush();

        return true;
    }
}
