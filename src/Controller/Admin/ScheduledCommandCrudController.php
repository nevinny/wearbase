<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ScheduledCommand;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ScheduledCommandCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ScheduledCommand::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Задача крона')
            ->setEntityLabelInPlural('Крон (расписание)')
            ->setDefaultSort(['environment' => 'ASC', 'name' => 'ASC'])
            ->setSearchFields(['name', 'command', 'schedule']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('environment')
            ->add('enabled');
    }

    public function configureActions(Actions $actions): Actions
    {
        // Физический DELETE из админки запрещён правилом проекта — деактивируем через «enabled».
        return $actions->disable(Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield ChoiceField::new('environment', 'Окружение')
            ->setChoices(array_flip(ScheduledCommand::ENVIRONMENTS))
            ->setHelp('Запустится только там, где CRON_ENV совпадает с этим значением')
            ->setColumns(3);
        yield TextField::new('name', 'Название')->setColumns(6);
        yield BooleanField::new('enabled', 'Включена');
        yield TextField::new('command', 'Команда')
            ->setHelp('Как в терминале, без `php bin/console`: <code>app:gsc:sync --no-debug</code>')
            ->setColumns(6);
        yield TextField::new('schedule', 'Расписание (cron)')
            ->setHelp('5 полей: мин час день месяц день_недели. Напр. <code>17 9 * * *</code> — ежедневно в 09:17')
            ->setColumns(3);
        yield IntegerField::new('timeoutSec', 'Таймаут, сек')
            ->setHelp('Убить, если выполняется дольше')
            ->setColumns(3)
            ->hideOnIndex();

        yield DateTimeField::new('lastRunAt', 'Последний запуск')->hideOnForm();
        yield IntegerField::new('lastExitCode', 'Код выхода')->hideOnForm()
            ->setHelp('0 — успех');
        yield IntegerField::new('lastDurationSec', 'Длит., сек')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('nextRunAt', 'Следующий запуск')->hideOnForm()->hideOnIndex();
        yield CodeEditorField::new('lastOutput', 'Хвост вывода')->hideOnForm()->hideOnIndex();
    }
}
