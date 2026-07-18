<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SocialChannel;
use App\Service\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Каналы автопостинга. Токен вводится в открытом виде (write-only) и шифруется
 * при сохранении (SecretCipher) — в БД хранится только token_enc.
 */
class SocialChannelCrudController extends AbstractCrudController
{
    public function __construct(private readonly SecretCipher $cipher)
    {
    }

    public static function getEntityFqcn(): string
    {
        return SocialChannel::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Канал соцсети')
            ->setEntityLabelInPlural('Соцсети: каналы')
            ->setDefaultSort(['platform' => 'ASC', 'name' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE); // soft: выключить через «enabled»
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield ChoiceField::new('platform', 'Площадка')
            ->setChoices([
                'Telegram' => SocialChannel::PLATFORM_TG,
                'VK'       => SocialChannel::PLATFORM_VK,
                'Instagram' => SocialChannel::PLATFORM_IG,
            ])->setColumns(3);
        yield TextField::new('name', 'Название')->setColumns(5);
        yield BooleanField::new('enabled', 'Включён');
        yield TextField::new('target', 'Target')
            ->setHelp('TG — @handle/chat_id; VK — owner_id сообщества (отриц.); IG — Instagram Business Account id')
            ->setColumns(6);
        yield ChoiceField::new('egressHost', 'Egress-хост')
            ->setChoices(['Mac (TG/IG)' => SocialChannel::HOST_MAC, 'Prod (VK)' => SocialChannel::HOST_PROD])
            ->setColumns(3);
        yield TextField::new('plainToken', 'Токен (ввод)')
            ->setFormTypeOption('required', false)
            ->onlyOnForms()
            ->setHelp('Вводится один раз, хранится зашифрованным. Пусто при редактировании — токен не меняется.');
        yield BooleanField::new('hasToken', 'Токен задан')->hideOnForm()
            ->renderAsSwitch(false);
        yield DateField::new('launchDate', 'Старт рампа')->hideOnIndex()->setColumns(3)
            ->setFormTypeOption('required', false);
        yield IntegerField::new('rateStart', 'Старт/день')->hideOnIndex()->setColumns(3)
            ->setFormTypeOption('required', false);
        yield IntegerField::new('rateCap', 'Потолок/день')->hideOnIndex()->setColumns(3)
            ->setFormTypeOption('required', false);
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->applyToken($entityInstance);
        parent::persistEntity($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->applyToken($entityInstance);
        parent::updateEntity($em, $entityInstance);
    }

    private function applyToken(SocialChannel $channel): void
    {
        $plain = $channel->getPlainToken();
        if ($plain !== null && trim($plain) !== '') {
            $channel->setTokenEnc($this->cipher->encrypt(trim($plain)));
            $channel->setPlainToken(null);
        }
    }
}
