<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ImageDownloaderService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ImageDownloaderServiceTest extends TestCase
{
    public function testDownloadReturnsNullOnInvalidUrl(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 404]),
        ]);

        $service = new ImageDownloaderService(
            $httpClient,
            new NullLogger(),
            sys_get_temp_dir(),
        );

        $result = $service->download('https://invalid.example/nonexistent.jpg');
        $this->assertNull($result);
    }
}
