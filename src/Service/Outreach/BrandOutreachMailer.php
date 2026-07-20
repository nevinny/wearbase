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
        private readonly WarmOfferService $warmOffer,
        #[Autowire('%env(default::OUTREACH_FROM)%')]
        private readonly ?string $from,          // "WEARBASE <hello@mail.wearbase.ru>"
        #[Autowire('%env(default::OUTREACH_BASE_URL)%')]
        private readonly ?string $trackBaseUrl,  // "https://wearbase.ru" (эндпоинты /e/* на проде)
        #[Autowire('%env(default::RUSENDER_API_KEY)%')]
        private readonly ?string $apiKey,
        #[Autowire('%env(default::OUTREACH_REPLY_TO)%')]
        private readonly ?string $replyTo,   // куда приходят ответы (продажи!); пусто = ответы на From
    ) {
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->from) !== ''
            && trim((string) $this->trackBaseUrl) !== ''
            && trim((string) $this->apiKey) !== '';
    }

    /**
     * Контекст письма: динамический крючок «что знаем» из реально доступного —
     * каналы (ссылки, лейбл по хосту), категории-ассортимент (brand_attribute),
     * город, магазины. Используется и warmup-отправкой, и тест-командой.
     *
     * @return array<string,mixed>
     */
    public function buildContext(Brand $brand, string $base, string $token): array
    {
        // Каналы: лейбл по хосту (link_type часто 'other' из enrichment).
        $channels = [];
        foreach ($brand->getLinks() as $link) {
            $u = mb_strtolower((string) $link->getLinkUrl());
            if ($u === '') {
                continue;
            }
            $channels[] = match (true) {
                str_contains($u, 'instagram.') => 'Instagram',
                str_contains($u, 'vk.com'), str_contains($u, 'vkontakte') => 'VK',
                str_contains($u, 't.me/'), str_contains($u, 'telegram.') => 'Telegram',
                str_contains($u, 'youtube.'), str_contains($u, 'youtu.be') => 'YouTube',
                str_contains($u, 'tiktok.') => 'TikTok',
                default => 'сайт',
            };
        }
        $channels = array_values(array_unique($channels));

        // Ассортимент-категории из извлечённых атрибутов.
        $categories = [];
        foreach ($this->em->getRepository(\App\Entity\BrandAttribute::class)->findByBrand($brand) as $a) {
            if ($a->getName() === \App\Entity\BrandAttribute::NAME_CATEGORY) {
                $categories[] = $a->getValue();
            }
        }
        $categories = array_slice(array_values(array_unique($categories)), 0, 5);

        return [
            'brand'      => $brand,
            'channels'   => $channels,
            'categories' => $categories,
            'stores'     => $brand->getActiveStores()->slice(0, 2),
            'click_url'  => $base . '/e/c/' . $token,
            'pixel_url'  => $base . '/e/o/' . $token . '.gif',
            'unsub_url'  => $base . '/e/u/' . $token,
        ];
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

        // Belt-and-suspenders (инцидент: письма ушли иностранным брендам — Tissot, Agent
        // Provocateur — когорта не фильтровала origin/niche). Даже ручной --slugs/прямой вызов
        // не должен уйти иностранному или вне-нишевому бренду.
        if ($brand->isForeignOrigin() || $brand->isOffNiche()) {
            $this->logger->warning('outreach skipped: foreign/off-niche brand', [
                'brand' => $brand->getId(), 'origin_status' => $brand->getOriginStatus(), 'niche_status' => $brand->getNicheStatus(),
            ]);

            return false;
        }

        // Guard чужой сущности: email на брендовом домене ≠ own-site домена (инцидент
        // Majestic — корпус/контакты от majestic.com при бренде majestic.store). Free-провайдеры
        // (gmail/yandex/mail.ru) пропускаем — у мелких брендов это норма.
        if ($this->emailDomainMismatch($brand, $email)) {
            return false;
        }

        /** @var BrandOutreachRepository $repo */
        $repo = $this->em->getRepository(BrandOutreach::class);
        if ($repo->isSuppressed($email)) {
            return false;
        }

        // Частотный кап по email: не слать, если письмо на этот адрес уже уходило за 30 дней
        // (любой канал/бренд). Повтор — не чаще раза в месяц.
        if ($repo->recentlyContacted($email)) {
            $this->logger->info('outreach skipped: email contacted within 30 days', ['email' => $email]);
            return false;
        }

        $existing = $repo->findByBrand($brand);
        if ($existing !== null && $existing->getSentAt() !== null && !$isRetry) {
            return false; // уже отправляли этому бренду
        }

        $outreach = $this->getOrCreateOutreach($brand, $email);
        $token = $outreach->getSendToken();
        $base  = rtrim((string) $this->trackBaseUrl, '/');

        $ctx = $this->buildContext($brand, $base, $token);
        $html = $this->twig->render('email/outreach/brand_published.html.twig', $ctx);
        $text = $this->twig->render('email/outreach/brand_published.txt.twig', $ctx);

        [$fromName, $fromEmail] = $this->parseFrom();

        try {
            $this->postMail('outreach-' . $token, [
                'to'      => ['email' => $email],
                'from'    => ['email' => $fromEmail, 'name' => $fromName],
                'subject' => sprintf('«%s» — ваша страница уже в каталоге Wearbase', $brand->getTitle()),
                'html'    => $html,
                'text'    => $text,
                'headers' => array_filter([
                    'List-Unsubscribe' => sprintf('<%s/e/u/%s>', $base, $token),
                    // Ответ на письмо = тёплый лид: без Reply-To ответы падают на From
                    // (ящик рассылочного поддомена), где их никто не читает.
                    'Reply-To' => trim((string) $this->replyTo) ?: null,
                ]),
            ]);
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

    /**
     * Тёплый оффер «Размещение под ключ» 5000₽ уже опубликованному бренду с реальным
     * поисковым трафиком (docs/sales_offer.md). Те же guard'ы, что у sendFor()
     * (suppression/origin-niche/domain-mismatch), НО НЕ проверяет brand_outreach.sentAt —
     * то поле означает «уходило activation-письмо» (холодный тач), а идемпотентность
     * тёплого оффера — на стороне вызывающей команды (outreach_log.status).
     *
     * @return bool true = письмо ушло в relay; false = пропущено (guard/не настроен) или ошибка
     */
    public function sendWarmOfferFor(Brand $brand): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $email = trim((string) $brand->getEmail());
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if ($brand->isForeignOrigin() || $brand->isOffNiche()) {
            $this->logger->warning('warm offer skipped: foreign/off-niche brand', [
                'brand' => $brand->getId(), 'origin_status' => $brand->getOriginStatus(), 'niche_status' => $brand->getNicheStatus(),
            ]);

            return false;
        }

        if ($this->emailDomainMismatch($brand, $email)) {
            return false;
        }

        /** @var BrandOutreachRepository $repo */
        $repo = $this->em->getRepository(BrandOutreach::class);
        if ($repo->isSuppressed($email)) {
            return false;
        }

        // Частотный кап по email: не слать, если письмо на этот адрес уже уходило за 30 дней
        // (любой канал/бренд). Повтор — не чаще раза в месяц.
        if ($repo->recentlyContacted($email)) {
            $this->logger->info('outreach skipped: email contacted within 30 days', ['email' => $email]);
            return false;
        }

        $outreach = $this->getOrCreateOutreach($brand, $email);
        $token    = $outreach->getSendToken();
        $rendered = $this->renderWarmOffer($brand, $token);
        [$fromName, $fromEmail] = $this->parseFrom();
        $base = rtrim((string) $this->trackBaseUrl, '/');

        try {
            $this->postMail('warm-' . $token, [
                'to'      => ['email' => $email],
                'from'    => ['email' => $fromEmail, 'name' => $fromName],
                'subject' => $rendered['subject'],
                'html'    => $rendered['html'],
                'text'    => $rendered['text'],
                'headers' => array_filter([
                    'List-Unsubscribe' => sprintf('<%s/e/u/%s>', $base, $token),
                    'Reply-To' => trim((string) $this->replyTo) ?: null,
                ]),
            ]);
        } catch (\Throwable $e) {
            $outreach->setAttempts($outreach->getAttempts() + 1)->setLastError($e->getMessage());
            $this->em->flush();
            $this->logger->warning('warm offer send failed', ['brand' => $brand->getId(), 'error' => $e->getMessage()]);

            return false;
        }

        $outreach->setSentAt($outreach->getSentAt() ?? new \DateTime())
            ->setAttempts($outreach->getAttempts() + 1)
            ->setLastError(null);
        $this->em->flush();

        return true;
    }

    /**
     * ТЕСТ: то же письмо, что sendWarmOfferFor(), но на произвольный адрес с невалидным
     * (не в БД) токеном — трекинг/suppression/БД не трогаются. Тот же паттерн, что
     * OutreachTestCommand использует для activation-письма.
     *
     * @return int HTTP-статус RuSender (201 = принято в очередь)
     */
    public function sendWarmOfferTest(Brand $brand, string $to): int
    {
        if (trim((string) $this->apiKey) === '') {
            throw new \RuntimeException('RUSENDER_API_KEY не задан');
        }

        $token = 'test' . bin2hex(random_bytes(14)); // не матчит [a-f0-9]{32} — в БД не пишем
        $rendered = $this->renderWarmOffer($brand, $token);
        [$fromName, $fromEmail] = $this->parseFrom();

        return $this->postMail('warm-test-' . $token, [
            'to'      => ['email' => $to],
            'from'    => ['email' => $fromEmail, 'name' => $fromName],
            'subject' => '[ТЕСТ] ' . $rendered['subject'],
            'html'    => $rendered['html'],
            'text'    => $rendered['text'],
        ]);
    }

    /**
     * Subject/html/text тёплого оффера для бренда. Общий источник текста —
     * WarmOfferService::buildDraft() (тот же, что у драфт-файла человек-гейта
     * OutreachWarmRefreshCommand). Используется реальной отправкой и тест-командой.
     *
     * @return array{subject:string, html:string, text:string}
     */
    public function renderWarmOffer(Brand $brand, string $token): array
    {
        $base    = rtrim((string) $this->trackBaseUrl, '/');
        $stats   = $this->warmOffer->fetchStats((int) $brand->getId());
        $similar = $this->warmOffer->findSimilarBrands((int) $brand->getId(), (string) $brand->getCity());
        $lead = [
            'id'          => $brand->getId(),
            'title'       => $brand->getTitle(),
            'slug'        => $brand->getSlug(),
            'email'       => (string) $brand->getEmail(),
            'city'        => $brand->getCity(),
            'clicks'      => $stats['clicks'],
            'impressions' => $stats['impressions'],
        ];
        $draft = $this->warmOffer->buildDraft($lead, $similar);

        $ctx = [
            'brand'     => $brand,
            'body_text' => $draft['body'],
            'body_html' => nl2br(preg_replace(
                '~(https?://\S+)~',
                '<a href="$1" style="color:#2563eb;text-decoration:underline;">$1</a>',
                htmlspecialchars($draft['body'], ENT_QUOTES, 'UTF-8'),
            )),
            'unsub_url' => $base . '/e/u/' . $token,
        ];

        return [
            'subject' => $draft['subject'],
            'html'    => $this->twig->render('email/outreach/warm_offer.html.twig', $ctx),
            'text'    => $this->twig->render('email/outreach/warm_offer.txt.twig', $ctx),
        ];
    }

    /** Находит существующую строку brand_outreach по бренду или создаёт новую (токен на всю жизнь бренда). */
    private function getOrCreateOutreach(Brand $brand, string $email): BrandOutreach
    {
        /** @var BrandOutreachRepository $repo */
        $repo = $this->em->getRepository(BrandOutreach::class);
        $outreach = $repo->findByBrand($brand);
        if ($outreach === null) {
            $outreach = (new BrandOutreach())
                ->setBrand($brand)
                ->setEmail($email)
                ->setSendToken(bin2hex(random_bytes(16)));
            $this->em->persist($outreach);
            $this->em->flush(); // нужна строка до отправки: retry-финдер видит провалы
        }

        return $outreach;
    }

    /** "WEARBASE <hello@mail.wearbase.ru>" → [name, email] для RuSender. @return array{0:string,1:string} */
    private function parseFrom(): array
    {
        $fromName  = 'WEARBASE';
        $fromEmail = (string) $this->from;
        if (preg_match('~^(.*?)\s*<([^>]+)>$~', (string) $this->from, $m)) {
            $fromName  = trim($m[1]) ?: 'WEARBASE';
            $fromEmail = trim($m[2]);
        }

        return [$fromName, $fromEmail];
    }

    /** @param array<string,mixed> $mail RuSender 'mail' payload. @return int HTTP-статус (2xx). */
    private function postMail(string $idempotencyKey, array $mail): int
    {
        $response = $this->httpClient->request('POST', self::API_ENDPOINT, [
            'headers' => ['X-Api-Key' => (string) $this->apiKey, 'Content-Type' => 'application/json'],
            'json'    => ['idempotencyKey' => $idempotencyKey, 'mail' => $mail],
            'timeout' => 30,
        ]);
        $status = $response->getStatusCode();
        if ($status >= 300) {
            throw new \RuntimeException(sprintf('RuSender HTTP %d: %s', $status, mb_substr($response->getContent(false), 0, 300)));
        }

        return $status;
    }

    /** Free-провайдеры — у мелких брендов норма, домен бренда с ними не сравниваем. */
    private const FREE_EMAIL_PROVIDERS = [
        'gmail.com', 'yandex.ru', 'ya.ru', 'yandex.com', 'mail.ru', 'bk.ru', 'list.ru',
        'inbox.ru', 'internet.ru', 'rambler.ru', 'icloud.com', 'me.com', 'outlook.com',
        'hotmail.com', 'proton.me', 'protonmail.com',
    ];

    /**
     * Email на «брендовом» домене, не совпадающем с own-site бренда → подозрение на чужую
     * сущность (Majestic). True = НЕ слать. Free-провайдеры и отсутствие own-site → допускаем.
     */
    private function emailDomainMismatch(Brand $brand, string $email): bool
    {
        $emailDomain = $this->registrableDomain((string) strrchr($email, '@'));
        if ($emailDomain === '' || in_array($emailDomain, self::FREE_EMAIL_PROVIDERS, true)) {
            return false;
        }

        $siteDomain = '';
        foreach ($brand->getLinks() as $link) {
            if ($link->getLinkType() === 'website' && $link->getLinkUrl()) {
                $siteDomain = $this->registrableDomain((string) parse_url($link->getLinkUrl(), PHP_URL_HOST));
                break;
            }
        }
        if ($siteDomain === '') {
            return false; // нет own-site для сравнения — не блокируем
        }

        $mismatch = $emailDomain !== $siteDomain;
        if ($mismatch) {
            $this->logger->warning('outreach skipped: email domain ≠ brand site', [
                'brand' => $brand->getId(), 'email_domain' => $emailDomain, 'site_domain' => $siteDomain,
            ]);
        }

        return $mismatch;
    }

    /** Регистрируемый домен: последние 2 метки хоста, lowercase (mail.brand.ru → brand.ru). */
    private function registrableDomain(string $host): string
    {
        $host = strtolower(trim(ltrim($host, '@')));
        $host = preg_replace('~^www\.~', '', $host);
        $parts = explode('.', $host);

        return count($parts) >= 2 ? implode('.', array_slice($parts, -2)) : $host;
    }
}
