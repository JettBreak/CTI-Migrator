<?php

namespace App\Tests\Command;

use App\Command\InitDatabaseCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InitDatabaseCommandTest extends KernelTestCase
{
    public function testCommandIsRegistered(): void
    {
        $application = new Application(self::bootKernel());

        self::assertTrue($application->has('app:database:init'));
    }

    public function testRefusesToUseACoreDatabaseAsTheAppDatabase(): void
    {
        $core = ['host' => '10.22.70.88', 'port' => 3306, 'dbname' => 'coreapp_fusion'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_DATABASE_URL points at the core database "coreapp_fusion"');
        InitDatabaseCommand::assertSeparateFromCore(['host' => '10.22.70.88', 'dbname' => 'CoreApp_Fusion'], [$core]);
    }

    public function testAcceptsASeparateSchemaOnTheSameServer(): void
    {
        $core = ['host' => '10.22.70.88', 'port' => 3306, 'dbname' => 'coreapp_fusion'];

        InitDatabaseCommand::assertSeparateFromCore(['host' => '10.22.70.88', 'port' => 3306, 'dbname' => 'data_migration'], [$core]);
        $this->addToAssertionCount(1);
    }
}
