<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\User;
use App\Entity\Wardrobe;
use App\Entity\WardrobeItem;
use App\Entity\WardrobeItemDraft;
use App\Service\FamilyService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Vich\UploaderBundle\Storage\StorageInterface;

final class WardrobeDraftPromotionService
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly FamilyService $families,
        private readonly StorageInterface $storage,
        private readonly ?WardrobeActivationService $activation = null,
    ) {}

    /**
     * @param array{name?:mixed,category?:mixed,size?:mixed,notes?:mixed} $overrides
     * @return array{item:WardrobeItem,idempotent:bool}
     */
    public function promote(User $actor, int $draftId, array $overrides): array
    {
        try {
            $result = $this->attempt($actor->getId(), $draftId, $overrides);
        } catch (UniqueConstraintViolationException) {
            $this->doctrine->resetManager();

            $result = $this->attempt($actor->getId(), $draftId, $overrides);
        } catch (\Throwable $exception) {
            $this->doctrine->resetManager();
            throw $exception;
        }

        $this->activation?->firstItemAdded($actor, $result['item']->getUser(), 'batch');

        return $result;
    }

    /**
     * @param array{name?:mixed,category?:mixed,size?:mixed,notes?:mixed} $overrides
     * @return array{item:WardrobeItem,idempotent:bool}
     */
    private function attempt(?int $actorId, int $draftId, array $overrides): array
    {
        $em = $this->doctrine->getManager();
        $connection = $em->getConnection();
        $item = null;
        $temporaryPhoto = null;
        $connection->beginTransaction();

        try {
            $actor = $actorId !== null ? $em->find(User::class, $actorId) : null;
            $draft = $em->find(WardrobeItemDraft::class, $draftId, LockMode::PESSIMISTIC_WRITE);
            if (!$draft instanceof WardrobeItemDraft) {
                throw new \OutOfBoundsException('Черновик не найден');
            }
            $subject = $draft->getProfileSubject();
            if (!$actor instanceof User || !$subject instanceof User || !$this->families->canManage($actor, $subject)) {
                throw new AccessDeniedException('Нет доступа к черновику');
            }
            if ($draft->getStatus() === WardrobeItemDraft::STATUS_ACCEPTED) {
                $item = $draft->getAcceptedItem();
                if (!$item instanceof WardrobeItem) {
                    throw new \DomainException('Принятый черновик повреждён');
                }
                $connection->commit();
                return ['item' => $item, 'idempotent' => true];
            }
            if (!in_array($draft->getStatus(), [WardrobeItemDraft::STATUS_RECOGNIZED, WardrobeItemDraft::STATUS_FAILED], true)) {
                throw new \DomainException('Черновик ещё не готов к подтверждению');
            }

            $category = $this->value($overrides, 'category', $draft->getCategory(), 100);
            $name = $this->value($overrides, 'name', $draft->getName(), 255);
            $size = $this->value($overrides, 'size', $draft->getSize(), 50);
            $notes = $this->value($overrides, 'notes', $draft->getNotes(), 4000);
            if ($category === null || $name === null) {
                throw new \DomainException('Заполните категорию и название');
            }

            // Сериализует выдачу itemNo для одного владельца между web workers.
            $em->lock($subject, LockMode::PESSIMISTIC_WRITE);
            $wardrobes = $em->getRepository(Wardrobe::class);
            $wardrobe = $wardrobes->findOneBy(['owner' => $subject, 'isDefault' => true]);
            if (!$wardrobe instanceof Wardrobe) {
                $wardrobe = (new Wardrobe())->setOwner($subject);
                $em->persist($wardrobe);
            }
            $items = $em->getRepository(WardrobeItem::class);
            $item = (new WardrobeItem())
                ->setCategory($category)
                ->setName($name)
                ->setSize($size)
                ->setNotes($notes)
                ->setSource(WardrobeItem::SOURCE_IMPORT)
                ->setUser($subject)
                ->setWardrobe($wardrobe)
                ->setOriginalOwner($subject)
                ->setItemNo($items->nextItemNo($subject));

            $path = $draft->getPhoto() !== null ? $this->storage->resolvePath($draft, 'photoFile') : null;
            if (is_string($path) && is_file($path)) {
                $temporaryPhoto = tempnam(sys_get_temp_dir(), 'wardrobe_ingest_');
                if ($temporaryPhoto === false || !copy($path, $temporaryPhoto)) {
                    throw new \RuntimeException('Не удалось подготовить фотографию');
                }
                $mime = MimeTypes::getDefault()->guessMimeType($temporaryPhoto) ?? 'image/jpeg';
                $item->setPhotoFile(new UploadedFile($temporaryPhoto, basename($path), $mime, null, true));
            }

            $em->persist($item);
            $em->flush();
            $draft->accept($item);
            $em->flush();
            $connection->commit();

            return ['item' => $item, 'idempotent' => false];
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($item instanceof WardrobeItem && $item->getPhoto() !== null) {
                $uploadedPath = $this->storage->resolvePath($item, 'photoFile');
                if (is_string($uploadedPath) && is_file($uploadedPath)) {
                    @unlink($uploadedPath);
                }
            }
            if (is_string($temporaryPhoto) && is_file($temporaryPhoto)) {
                @unlink($temporaryPhoto);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $overrides */
    private function value(array $overrides, string $field, ?string $fallback, int $maxLength): ?string
    {
        $raw = $overrides[$field] ?? $fallback;
        if ($raw !== null && !is_string($raw)) {
            throw new \InvalidArgumentException('Некорректное поле '.$field);
        }
        $value = trim((string) $raw);
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException('Поле '.$field.' слишком длинное');
        }

        return $value === '' ? null : $value;
    }
}
