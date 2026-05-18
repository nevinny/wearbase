<?php

namespace App\Controller\Admin;

use App\Entity\Brand;
use App\Entity\Main;
use App\Entity\Product;
use App\Entity\SectionType;
use App\Service\AlphabetManagerService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Nevinny\AdminCoreBundle\Service\SectionPathGenerator;
use Symfony\Component\HttpFoundation\RequestStack;

class BrandCrudController extends DefaultCrudController
//class BrandCrudController extends AbstractCrudController
{
    public function __construct(
        RequestStack $requestStack,
        EntityManagerInterface $entityManager,
        AdminUrlGenerator $adminUrlGenerator,
        SectionPathGenerator $pathGenerator,
        private AlphabetManagerService $alphabetManager
    )
    {
        parent::__construct($requestStack, $entityManager, $adminUrlGenerator, $pathGenerator);
    }

    public static function getEntityFqcn(): string
    {
        return Brand::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = AbstractCrudController::configureActions($actions);

        // добавляем кастомные NEW, если нужно
        return $actions;
    }
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $qb->orderBy('entity.created_at', 'DESC');
        return $qb;
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        $this->alphabetManager->handleBrandCreation($entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $changes = $unitOfWork->getEntityChangeSet($entityInstance);

        // Получаем старые значения
        $oldFirstLetter = isset($changes['firstLetter'])
            ? $changes['firstLetter'][0]
            : $entityInstance->getFirstLetter();

        $oldStatus = isset($changes['status'])
            ? ($changes['status'][0] === Statuses::Active->value)
            : $entityInstance->isPublished();

        parent::updateEntity($entityManager, $entityInstance);

        $this->alphabetManager->handleBrandUpdate($entityInstance, $oldFirstLetter, $oldStatus);
    }
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->alphabetManager->handleBrandDeletion($entityInstance);
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
