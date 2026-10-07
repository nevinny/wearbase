<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Brand;
use App\Repository\BrandRepository;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Пришёл ли аноним из флоу «Я владелец бренда»: /brand-claim/{id} (IsGranted ROLE_USER)
 * отправил его на /login и Symfony сохранил URL в сессии (firewall main). Такому человеку
 * нужен обычный аккаунт — не новый бренд (docs/brand_duplicates.md, инцидент iseymordc).
 */
class ClaimFlowDetector
{
    public function __construct(private readonly BrandRepository $brands) {}

    /** id бренда из target_path сессии или null, если это не флоу заявки. */
    public function claimBrandId(Request $request): ?int
    {
        if (!$request->hasSession()) {
            return null;
        }
        $target = $request->getSession()->get('_security.main.target_path');
        if (!is_string($target)) {
            return null;
        }
        $path = (string) parse_url($target, PHP_URL_PATH);

        return preg_match('#^/brand-claim/(\d+)(?:/|$)#', $path, $m) === 1 ? (int) $m[1] : null;
    }

    /** Живой (не удалённый и не склеенный) бренд заявки — для текста на странице логина. */
    public function claimBrand(Request $request): ?Brand
    {
        $id = $this->claimBrandId($request);
        $brand = $id === null ? null : $this->brands->find($id);

        return $brand !== null && $brand->getStatus() !== Statuses::Deleted && !$brand->isMerged() ? $brand : null;
    }
}
