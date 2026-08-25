<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\NewsItem;
use App\Enum\NewsItemStatus;
use App\Repository\NewsItemRepository;
use App\Service\News\NewsSlugger;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController;
use Nevinny\AdminCoreBundle\Service\SectionPathGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Модерация новостей (первые 2 недели — только ручная публикация,
 * NEWS_AUTO_PUBLISH=0 по умолчанию). Publish/Reject — кастомные crud-действия.
 */
final class NewsItemCrudController extends DefaultCrudController
{
    public function __construct(
        RequestStack $requestStack,
        EntityManagerInterface $entityManager,
        AdminUrlGenerator $adminUrlGenerator,
        SectionPathGenerator $pathGenerator,
        private readonly NewsItemRepository $items,
        private readonly NewsSlugger $slugger,
    ) {
        parent::__construct($requestStack, $entityManager, $adminUrlGenerator, $pathGenerator);
    }

    public static function getEntityFqcn(): string
    {
        return NewsItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setDefaultSort(['id' => 'DESC'])
            ->setEntityLabelInSingular('Новость')
            ->setEntityLabelInPlural('Новости (модерация)')
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $publish = Action::new('publish', 'Опубликовать', 'fa fa-check')
            ->linkToCrudAction('publishAction')
            ->addCssClass('btn btn-success btn-sm')
            ->displayIf(static fn (NewsItem $item) => $item->getStatus() === NewsItemStatus::Ready);
        $reject = Action::new('reject', 'Отклонить', 'fa fa-ban')
            ->linkToCrudAction('rejectAction')
            ->addCssClass('btn btn-danger btn-sm')
            ->displayIf(static fn (NewsItem $item) => in_array($item->getStatus(), [NewsItemStatus::Ready, NewsItemStatus::Rewritten], true));

        return parent::configureActions($actions)
            ->add(Action::INDEX, $publish)
            ->add(Action::INDEX, $reject)
            ->add(Action::DETAIL, $publish)
            ->add(Action::DETAIL, $reject);
    }

    /** Ручная публикация админом разрешена всегда (независимо от NEWS_AUTO_PUBLISH). */
    public function publishAction(AdminContext $context): RedirectResponse
    {
        /** @var NewsItem|null $item */
        $item = $context->getEntity()->getInstance();
        if ($item !== null && $item->getStatus() === NewsItemStatus::Ready) {
            if ($item->getSlug() === null) {
                $this->assignUniqueSlug($item);
            }
            $item->setStatus(NewsItemStatus::Published);
            $this->entityManager->flush();
        }

        return $this->redirectBack($context);
    }

    public function rejectAction(AdminContext $context): RedirectResponse
    {
        /** @var NewsItem|null $item */
        $item = $context->getEntity()->getInstance();
        if ($item !== null && in_array($item->getStatus(), [NewsItemStatus::Ready, NewsItemStatus::Rewritten], true)) {
            $item->setStatus(NewsItemStatus::Rejected)->setRejectReason('manual_moderation');
            $this->entityManager->flush();
        }

        return $this->redirectBack($context);
    }

    private function redirectBack(AdminContext $context): RedirectResponse
    {
        $url = $context->getReferrer()
            ?? $this->getAdminUrlGenerator()->setController(self::class)->setAction(Action::INDEX)->generateUrl();

        return new RedirectResponse($url);
    }

    private function assignUniqueSlug(NewsItem $item): void
    {
        $base = $this->slugger->slugify($item->getRewrittenTitle() ?? $item->getTitle());
        $slug = $base;
        $i = 2;
        while ($this->items->findOneBy(['slug' => $slug]) !== null) {
            $slug = $base . '-' . $i++;
        }
        $item->setSlug($slug);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('sourceName', 'Издание')->onlyOnIndex();
        yield TextField::new('title', 'Заголовок из фида')
            ->hideOnIndex()
            ->setFormTypeOption('disabled', true);
        yield TextField::new('rewrittenTitle', 'Наш заголовок');
        yield TextareaField::new('rewrittenBody', 'Наша заметка')
            ->hideOnIndex()
            ->setNumOfRows(18);
        yield TextField::new('slug', 'Слаг')
            ->hideWhenCreating()
            ->setHelp('Публичный URL /news/&lt;слаг&gt;; проставляется при ready/publish');
        yield ChoiceField::new('status', 'Статус')
            ->setChoices(array_combine(
                array_map(static fn ($c) => $c->value, NewsItemStatus::cases()),
                array_map(static fn ($c) => $c->value, NewsItemStatus::cases()),
            ))
            ->setFormTypeOption('disabled', true);
        yield TextField::new('rubric', 'Рубрика')->onlyOnIndex();
        yield NumberField::new('shingleScore', 'Шинглы')
            ->hideOnIndex()
            ->setNumOfDecimals(4)
            ->setHelp('Доля совпадений 5-грамм с исходником; гейт ≤0.10');
        yield TextField::new('rejectReason', 'Причина отказа')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield DateTimeField::new('readyAt', 'Готово')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield DateTimeField::new('createdAt', 'Создано')->onlyOnIndex()->setFormTypeOption('disabled', true);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('status')
            ->add('rubric')
            ->add('sourceName');
    }
}
