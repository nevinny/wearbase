<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandClaim;
use App\Entity\BrandInvite;
use App\Entity\BrandUser;
use App\Entity\NewsletterSubscriber;
use App\Entity\Order;
use App\Entity\User;
use App\Notification\EmailNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Дымовой тест транзакционной почты: реально отправляет шаблоны письма на указанный адрес
 * через тот же {@see EmailNotifier}, которым шлёт приложение.
 *
 * Зачем отдельно от `mailer:test`: тот шлёт обычный `Email` с готовым текстом, ему НЕ нужен
 * twig-рендер — именно поэтому 19.07.2026 он показывал «401, ключ отозван», пока настоящие
 * `TemplatedEmail` падали раньше на пустом теле (docs/production.md, раздел почты).
 * Здесь проверяется весь путь: рендер шаблона → From/Reply-To → транспорт → ответ API.
 *
 * Шаблонам, которым нужны сущности (заказ, заявка, приглашение, дайджест), собираются
 * ОТСОЕДИНЁННЫЕ объекты в памяти: в БД ничего не пишется, id проставляется рефлексией —
 * иначе `url()` с параметром id не сгенерируется. Бренд берётся реальный (первый из каталога).
 */
#[AsCommand(
    name: 'app:mail:test',
    description: 'Отправить тестовые транзакционные письма на адрес (проверка рендера + доставки)',
)]
class MailTestCommand extends Command
{
    /** Шаблоны с простым контекстом — покрывают то, что ломалось. */
    private const TEMPLATES = [
        'lead_welcome', 'brand_access_granted', 'new_lead', 'verify_email', 'reset_password',
        'brand_claim_code', 'brand_claim_admin', 'brand_claim_approved', 'brand_claim_rejected',
        'brand_invite', 'order_confirmation', 'order_status_changed', 'new_order_brand',
        'newsletter_digest',
    ];

    public function __construct(
        private readonly EmailNotifier $notifier,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Куда отправлять')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Один шаблон вместо всех: ' . implode(', ', self::TEMPLATES));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim((string) $input->getArgument('email'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Нужен корректный email');
            return Command::INVALID;
        }

        $only = $input->getOption('only');
        $templates = $only !== null ? [$only] : self::TEMPLATES;

        foreach ($templates as $template) {
            if (!in_array($template, self::TEMPLATES, true)) {
                $io->error(sprintf('Шаблон «%s» не поддержан. Доступны: %s', $template, implode(', ', self::TEMPLATES)));
                return Command::INVALID;
            }
        }

        $rows = [];
        $failed = 0;

        foreach ($templates as $template) {
            $context = $this->contextFor($template);
            if ($context === null) {
                $rows[] = [$template, '— пропущен (нет данных в БД)'];
                continue;
            }

            $ok = $this->notifier->send($email, '[ТЕСТ] ' . $template . ' — WEARBASE', $template, $context);
            $rows[] = [$template, $ok ? '✅ отправлено' : '❌ ошибка (см. лог)'];
            $failed += $ok ? 0 : 1;
        }

        $io->table(['Шаблон', 'Результат'], $rows);

        if ($failed > 0) {
            $io->error(sprintf('%d из %d писем не ушло — причина в логе (app.ERROR «Email notification failed»)', $failed, count($templates)));
            return Command::FAILURE;
        }

        $io->success('Все письма приняты транспортом. Проверьте ящик ' . $email);

        return Command::SUCCESS;
    }

    /** @return array<string,mixed>|null null — шаблон нельзя проверить без данных */
    private function contextFor(string $template): ?array
    {
        return match ($template) {
            'lead_welcome' => ['brandName' => 'Тестовый Бренд'],
            'brand_access_granted' => [
                'login' => 'test@example.com',
                'tempPassword' => 'TempPass123',
                'brandTitle' => 'Тестовый Бренд',
            ],
            'new_lead' => [
                'leadEmail' => 'lead@example.com',
                'source' => 'for-brands',
                'brandName' => 'Тестовый Бренд',
                'website' => 'example.com',
            ],
            'verify_email', 'reset_password' => ['token' => str_repeat('t', 32)],
            'brand_claim_code' => ($brand = $this->anyBrand()) !== null ? ['brand' => $brand, 'code' => '123456'] : null,
            'brand_claim_admin', 'brand_claim_approved', 'brand_claim_rejected' => ($claim = $this->fakeClaim()) !== null
                ? ['claim' => $claim]
                : null,
            'brand_invite' => ($brand = $this->anyBrand()) !== null
                ? [
                    'brand' => $brand,
                    'invitedBy' => $this->fakeUser(),
                    'role' => BrandUser::ROLE_OWNER,
                    'token' => str_repeat('i', 32),
                ]
                : null,
            'order_confirmation', 'order_status_changed', 'new_order_brand' => ($order = $this->fakeOrder()) !== null
                ? ['order' => $order]
                : null,
            'newsletter_digest' => ($brand = $this->anyBrand()) !== null
                ? [
                    'brands' => [$brand],
                    'saleCount' => 12,
                    // Токен отписки генерирует конструктор подписчика — сеттера нет и не нужен.
                    'subscriber' => (new NewsletterSubscriber())->setEmail('test@example.com'),
                ]
                : null,
            default => null,
        };
    }

    private function anyBrand(): ?Brand
    {
        return $this->em->getRepository(Brand::class)->findOneBy(['status' => 'active'], ['id' => 'ASC']);
    }

    private function fakeUser(): User
    {
        return (new User())->setEmail('customer@example.com')->setFirstName('Иван')->setLastName('Петров');
    }

    private function fakeClaim(): ?BrandClaim
    {
        if (($brand = $this->anyBrand()) === null) {
            return null;
        }

        $claim = (new BrandClaim())
            ->setBrand($brand)
            ->setUser($this->fakeUser())
            ->setStatus(BrandClaim::STATUS_PENDING)
            ->setComment('Я владелец, подтверждаю права на бренд.')
            ->setAdminNote('Не хватает подтверждения с домена бренда.');

        return $this->withId($claim, 1);
    }

    private function fakeOrder(): ?Order
    {
        if (($brand = $this->anyBrand()) === null) {
            return null;
        }

        $order = (new Order())
            ->setOrderNumber('TEST-000001')
            ->setBrand($brand)
            ->setCustomer($this->fakeUser())
            ->setStatus(Order::STATUS_SHIPPED)
            ->setTrackingNumber('RU123456789')
            ->setSubtotal('4900.00')
            ->setShippingCost('400.00')
            ->setDiscountAmount('0.00')
            ->setTotalAmount('5300.00');

        return $this->withId($order, 1);
    }

    /**
     * Проставить id отсоединённой сущности: шаблоны строят ссылки через url(..., {id: ...}),
     * а без id генератор маршрутов падает. В БД при этом ничего не пишем.
     *
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function withId(object $entity, int $id): object
    {
        $property = new \ReflectionProperty($entity::class, 'id');
        $property->setValue($entity, $id);

        return $entity;
    }
}
