<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\AdminAccess;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig-функция is_platform_admin() — true для оператора-админа (main ROLE_ADMIN
 * ИЛИ залогиненная admincore-сессия). Для админ-кнопок на публичных страницах.
 */
final class AdminAccessExtension extends AbstractExtension
{
    public function __construct(private readonly AdminAccess $adminAccess)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_platform_admin', $this->adminAccess->isAdmin(...)),
        ];
    }
}
