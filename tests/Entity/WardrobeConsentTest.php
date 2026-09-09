<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\WardrobeConsent;
use PHPUnit\Framework\TestCase;

final class WardrobeConsentTest extends TestCase
{
    public function testPersonalizationCanBeRevokedIndependently(): void
    {
        $user = new User();
        $consent = new WardrobeConsent($user, $user);
        $consent->grantPhotoProcessing($user);
        $consent->grantPersonalization($user);

        $consent->revokePersonalization();

        self::assertFalse($consent->isPersonalizationGranted());
        self::assertTrue($consent->isPhotoProcessingGranted());
    }

    public function testNewGrantDoesNotReviveScopesFromGlobalRevocation(): void
    {
        $user = new User();
        $consent = new WardrobeConsent($user, $user);
        $consent->grantPhotoProcessing($user);
        $consent->revoke();

        $consent->grantPersonalization($user);

        self::assertTrue($consent->isPersonalizationGranted());
        self::assertFalse($consent->isPhotoProcessingGranted());
    }
}
