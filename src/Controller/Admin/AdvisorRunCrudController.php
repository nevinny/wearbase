<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdvisorRun;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * История тиков советника (advisor_run) — лог, только просмотр (докстрока
 * AdvisorRun: аудит входов/дайджеста/решений).
 */
class AdvisorRunCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AdvisorRun::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Дайджест')
            ->setEntityLabelInPlural('Советник: дайджесты')
            ->setDefaultSort(['ranAt' => 'DESC'])
            ->setSearchFields(['mode', 'digestText']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Лог: только чтение, руками не создаём и не удаляем.
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('mode');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield DateTimeField::new('ranAt', 'Запущен');
        yield TextField::new('mode', 'Режим');
        yield TextareaField::new('digestText', 'Дайджест')
            ->formatValue(static function ($value) {
                if (!\is_string($value)) {
                    return $value;
                }

                return mb_strlen($value) > 200 ? mb_substr($value, 0, 200) . '…' : $value;
            })
            ->onlyOnIndex();
        yield TextareaField::new('digestText', 'Дайджест (полный текст)')
            ->onlyOnDetail()
            ->setNumOfRows(15);
        yield TextareaField::new('inputsSummary', 'Сводка входов')->onlyOnDetail()->setNumOfRows(8);
        yield TextareaField::new('decisions', 'Решения')->onlyOnDetail()
            ->formatValue(static fn ($value) => \is_array($value) ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $value);
        yield DateTimeField::new('createdAt', 'Создан')->onlyOnDetail();
    }
}
