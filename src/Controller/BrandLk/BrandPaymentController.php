<?php

declare(strict_types=1);

namespace App\Controller\BrandLk;

use App\Entity\PaymentProvider;
use App\Entity\SellerLegalEntity;
use App\Entity\SellerPaymentAccount;
use App\Form\BrandLk\SellerLegalEntityFormType;
use App\Form\BrandLk\SellerPaymentAccountFormType;
use App\Repository\PaymentProviderRepository;
use App\Repository\SellerLegalEntityRepository;
use App\Service\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/brand/payments', name: 'brand_payment_')]
class BrandPaymentController extends BrandDashboardController
{
    /** Подсказки по реквизитам для каждого провайдера (что вводить в поля счёта). */
    private const PROVIDER_HINTS = [
        'yookassa'     => ['ref' => 'shopId', 'secret' => 'Секретный ключ', 'note' => ''],
        'tinkoff'      => ['ref' => 'TerminalKey', 'secret' => 'Password', 'note' => ''],
        'cloudpayments'=> ['ref' => 'Public ID', 'secret' => 'API Secret', 'note' => ''],
        'sber'         => ['ref' => 'Логин API (userName)', 'secret' => 'Пароль', 'note' => ''],
        'robokassa'    => ['ref' => 'MerchantLogin', 'secret' => 'JSON {"p1":"Пароль#1","p2":"Пароль#2"}', 'note' => 'Секрет — JSON с двумя паролями Robokassa'],
        'payselection' => ['ref' => 'SiteId', 'secret' => 'SecretKey', 'note' => ''],
        'paykeeper'    => ['ref' => 'Логин', 'secret' => 'Пароль', 'note' => 'Поддомен мерчанта укажите в config.base_url'],
    ];

    #[Route('', name: 'index')]
    public function index(
        SellerLegalEntityRepository $legalEntities,
        PaymentProviderRepository $providers,
        SecretCipher $cipher,
    ): Response {
        $brand = $this->getActiveBrand();

        return $this->render('brand_lk/payments/index.html.twig', [
            'brand'               => $brand,
            'legal_entities'      => $legalEntities->findBy(['brand' => $brand], ['id' => 'DESC']),
            'cipher_ready'        => $cipher->isConfigured(),
            'available_providers' => $providers->findActive(),
        ]);
    }

    #[Route('/legal-entity/new', name: 'le_new')]
    #[Route('/legal-entity/{id}/edit', name: 'le_edit')]
    public function legalEntity(?int $id, Request $request, EntityManagerInterface $em): Response
    {
        $brand = $this->getActiveBrand();

        if ($id !== null) {
            $entity = $this->ownedLegalEntity($id, $em);
        } else {
            $entity = new SellerLegalEntity();
            $entity->setBrand($brand);
        }

        $form = $this->createForm(SellerLegalEntityFormType::class, $entity);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($entity);
            $em->flush();
            $this->addFlash('success', 'Юр.лицо сохранено');
            return $this->redirectToRoute('brand_payment_index');
        }

        return $this->render('brand_lk/payments/legal_entity_form.html.twig', [
            'brand'  => $brand,
            'form'   => $form,
            'entity' => $entity,
        ]);
    }

    #[Route('/legal-entity/{id}/delete', name: 'le_delete', methods: ['POST'])]
    public function deleteLegalEntity(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $entity = $this->ownedLegalEntity($id, $em);
        if ($this->isCsrfTokenValid('le_delete_' . $id, (string) $request->request->get('_token'))) {
            $em->remove($entity);
            $em->flush();
            $this->addFlash('success', 'Юр.лицо удалено');
        }

        return $this->redirectToRoute('brand_payment_index');
    }

    #[Route('/legal-entity/{entityId}/account/new', name: 'acc_new')]
    #[Route('/account/{id}/edit', name: 'acc_edit')]
    public function account(
        ?int $entityId,
        ?int $id,
        Request $request,
        EntityManagerInterface $em,
        SecretCipher $cipher,
    ): Response {
        $brand = $this->getActiveBrand();

        if ($id !== null) {
            $account = $em->getRepository(SellerPaymentAccount::class)->find($id);
            if ($account === null || $account->getLegalEntity()?->getBrand()?->getId() !== $brand->getId()) {
                throw $this->createAccessDeniedException();
            }
            $legalEntity = $account->getLegalEntity();
        } else {
            $legalEntity = $this->ownedLegalEntity((int) $entityId, $em);
            $account = new SellerPaymentAccount();
            $account->setLegalEntity($legalEntity);

            // Преселект провайдера, если перешли по кнопке «Подключить {платёжку}»
            $code = (string) $request->query->get('provider', '');
            if ($code !== '') {
                $provider = $em->getRepository(PaymentProvider::class)->findOneBy(['code' => $code, 'isActive' => true]);
                if ($provider !== null) {
                    $account->setProvider($provider);
                }
            }
        }

        $form = $this->createForm(SellerPaymentAccountFormType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $secret = (string) $form->get('secret')->getData();
            if ($secret !== '') {
                if (!$cipher->isConfigured()) {
                    $this->addFlash('error', 'Шифрование секретов не настроено (PAYMENT_SECRET_KEY). Обратитесь в поддержку.');
                    return $this->redirectToRoute('brand_payment_index');
                }
                $account->setSecretEncrypted($cipher->encrypt($secret));
            }

            // Только один основной счёт на юр.лицо
            if ($account->isPrimary()) {
                foreach ($legalEntity->getPaymentAccounts() as $other) {
                    if ($other !== $account) {
                        $other->setIsPrimary(false);
                    }
                }
            }

            $em->persist($account);
            $em->flush();
            $this->addFlash('success', 'Счёт приёма оплаты сохранён');
            return $this->redirectToRoute('brand_payment_index');
        }

        return $this->render('brand_lk/payments/account_form.html.twig', [
            'brand'         => $brand,
            'form'          => $form,
            'account'       => $account,
            'legal_entity'  => $legalEntity,
            'provider_hints'=> self::PROVIDER_HINTS,
        ]);
    }

    #[Route('/account/{id}/delete', name: 'acc_delete', methods: ['POST'])]
    public function deleteAccount(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $brand = $this->getActiveBrand();
        $account = $em->getRepository(SellerPaymentAccount::class)->find($id);
        if ($account === null || $account->getLegalEntity()?->getBrand()?->getId() !== $brand->getId()) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('acc_delete_' . $id, (string) $request->request->get('_token'))) {
            $em->remove($account);
            $em->flush();
            $this->addFlash('success', 'Счёт удалён');
        }

        return $this->redirectToRoute('brand_payment_index');
    }

    private function ownedLegalEntity(int $id, EntityManagerInterface $em): SellerLegalEntity
    {
        $entity = $em->getRepository(SellerLegalEntity::class)->find($id);
        if ($entity === null || $entity->getBrand()?->getId() !== $this->getActiveBrand()->getId()) {
            throw $this->createAccessDeniedException();
        }

        return $entity;
    }
}
