<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdvisorIdea;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Бэклог идей советника (advisor_idea). Человек-гейт: статус редактируется руками
 * (approved/rejected), остальные поля — read-only, приходят из RAG/DecisionMaker.
 */
class AdvisorIdeaCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AdvisorIdea::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Идея')
            ->setEntityLabelInPlural('Советник: идеи')
            ->setDefaultSort(['iceScore' => 'DESC'])
            ->setSearchFields(['title', 'hypothesis', 'sourceSignal']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Идеи создаёт советник (LLM), физический DELETE из админки запрещён правилом проекта.
        return $actions->disable(Action::NEW, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('status')
            ->add('actionClass')
            ->add('needsHuman');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield ChoiceField::new('status', 'Статус')
            ->setChoices([
                'Предложена' => AdvisorIdea::STATUS_PROPOSED,
                'Одобрена' => AdvisorIdea::STATUS_APPROVED,
                'Отклонена' => AdvisorIdea::STATUS_REJECTED,
                'В работе' => AdvisorIdea::STATUS_IN_PROGRESS,
                'Задеплоена' => AdvisorIdea::STATUS_SHIPPED,
                'Замеряется' => AdvisorIdea::STATUS_MEASURING,
                'Подтверждена' => AdvisorIdea::STATUS_VALIDATED,
                'Откачена' => AdvisorIdea::STATUS_REVERTED,
            ])
            ->renderAsBadges([
                AdvisorIdea::STATUS_PROPOSED => 'secondary',
                AdvisorIdea::STATUS_APPROVED => 'success',
                AdvisorIdea::STATUS_REJECTED => 'danger',
                AdvisorIdea::STATUS_IN_PROGRESS => 'info',
                AdvisorIdea::STATUS_SHIPPED => 'primary',
                AdvisorIdea::STATUS_MEASURING => 'warning',
                AdvisorIdea::STATUS_VALIDATED => 'success',
                AdvisorIdea::STATUS_REVERTED => 'danger',
            ])
            ->setHelp('Человек-гейт: approved/rejected проставляется руками');
        yield TextField::new('actionClass', 'Класс')
            ->setHelp('a=контент(авто), b=код(брокер), c=человек')
            ->hideOnForm();
        yield IntegerField::new('iceScore', 'ICE')->hideOnForm();
        yield BooleanField::new('needsHuman', 'Нужен человек')->renderAsSwitch(false)->hideOnForm();
        yield TextField::new('title', 'Заголовок')->hideOnForm();
        yield TextField::new('sourceSignal', 'Источник сигнала')->hideOnIndex()->hideOnForm();
        yield DateTimeField::new('createdAt', 'Создана')->hideOnForm();

        yield TextareaField::new('hypothesis', 'Гипотеза')->hideOnIndex()->hideOnForm()->setNumOfRows(4);
        yield IntegerField::new('impact', 'Impact')->onlyOnDetail();
        yield IntegerField::new('confidence', 'Confidence')->onlyOnDetail();
        yield IntegerField::new('ease', 'Ease')->onlyOnDetail();
        yield TextareaField::new('ragCitations', 'RAG-цитаты')->onlyOnDetail()
            ->formatValue(static fn ($value) => \is_array($value) ? implode(', ', $value) : $value);
        yield TextareaField::new('rejectedReason', 'Причина отклонения')->hideOnIndex()
            ->setNumOfRows(3)
            ->setHelp('Заполняется человеком при статусе rejected');
        yield DateTimeField::new('updatedAt', 'Обновлена')->onlyOnDetail();
    }
}
