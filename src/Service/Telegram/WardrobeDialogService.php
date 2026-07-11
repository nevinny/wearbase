<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Entity\TelegramDialogState;
use App\Entity\User;
use App\Entity\WardrobeItem;
use App\Notification\TelegramNotifier;
use App\Repository\TelegramDialogStateRepository;
use App\Repository\WardrobeItemRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypes;

/**
 * Telegram-диалог «Мой гардероб»: сбор черновика вещи (фото + шаблон в подписи
 * или отдельными сообщениями) → создание WardrobeItem. Транспорт-агностик:
 * контроллер вебхука передаёт сюда message/callback, false = «не моё, в воронку».
 */
class WardrobeDialogService
{
    private const LOVE_KEYBOARD = ['inline_keyboard' => [[
        ['text' => 'Да', 'callback_data' => 'wl:yes'],
        ['text' => 'Нет', 'callback_data' => 'wl:no'],
        ['text' => 'Пока не знаю', 'callback_data' => 'wl:unknown'],
    ]]];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly WardrobeItemRepository $itemRepo,
        private readonly TelegramDialogStateRepository $stateRepo,
        private readonly WardrobeTemplate $template,
        private readonly TelegramFileFetcher $fileFetcher,
        private readonly TelegramNotifier $telegram,
        private readonly LoggerInterface $telegramLogger,
    ) {}

    /**
     * @param array<string, mixed> $message Telegram message
     * @return bool true — сообщение обработано диалогом; false — не для гардероба (пусть падает в воронку)
     */
    public function handle(User $user, array $message, int $updateId): bool
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        if ($chatId === '') {
            return false;
        }

        $text = trim((string) ($message['text'] ?? ''));
        $command = (string) preg_replace('/@\w+$/', '', $text); // «/wardrobe@wearbase_bot» → «/wardrobe»

        if ($command === '/wardrobe') {
            $state = $this->freshState($chatId);
            $state->setLastUpdateId($updateId);
            $this->doctrine->getManager()->flush();
            $this->telegram->send($chatId, $this->template->instruction());
            return true;
        }

        if ($command === '/cancel') {
            $state = $this->stateRepo->findByChatId($chatId);
            if ($state !== null) {
                $em = $this->doctrine->getManager();
                $em->remove($state);
                $em->flush();
            }
            $this->telegram->send($chatId, '❌ Черновик сброшен. /wardrobe — начать заново.');
            return true;
        }

        $state = $this->activeState($chatId);
        if ($state === null) {
            // Нет активного диалога: фото и обычный текст не перехватываем (воронка)
            return false;
        }
        if ($text !== '' && str_starts_with($text, '/')) {
            // Чужие команды (/start и т.п.) диалог не глотает
            return false;
        }

        // Дедуп ретраев вебхука
        if ($state->getLastUpdateId() !== null && $updateId <= $state->getLastUpdateId()) {
            return true;
        }
        $state->setLastUpdateId($updateId);

        $draft = $state->getDraft();

        $photos = $message['photo'] ?? null;
        if (is_array($photos) && $photos !== []) {
            $largest = end($photos); // Telegram шлёт варианты по возрастанию, берём последний
            if (isset($largest['file_id'])) {
                $draft['photo_file_id'] = (string) $largest['file_id'];
            }
        }

        $input = $text !== '' ? $text : trim((string) ($message['caption'] ?? ''));
        if ($input !== '') {
            $draft = array_merge($draft, $this->template->parse($input));
        }

        $state->setDraft($draft)->touch();
        $this->tryCommitOrPrompt($user, $state, $chatId);

        return true;
    }

    /** @return bool true — callback обработан (wl:*) */
    public function handleCallback(User $user, string $data, string $chatId): bool
    {
        if (!str_starts_with($data, 'wl:')) {
            return false;
        }

        $love = match (substr($data, 3)) {
            'yes'     => WardrobeItem::LOVE_YES,
            'no'      => WardrobeItem::LOVE_NO,
            'unknown' => WardrobeItem::LOVE_UNKNOWN,
            default   => null,
        };
        if ($love === null) {
            $this->telegramLogger->warning('TG wl: неизвестный payload', ['chat' => $chatId, 'data' => $data]);
            return true;
        }

        $state = $this->activeState($chatId);
        if ($state === null) {
            $this->telegram->send($chatId, 'Черновик не найден. /wardrobe — начать новую вещь.');
            return true;
        }

        $draft = $state->getDraft();
        $draft['love'] = $love;
        $state->setDraft($draft)->touch();
        $this->tryCommitOrPrompt($user, $state, $chatId);

        return true;
    }

    /** Активное (не протухшее) состояние; протухшее удаляется (lazy-expiry, TTL 24ч). */
    private function activeState(string $chatId): ?TelegramDialogState
    {
        $state = $this->stateRepo->findByChatId($chatId);
        if ($state !== null && $state->isStale()) {
            $em = $this->doctrine->getManager();
            $em->remove($state);
            $em->flush();
            return null;
        }
        return $state;
    }

    private function freshState(string $chatId): TelegramDialogState
    {
        $state = $this->stateRepo->findByChatId($chatId);
        if ($state === null) {
            $state = new TelegramDialogState($chatId);
            $this->doctrine->getManager()->persist($state);
        }
        $state->setState(TelegramDialogState::STATE_COLLECTING)->setDraft([])->touch();

        return $state;
    }

    /** Все обязательные поля собраны → коммит вещи; иначе — сохранить draft и спросить недостающее. */
    private function tryCommitOrPrompt(User $user, TelegramDialogState $state, string $chatId): void
    {
        $draft = $state->getDraft();
        $missing = $this->template->missingRequired($draft);

        if ($missing !== []) {
            $this->doctrine->getManager()->flush();
            $msg = '📝 Принято. Для сохранения не хватает: <b>' . implode('</b>, <b>', $missing) . '</b>.'
                . "\nДошлите отдельными строками «Ключ: значение», например: Название: Белая футболка";
            $markup = null;
            if (!isset($draft['love'])) {
                $msg .= "\n\nЛюбовь с первого взгляда — можно кнопкой:";
                $markup = self::LOVE_KEYBOARD;
            }
            $this->telegram->send($chatId, $msg, $markup);
            return;
        }

        $item = $this->commitDraft($user, $draft);

        // После возможного resetManager (retry) state детачнут — перечитываем и удаляем
        $managedState = $this->stateRepo->findByChatId($chatId);
        if ($managedState !== null) {
            $em = $this->doctrine->getManager();
            $em->remove($managedState);
            $em->flush();
        }

        $stats = $this->itemRepo->getStats($item->getUser());
        $this->telegram->send($chatId, implode("\n\n", [
            $this->template->formatCard($item),
            $this->template->formatStats(
                $stats,
                (int) array_sum(array_column($stats, 'cnt')),
                (float) array_sum(array_map('floatval', array_column($stats, 'total'))),
            ),
            $this->template->blankTemplate(),
        ]));
    }

    /** @param array<string, mixed> $draft */
    private function commitDraft(User $user, array $draft): WardrobeItem
    {
        $item = new WardrobeItem();
        $item->setCategory((string) $draft['category']);
        $item->setName((string) $draft['name']);
        $item->setSize(isset($draft['size']) ? (string) $draft['size'] : null);
        $item->setPrice(isset($draft['price']) ? (string) $draft['price'] : null);
        $item->setProductUrl(isset($draft['product_url']) ? (string) $draft['product_url'] : null);
        $item->setPurchaseReason(isset($draft['purchase_reason']) ? (string) $draft['purchase_reason'] : null);
        $item->setLoveAtFirstSight(isset($draft['love']) ? (string) $draft['love'] : null);
        $item->setSource(WardrobeItem::SOURCE_TELEGRAM);
        if (isset($draft['purchased_at'])) {
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $draft['purchased_at']);
            $item->setPurchasedAt($dt === false ? null : $dt);
        }

        // Фото: скачиваем во tmp → как веб-аплоад через Vich. Ошибка скачивания → вещь без фото.
        if (isset($draft['photo_file_id'])) {
            $tmpPath = $this->fileFetcher->fetchToTmp((string) $draft['photo_file_id']);
            if ($tmpPath !== null) {
                $mime = MimeTypes::getDefault()->guessMimeType($tmpPath) ?? 'image/jpeg';
                $item->setPhotoFile(new UploadedFile($tmpPath, basename($tmpPath), $mime, null, true));
            }
        }

        $em = $this->doctrine->getManager();
        $item->setUser($user);
        $item->setItemNo($this->itemRepo->nextItemNo($user));
        try {
            $em->persist($item);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // Гонка за item_no: один retry со свежим номером (EM после исключения закрыт)
            $this->doctrine->resetManager();
            $em = $this->doctrine->getManager();
            /** @var User $user */
            $user = $em->find(User::class, $user->getId());
            $item->setUser($user);
            $item->setItemNo($this->itemRepo->nextItemNo($user));
            // Vich мог успеть переместить tmp-файл при первой попытке — не переаплоадим исчезнувший
            if ($item->getPhotoFile() !== null && !file_exists($item->getPhotoFile()->getPathname())) {
                $item->setPhotoFile(null);
            }
            $em->persist($item);
            $em->flush();
        }

        return $item;
    }
}
