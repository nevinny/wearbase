<?php

namespace App\Service\Outreach;

use App\Entity\Brand;
use App\Entity\BrandOutreach;
use App\Repository\BrandOutreachRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Activation-письмо владельцу бренда «ваша страница опубликована».
 *
 * ЮРИДИЧЕСКИЕ РАМКИ (дизайн, ФЗ-38 ст.18): письмо — ЧИСТОЕ УВЕДОМЛЕНИЕ,
 * персонифицированное (ЕГО бренд, ЕГО данные), БЕЗ продвижения платных услуг.
 * Никаких цен/подписок в шаблоне. Suppression ПО EMAIL до отправки.
 *
 * From — поддомен mail.wearbase.ru (изоляция репутации от основного домена).
 * Fail-open: ошибки SMTP пишутся в attempts/last_error, наружу не летят.
 */
class BrandOutreachMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::OUTREACH_FROM)%')]
        private readonly ?string $from,          // "WEARBASE <hello@mail.wearbase.ru>"
        #[Autowire('%env(default::OUTREACH_BASE_URL)%')]
        private readonly ?string $trackBaseUrl,  // "https://wearbase.ru" (эндпоинты /e/* на проде)
    ) {
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->from) !== '' && trim((string) $this->trackBaseUrl) !== '';
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

        $message = (new TemplatedEmail())
            ->from(Address::create((string) $this->from))
            ->to($email)
            ->replyTo('hello@wearbase.ru')
            ->subject(sprintf('%s — мы опубликовали страницу о вашем бренде на Wearbase', $brand->getTitle()))
            ->htmlTemplate('email/outreach/brand_published.html.twig')
            ->textTemplate('email/outreach/brand_published.txt.twig')
            ->context([
                'brand'      => $brand,
                'stores'     => $brand->getActiveStores()->slice(0, 2),
                'click_url'  => $base . '/e/c/' . $token,
                'pixel_url'  => $base . '/e/o/' . $token . '.gif',
                'unsub_url'  => $base . '/e/u/' . $token,
            ]);

        $headers = $message->getHeaders();
        $headers->addTextHeader('List-Unsubscribe', sprintf('<%s/e/u/%s>', $base, $token));
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click'); // RFC 8058
        $headers->addTextHeader('X-Outreach-Token', $token); // эхо для вебхуков SMTP-сервиса

        try {
            $this->mailer->send($message);
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
