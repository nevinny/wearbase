<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CityHub;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Orm\EntityRepository;
use Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController;

class CityHubCrudController extends DefaultCrudController
{
    public static function getEntityFqcn(): string
    {
        return CityHub::class;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return $this->container->get(EntityRepository::class)->createQueryBuilder($searchDto, $entityDto, $fields, $filters);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('SEO-контент города')
            ->setEntityLabelInPlural('SEO города (хабы)')
            ->setDefaultSort(['title' => 'ASC'])
            ->setSearchFields(['slug', 'title']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('slug', 'Слаг')
            ->setColumns(4)
            ->setHelp('Как в URL /cities/{slug} (из CitySlugger), напр. moskva');
        yield TextField::new('title', 'Город')->setColumns(4);
        yield BooleanField::new('isActive', 'Активен');
        yield TextField::new('h1', 'H1')->onlyOnForms()->setColumns(8);
        yield TextField::new('metaTitle', 'Meta title')->onlyOnForms()->setColumns(8)
            ->setHelp('≤ 60 символов; пусто → формульный заголовок');
        yield TextareaField::new('metaDescription', 'Meta description')->onlyOnForms()->setNumOfRows(2)
            ->setHelp('≤ 160 символов; пусто → формульное описание');
        yield TextareaField::new('intro', 'Вводный текст (HTML)')->onlyOnForms()->setNumOfRows(6)
            ->setHelp('HTML, рендерится как есть. Пусто → формульный абзац со счётчиком брендов');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('isActive');
    }
}
