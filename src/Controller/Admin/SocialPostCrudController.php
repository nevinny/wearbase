<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SocialPost;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Очередь постов: просмотр статусов, ручной разбор held (Reels/UGC/QA-fail) и
 * кнопка «approve» (held → scheduled). NEW/DELETE отключены (посты из планировщика; soft-only).
 */
class SocialPostCrudController extends AbstractCrudController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public static function getEntityFqcn(): string
    {
        return SocialPost::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Пост')
            ->setEntityLabelInPlural('Соцсети: посты')
            ->setDefaultSort(['scheduledAt' => 'DESC'])
            ->setSearchFields(['rubric', 'caption', 'status']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('status')
            ->add('rubric')
            ->add('channel');
    }

    public function configureActions(Actions $actions): Actions
    {
        $approve = Action::new('approve', 'Одобрить', 'fa fa-check')
            ->linkToCrudAction('approve')
            ->displayIf(static fn (SocialPost $p): bool => $p->getStatus() === SocialPost::STATUS_HELD);

        return $actions
            ->add(Crud::PAGE_INDEX, $approve)
            ->add(Crud::PAGE_DETAIL, $approve)
            ->disable(Action::NEW, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('channel', 'Канал');
        yield TextField::new('rubric', 'Рубрика');
        yield TextField::new('status', 'Статус');
        yield AssociationField::new('brand', 'Бренд')->hideOnIndex();
        yield TextareaField::new('caption', 'Подпись')->hideOnIndex();
        yield TextField::new('mediaType', 'Медиа')->hideOnIndex();
        yield BooleanField::new('aiGenerated', 'ИИ')->renderAsSwitch(false);
        yield DateTimeField::new('scheduledAt', 'Запланирован');
        yield DateTimeField::new('publishedAt', 'Опубликован')->hideOnForm();
        yield TextField::new('lastError', 'Ошибка/причина')->hideOnIndex()->hideOnForm();
    }

    /** held → scheduled (ручное одобрение); если время не задано — публикуем ближайшим тиком. */
    public function approve(AdminContext $context): RedirectResponse
    {
        /** @var SocialPost $post */
        $post = $context->getEntity()->getInstance();

        if ($post->getStatus() === SocialPost::STATUS_HELD && trim((string) $post->getCaption()) !== '') {
            $post->setStatus(SocialPost::STATUS_SCHEDULED)->setLastError(null);
            if ($post->getScheduledAt() === null) {
                $post->setScheduledAt(new \DateTime());
            }
            $this->em->flush();
            $this->addFlash('success', 'Пост одобрен и поставлен в очередь публикации.');
        } else {
            $this->addFlash('warning', 'Нельзя одобрить: пост не в held или нет подписи.');
        }

        return $this->redirect($context->getReferrer() ?? '/admin');
    }
}
