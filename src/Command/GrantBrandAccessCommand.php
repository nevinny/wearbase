<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Brand;
use App\Entity\BrandUser;
use App\Entity\User;
use App\Notification\EmailNotifier;
use App\Service\SubscriptionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Ручная выдача доступа в ЛК бренда — для лидов, которых обрабатываем руками
 * (sales_offer.md §11). Делает то же, что самостоятельная регистрация
 * `/register?brand=1`, но за человека: аккаунт + бренд + связь владельца + free-trial.
 *
 * Бренд заводится в статусе `new` (НЕ публикуется в каталоге) — название владелец
 * поправит сам в /brand/settings. Пароль временный: печатается в вывод, передать
 * владельцу отдельным каналом и попросить сменить.
 *
 * Идемпотентна: повторный запуск не дублирует бренд/связь, только переустанавливает пароль.
 */
#[AsCommand(
    name: 'app:brand:grant-access',
    description: 'Выдать доступ в ЛК бренда вручную (аккаунт + бренд-заготовка + временный пароль)',
)]
class GrantBrandAccessCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly SluggerInterface $slugger,
        private readonly SubscriptionFactory $subscriptionFactory,
        private readonly EmailNotifier $emailNotifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email владельца (логин)')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Название бренда (по умолчанию — из email)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Временный пароль (по умолчанию генерируется)')
            ->addOption('send', null, InputOption::VALUE_NONE, 'Отправить владельцу пригласительное письмо с доступом');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = strtolower(trim((string) $input->getOption('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Нужен корректный --email');
            return Command::INVALID;
        }

        $password = (string) ($input->getOption('password') ?? '');
        if ($password === '') {
            $password = $this->generatePassword();
        }

        $userRepo = $this->em->getRepository(User::class);
        $user = $userRepo->findOneBy(['email' => $email]);
        $userIsNew = $user === null;

        if ($userIsNew) {
            $user = new User();
            $user->setEmail($email);
            $this->em->persist($user);
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        // Доступ выдан руками — верификацию email считаем пройденной, иначе владелец
        // упрётся в баннер «подтвердите email» с токеном, которого ему никто не присылал.
        if (!$user->isEmailVerified()) {
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
        }
        if (!in_array('ROLE_BRAND_MANAGER', $user->getRoles(), true)) {
            $user->setRoles(['ROLE_BRAND_MANAGER']);
        }

        $brandUserRepo = $this->em->getRepository(BrandUser::class);
        $existingLink = $brandUserRepo->findOneBy(['user' => $user]);

        if ($existingLink !== null) {
            $brand = $existingLink->getBrand();
            $io->note(sprintf('Пользователь уже привязан к бренду «%s» — пересоздавать не буду, только пароль.', (string) $brand?->getTitle()));
        } else {
            $title = trim((string) $input->getOption('title'));
            if ($title === '') {
                // Бренд неизвестен (лид оставил только почту) — заготовка с понятным
                // временным названием, владелец переименует в /brand/settings.
                $title = 'Новый бренд ' . explode('@', $email)[0];
            }

            $brand = new Brand();
            $brand->setTitle($title);
            $brand->setSlug($this->uniqueSlug($title));
            $brand->setStatus(Statuses::New);
            $this->em->persist($brand);

            $link = new BrandUser();
            $link->setUser($user);
            $link->setBrand($brand);
            $link->setRole(BrandUser::ROLE_OWNER);
            $link->setAcceptedAt(new \DateTimeImmutable());
            $this->em->persist($link);

            $this->subscriptionFactory->createFreeTrial($brand);
        }

        $this->em->flush();

        $io->success($userIsNew ? 'Аккаунт создан' : 'Аккаунт найден, пароль переустановлен');
        $io->definitionList(
            ['Логин' => $email],
            ['Временный пароль' => $password],
            ['Вход' => 'https://wearbase.ru/login'],
            ['Кабинет' => 'https://wearbase.ru/brand/dashboard'],
            ['Бренд' => sprintf('«%s» (id %d, статус %s)', (string) $brand->getTitle(), (int) $brand->getId(), (string) $brand->getStatus()?->value)],
        );
        if ($input->getOption('send')) {
            // Письмо уходит через EmailNotifier → MAILER_DSN (на проде rusender+api).
            // Ключ `email` в контексте запрещён (см. память/sales_offer §11.2-bis) — только login.
            $this->emailNotifier->send(
                $email,
                'Доступ в кабинет бренда на WEARBASE',
                'brand_access_granted',
                [
                    'login' => $email,
                    'tempPassword' => $password,
                    'brandTitle' => (string) $brand->getTitle(),
                ],
            );
            $io->success('Пригласительное письмо отправлено на ' . $email);
        } else {
            $io->warning('Пароль передать владельцу и попросить сменить: /forgot-password → письмо со ссылкой на новый пароль. Отправить письмо автоматически: --send');
        }

        return Command::SUCCESS;
    }

    private function uniqueSlug(string $title): string
    {
        $base = strtolower((string) $this->slugger->slug($title));
        $slug = $base;
        $i = 1;

        while ($this->em->getRepository(Brand::class)->findOneBy(['slug' => $slug])) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    /** Читаемый временный пароль: без похожих символов, диктуется голосом. */
    private function generatePassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzACDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
