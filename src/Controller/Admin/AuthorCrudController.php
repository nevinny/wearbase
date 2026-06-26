<?php

namespace App\Controller\Admin;

use App\Entity\Author;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Nevinny\AdminCoreBundle\Controller\Admin\DefaultCrudController;
use Nevinny\AdminCoreBundle\Enum\Statuses;

class AuthorCrudController extends DefaultCrudController
{
    public static function getEntityFqcn(): string
    {
        return Author::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setDefaultSort(['name' => 'ASC'])
            ->setEntityLabelInSingular('Автор')
            ->setEntityLabelInPlural('Авторы');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield ImageField::new('photoSm', 'Фото')
            ->setBasePath('/images/authors')
            ->onlyOnIndex();
        yield TextField::new('name', 'Имя');
        yield TextField::new('jobTitle', 'Должность/роль');
        yield TextField::new('slug', 'Слаг')
            ->setHelp('URL: /ru/author/&lt;слаг&gt;. Только строчные латинские буквы, цифры, дефисы.');
        yield ChoiceField::new('status', 'Статус')
            ->setChoices(Statuses::choices())
            ->onlyOnForms();
        yield TextField::new('photo', 'Файл фото (полное)')
            ->setHelp('Имя файла в /public_html/images/authors/ (права 644). Напр. anna-semyannikova.jpg')
            ->onlyOnForms();
        yield TextField::new('photoSm', 'Файл фото (аватар, лёгкий)')
            ->setHelp('Лёгкая версия для байлайна. Напр. anna-semyannikova-sm.jpg')
            ->onlyOnForms();
        yield TextField::new('instagramUrl', 'Instagram (sameAs)')->hideOnIndex();
        yield TextField::new('schoolName', 'Учебное заведение (alumniOf)')->hideOnIndex();
        yield TextField::new('schoolUrl', 'Ссылка на учебное заведение')->onlyOnForms();
        yield TextareaField::new('bio', 'Био (HTML)')
            ->setNumOfRows(6)
            ->setHelp('Выводится на странице автора (рендерится как HTML)')
            ->hideOnIndex();
    }
}
