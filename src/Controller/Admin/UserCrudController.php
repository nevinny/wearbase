<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Nevinny\AdminCoreBundle\Entity\User;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Администраторы (firewall `admin`, сущность из nevinny/admin-core).
 *
 * Перекрывает вендорный {@see \Nevinny\AdminCoreBundle\Controller\Admin\UserCrudController},
 * у которого нет configureFields(): EasyAdmin генерил поля из метаданных Doctrine и выводил
 * колонку `password` обычным текстовым полем — в форме редактирования стоял bcrypt-хеш, а
 * сохранение писало введённое значение в БД КАК ЕСТЬ, без хеширования. То есть сменить
 * пароль через админку было нельзя, а попытка это сделать ломала вход пользователю
 * (провайдер сравнивает bcrypt-хеш с тем, что лежит в колонке).
 *
 * Здесь пароль — write-only поле: хеш не показывается никогда, пустое поле при
 * редактировании означает «не менять», непустое хешируется в addPasswordHasher().
 * Тот же приём, что для секретов в {@see SocialChannelCrudController}.
 *
 * Контроллер привязан к пункту меню явно (DashboardController), иначе EasyAdmin
 * резолвит сущность на вендорный CRUD.
 */
class UserCrudController extends AbstractCrudController
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Администратор')
            ->setEntityLabelInPlural('Администраторы')
            ->setDefaultSort(['id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email', 'Email');
        yield TextField::new('password', 'Новый пароль')
            ->onlyOnForms()
            ->setRequired($pageName === Crud::PAGE_NEW)
            ->setFormType(PasswordType::class)
            ->setFormTypeOptions([
                'mapped'   => false,
                'required' => $pageName === Crud::PAGE_NEW,
                'attr'     => ['autocomplete' => 'new-password'],
            ])
            ->setHelp($pageName === Crud::PAGE_NEW
                ? 'Задайте пароль — он будет сохранён в виде хеша.'
                : 'Пусто — пароль не меняется. Сохранённый хеш не показывается.');
        yield BooleanField::new('isVerified', 'Подтверждён');
        yield TextField::new('firstName', 'Имя');
        yield TextField::new('lastName', 'Фамилия');
        yield TextField::new('phone', 'Телефон');
        yield TextareaField::new('address', 'Адрес')->hideOnIndex();
    }

    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->addPasswordHasher(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->addPasswordHasher(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    /**
     * Поле unmapped — Doctrine само его не запишет, хеш кладёт только этот листенер
     * и только когда поле заполнили. Пустая строка = пароль остаётся прежним.
     */
    private function addPasswordHasher(FormBuilderInterface $builder): FormBuilderInterface
    {
        return $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $plain = $event->getForm()->get('password')->getData();
            if (!is_string($plain) || trim($plain) === '') {
                return;
            }

            $user = $event->getData();
            if ($user instanceof User) {
                $user->setPassword($this->hasher->hashPassword($user, trim($plain)));
            }
        });
    }
}
