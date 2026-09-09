<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandUser;
use App\Entity\Notification;
use App\Entity\User;
use App\Notification\NotificationDispatcher;
use App\Repository\BrandRepository;
use App\Repository\BrandUserRepository;
use App\Repository\ProductIntentClickRepository;
use App\Repository\ProductRepository;
use App\Twig\BrandSaleExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Прогрессивные напоминания владельцу опубликованного бренда, у которого не настроен
 * приём онлайн-оплаты (App\Twig\BrandSaleExtension::canSell() == false): на 1/3/7/14/30-й
 * полный день после публикации — письмо + in-app уведомление. dedupe встроен в
 * NotificationDispatcher::dispatchOnce (ключ payment_reminder:{brandId}:{day}), поэтому
 * повторный ежедневный запуск не дублирует.
 *
 * Прод, ежедневно (см. migrations/Version20260831_payment_reminders_cron.php).
 */
#[AsCommand(name: 'app:brand:payment-reminders', description: 'Напоминания владельцам брендов без настроенного приёма оплаты (1/3/7/14/30 день)')]
final class BrandPaymentRemindersCommand extends Command
{
    private const TIMEZONE = 'Europe/Moscow';

    /** @var list<int> */
    private const MILESTONE_DAYS = [1, 3, 7, 14, 30];

    public function __construct(
        private readonly BrandRepository $brands,
        private readonly BrandUserRepository $brandUsers,
        private readonly ProductRepository $products,
        private readonly ProductIntentClickRepository $intentClicks,
        private readonly BrandSaleExtension $saleGate,
        private readonly NotificationDispatcher $notifications,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать количество писем без записи')
            ->addOption('now', null, InputOption::VALUE_REQUIRED, 'Текущее время для воспроизводимого запуска (ISO-8601)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $now = $this->now($input->getOption('now'));
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());
            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $todayDate = new \DateTimeImmutable(
            $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d'),
            new \DateTimeZone(self::TIMEZONE),
        );

        $sent = 0;
        foreach ($this->brands->findActiveOwnedWithProducts() as $brand) {
            $publishedAt = $brand->getPublishedAt();
            if ($publishedAt === null) {
                continue;
            }
            $publishedDate = new \DateTimeImmutable($publishedAt->format('Y-m-d'), new \DateTimeZone(self::TIMEZONE));
            $day = (int) $publishedDate->diff($todayDate)->format('%a');

            if (!in_array($day, self::MILESTONE_DAYS, true)) {
                continue;
            }
            if ($this->saleGate->canSell($brand)) {
                continue;
            }

            $owner = $this->brandUsers->findOneBy(['brand' => $brand, 'role' => BrandUser::ROLE_OWNER])?->getUser();
            if ($owner === null) {
                continue;
            }

            if ($dryRun) {
                $sent++;
                continue;
            }

            $this->remind($brand, $owner, $day);
            $sent++;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf('%s: %d', $dryRun ? 'Будет отправлено' : 'Отправлено', $sent));

        return Command::SUCCESS;
    }

    private function remind(Brand $brand, User $owner, int $day): void
    {
        $productsCount = $this->products->countByBrandAndStatus($brand, 'active');
        $intentCount = $this->intentClicks->countForBrand($brand);
        $isLast = $day === 30;

        $this->notifications->dispatchOnce(
            $owner,
            Notification::TYPE_PAYMENT_REMINDER,
            sprintf('payment_reminder:%d:%d', $brand->getId(), $day),
            sprintf('Подключите приём оплаты — бренд «%s»', $brand->getTitle()),
            'Покупатели пока не могут оплатить заказ — настройте счёт приёма оплаты в личном кабинете.',
            ['brand_id' => $brand->getId(), 'day' => $day],
            'brand_payment_setup',
            [
                'brand' => $brand,
                'productsCount' => $productsCount,
                'intentCount' => $intentCount,
                'isLast' => $isLast,
                'paymentsUrl' => 'https://wearbase.ru/brand/payments',
            ],
        );
    }

    private function now(mixed $value): \DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Опция --now должна быть строкой ISO-8601');
        }
        $now = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$now || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException('Некорректное значение --now');
        }

        return $now;
    }
}
