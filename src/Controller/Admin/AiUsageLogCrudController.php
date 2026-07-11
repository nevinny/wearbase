<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AiUsageLog;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * ai_usage_log — журнал стоимости AI-запросов (токены + $) по пользователю/фиче.
 * Только чтение: append-only лог, руками не создаём и не редактируем.
 */
class AiUsageLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AiUsageLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Запрос AI')
            ->setEntityLabelInPlural('Учёт AI-запросов')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['feature', 'model']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('feature')
            ->add('createdAt');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield DateTimeField::new('createdAt', 'Когда');
        yield AssociationField::new('user', 'Пользователь')
            ->setHelp('Пусто — системный/пайплайн-вызов, не привязан к пользователю фронта');
        yield TextField::new('feature', 'Фича');
        yield TextField::new('model', 'Модель');
        yield NumberField::new('promptTokens', 'Prompt tokens');
        yield NumberField::new('completionTokens', 'Completion tokens');
        yield NumberField::new('costUsd', 'Стоимость, $')
            ->setNumDecimals(6);
    }
}
