<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** app:advisor:ask: валидация --role отрабатывает до любого ретрива/LLM. */
class AdvisorAskCommandTest extends KernelTestCase
{
    private function ask(string $role): CommandTester
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:advisor:ask'));
        $tester->execute(['question' => 'q', '--role' => $role, '--chunks' => true]);

        return $tester;
    }

    public function testUnknownRoleRejectedAndListsContent(): void
    {
        $t = $this->ask('nope');

        self::assertSame(1, $t->getStatusCode());
        self::assertStringContainsString('Неизвестная роль', $t->getDisplay());
        self::assertStringContainsString('content', $t->getDisplay());
    }

    public function testContentRoleIsExplicitOnly(): void
    {
        self::assertNotContains('content', \App\Service\Advisor\AdvisorRag::IDEA_ROLES);
        self::assertContains('content', \App\Service\Advisor\AdvisorRag::EXPLICIT_ROLES);
    }
}
