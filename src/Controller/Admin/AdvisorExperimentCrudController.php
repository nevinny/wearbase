<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdvisorExperiment;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Исполнение идей советника (advisor_experiment) — идея → ветка → деплой → вердикт.
 * Read-only: стадию/вердикт ведёт воркер/брокер (Фаза B), не человек из админки.
 */
class AdvisorExperimentCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AdvisorExperiment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Эксперимент')
            ->setEntityLabelInPlural('Советник: эксперименты')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['branch', 'commitSha', 'stage']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('stage')
            ->add('actionClass')
            ->add('verdict');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('idea', 'Идея');
        yield TextField::new('stage', 'Стадия');
        yield TextField::new('actionClass', 'Класс');
        yield TextField::new('verdict', 'Вердикт');
        yield DateTimeField::new('deployedAt', 'Задеплоен');

        yield TextField::new('branch', 'Ветка')->onlyOnDetail();
        yield TextField::new('commitSha', 'Commit SHA')->onlyOnDetail();
        yield TextField::new('testStatus', 'Тесты')->onlyOnDetail();
        yield TextareaField::new('testReport', 'Отчёт тестов')->onlyOnDetail()->setNumOfRows(6);
        yield TextareaField::new('gateReport', 'Гейты')->onlyOnDetail()
            ->formatValue(static fn ($value) => \is_array($value) ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $value);
        yield TextField::new('prUrl', 'PR')->onlyOnDetail();
        yield TextareaField::new('failureNote', 'Причина сбоя')->onlyOnDetail()->setNumOfRows(4);
        yield TextareaField::new('learning', 'Вывод')->onlyOnDetail()->setNumOfRows(4);
        yield TextField::new('approvedBy', 'Одобрил')->onlyOnDetail();
        yield DateTimeField::new('approvedAt', 'Одобрен')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Создан')->onlyOnDetail();
    }
}
