<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController;
use Nevinny\AdminCoreBundle\Enum\Statuses;

class ArticleCrudController extends DefaultCrudController
{
    public static function getEntityFqcn(): string
    {
        return Article::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setDefaultSort(['publishedAt' => 'DESC'])
            ->setEntityLabelInSingular('Статья')
            ->setEntityLabelInPlural('Статьи блога');
    }

    public function configureActions(Actions $actions): Actions
    {
        return AbstractCrudController::configureActions($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title', 'Заголовок');
        yield TextField::new('slug', 'Слаг')
            ->setHelp('URL статьи: /ru/blog/&lt;слаг&gt;. Только строчные латинские буквы, цифры, дефисы.');
        yield ChoiceField::new('locale', 'Локаль')
            ->setChoices(['Русский' => 'ru', 'English' => 'en']);
        yield AssociationField::new('author', 'Автор')
            ->setHelp('Байлайн + Person schema (E-E-A-T)');
        yield ChoiceField::new('status', 'Статус')
            ->setChoices(Statuses::choices())
            ->onlyOnForms();
        yield DateTimeField::new('publishedAt', 'Дата публикации')
            ->setHelp('Пусто или будущее время — статья не видна на сайте');
        yield TextareaField::new('excerpt', 'Анонс')
            ->setHelp('Короткое описание: список статей + meta description')
            ->hideOnIndex();
        // Не TextEditorField: Trix вырезает таблицы и часть HTML при сохранении
        yield TextareaField::new('content', 'Текст статьи (HTML)')
            ->setNumOfRows(30)
            ->hideOnIndex();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('title')
            ->add('status')
            ->add('locale')
            ;
    }
}
