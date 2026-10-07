<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\EventListener\SignupAttributionListener;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class IgLinkInBioTest extends WebTestCase
{
    public function testPageRendersWithUtmButtonsAndNoindex(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', 'https://localhost/ig');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('meta[name="robots"][content="noindex, follow"]');

        $hrefs = $crawler->filter('a[data-goal]')->each(fn ($a) => $a->attr('href'));
        $this->assertCount(3, $hrefs);
        $this->assertStringContainsString('/ru/wardrobe?utm_source=instagram&utm_medium=bio&utm_campaign=wardrobe', $hrefs[0]);
        $this->assertStringContainsString('utm_campaign=catalog', $hrefs[1]);
        $this->assertSame('https://t.me/wearbaseru', $hrefs[2]);
    }

    public function testVisitSetsInstagramBioAttribution(): void
    {
        $client = static::createClient();
        $client->request('GET', 'https://localhost/ig');

        $cookie = $client->getCookieJar()->get(SignupAttributionListener::COOKIE_NAME, '/', 'localhost');
        $this->assertNotNull($cookie);
        $data = json_decode((string) $cookie->getValue(), true);
        $this->assertSame('instagram', $data['utm_source']);
        $this->assertSame('bio', $data['utm_medium']);
        $this->assertSame('/ig', $data['lp']);
    }
}
