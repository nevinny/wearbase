<?php

declare(strict_types=1);

namespace App\Controller\Cart;

use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\ProductVariant;
use App\Repository\CartRepository;
use App\Repository\ProductVariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cart', name: 'cart_')]
class CartController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(CartRepository $cartRepo, Request $request): Response
    {
        $cart = $this->getOrCreateCart($cartRepo, $request);

        return $this->render('cart/index.html.twig', [
            'cart'   => $cart,
            'groups' => $cart->groupByBrand(),
        ]);
    }

    #[Route('/count', name: 'count')]
    public function count(CartRepository $cartRepo, Request $request): JsonResponse
    {
        $cart = $this->getOrCreateCart($cartRepo, $request);
        return $this->json(['count' => $cart->getItemsCount()]);
    }

    #[Route('/add/{variantId}', name: 'add', methods: ['POST'])]
    public function add(
        int $variantId,
        Request $request,
        CartRepository $cartRepo,
        ProductVariantRepository $variantRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!$this->isCartTokenValid($request)) {
            return $this->json(['error' => 'Недействительный токен'], 400);
        }

        $variant = $variantRepo->find($variantId);
        if (!$variant || !$variant->isInStock()) {
            return $this->json(['error' => 'Товар недоступен'], 400);
        }

        $cart = $this->getOrCreateCart($cartRepo, $request, $em);
        $qty  = max(1, min((int) $request->request->get('qty', 1), $variant->getStockQty()));

        $item = $cart->findItemByVariant($variant);
        if ($item) {
            $item->setQty($item->getQty() + $qty);
        } else {
            $item = new CartItem();
            $item->setVariant($variant);
            $item->setQty($qty);
            $cart->addItem($item);
            $em->persist($item);
        }

        $em->flush();

        return $this->json([
            'count' => $cart->getItemsCount(),
            'total' => $cart->getTotal(),
        ]);
    }

    #[Route('/update/{itemId}', name: 'update', methods: ['POST'])]
    public function update(
        int $itemId,
        Request $request,
        CartRepository $cartRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!$this->isCartTokenValid($request)) {
            return $this->json(['error' => 'Недействительный токен'], 400);
        }

        $cart = $this->getOrCreateCart($cartRepo, $request);
        $qty  = (int) $request->request->get('qty', 1);

        foreach ($cart->getItems() as $item) {
            if ($item->getId() === $itemId) {
                if ($qty <= 0) {
                    $em->remove($item);
                } else {
                    $variant = $item->getVariant();
                    $maxQty = $variant?->getStockQty() ?? $qty;
                    $item->setQty(min($qty, $maxQty));
                }
                break;
            }
        }

        $em->flush();

        return $this->json([
            'count' => $cart->getItemsCount(),
            'total' => $cart->getTotal(),
        ]);
    }

    #[Route('/remove/{itemId}', name: 'remove', methods: ['POST'])]
    public function remove(
        int $itemId,
        Request $request,
        CartRepository $cartRepo,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCartTokenValid($request)) {
            if ($request->isXmlHttpRequest()) {
                return $this->json(['error' => 'Недействительный токен'], 400);
            }
            $this->addFlash('error', 'Недействительный токен');
            return $this->redirectToRoute('cart_index');
        }

        $cart = $this->getOrCreateCart($cartRepo, $request);

        foreach ($cart->getItems() as $item) {
            if ($item->getId() === $itemId) {
                $em->remove($item);
                break;
            }
        }

        $em->flush();

        if ($request->isXmlHttpRequest()) {
            return $this->json(['count' => $cart->getItemsCount()]);
        }

        return $this->redirectToRoute('cart_index');
    }

    // ── Helpers ───────────────────────────────────────────────

    private function isCartTokenValid(Request $request): bool
    {
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token');

        return $this->isCsrfTokenValid('cart', (string) $token);
    }

    /** Ключ гостевой корзины в ДАННЫХ сессии: переживает session->migrate() при логине (session ID — нет) */
    public const SESSION_TOKEN_KEY = 'cart_token';

    private function getOrCreateCart(
        CartRepository $repo,
        Request $request,
        ?EntityManagerInterface $em = null,
    ): Cart {
        $user = $this->getUser();

        if ($user) {
            $cart = $repo->findOneBy(['user' => $user]);
            if (!$cart && $em) {
                $cart = new Cart();
                $cart->setUser($user);
                $em->persist($cart);
            }
            return $cart ?? new Cart();
        }

        // Гостевая корзина по токену из данных сессии
        $session = $request->getSession();
        $token   = $session->get(self::SESSION_TOKEN_KEY);
        $cart    = $token ? $repo->findOneBy(['sessionId' => $token, 'user' => null]) : null;

        // Легаси-совместимость: корзины, созданные до токенов, привязаны к PHP session ID
        if (!$cart && !$token) {
            $cart = $repo->findOneBy(['sessionId' => $session->getId(), 'user' => null]);
            if ($cart) {
                $token = bin2hex(random_bytes(16));
                $session->set(self::SESSION_TOKEN_KEY, $token);
                $cart->setSessionId($token);
                $em?->flush();
            }
        }

        if (!$cart && $em) {
            if (!$token) {
                $token = bin2hex(random_bytes(16));
                $session->set(self::SESSION_TOKEN_KEY, $token);
            }
            $cart = new Cart();
            $cart->setSessionId($token);
            $em->persist($cart);
        }

        return $cart ?? new Cart();
    }
}
