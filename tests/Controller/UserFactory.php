<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;

/**
 * Builds in-memory User stubs for use with KernelBrowser::loginUser().
 *
 * These objects are never persisted — they only need to satisfy
 * UserInterface and carry the right roles so Symfony's security layer
 * will recognise them as authenticated during the request.
 */
final class UserFactory
{
    /**
     * A regular customer with ROLE_USER (via ROLE_CUSTOMER hierarchy).
     */
    public static function makeCustomer(): User
    {
        $user = new User();
        $user->setEmail('customer@test.local');
        $user->setRoles(['ROLE_CUSTOMER']);
        $user->setPassword('hashed-password-stub');

        return $user;
    }

    /**
     * A brand manager with ROLE_BRAND_MANAGER.
     * Also has ROLE_USER via the role_hierarchy.
     */
    public static function makeBrandManager(): User
    {
        $user = new User();
        $user->setEmail('brand@test.local');
        $user->setRoles(['ROLE_BRAND_MANAGER']);
        $user->setPassword('hashed-password-stub');

        return $user;
    }

    /**
     * A brand owner with ROLE_BRAND_OWNER.
     * Inherits ROLE_BRAND_MANAGER and ROLE_USER via hierarchy.
     */
    public static function makeBrandOwner(): User
    {
        $user = new User();
        $user->setEmail('owner@test.local');
        $user->setRoles(['ROLE_BRAND_OWNER']);
        $user->setPassword('hashed-password-stub');

        return $user;
    }
}
