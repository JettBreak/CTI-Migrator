<?php

namespace App\Tests\Command;

use App\Entity\UserAuditEntry;
use App\Security\ConsoleCommandAuthorization;
use App\Security\SuperUserSetup;
use App\Tests\AppTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

/** Runs commands through the console application, so ConsoleAuthentication is in the way as it is on the server. */
final class ConsoleAuthenticationTest extends AppTestCase
{
    /** Matches APP_SUPERUSER_PASSWORD_HASH in .env.test. */
    private const SUPERUSER_PASSWORD = 'Super-user-pass-1';

    public function testACommandRunsAfterTheSuperUserSignsIn(): void
    {
        $tester = $this->runConsole('about', ['superuser', self::SUPERUSER_PASSWORD]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Coreware authentication', $tester->getErrorOutput());
        self::assertStringContainsString('Symfony', $tester->getDisplay());
        self::assertContains(UserAuditEntry::CONSOLE_AUTHENTICATED, $this->auditActions());
    }

    public function testThreeWrongAttemptsStopTheCommand(): void
    {
        $tester = $this->runConsole('about', ['superuser', 'wrong', 'superuser', 'wrong', 'someone', self::SUPERUSER_PASSWORD]);

        self::assertSame(ConsoleCommandEvent::RETURN_CODE_DISABLED, $tester->getStatusCode());
        self::assertSame(3, substr_count($tester->getErrorOutput(), 'Invalid super user name or password.'));
        self::assertStringNotContainsString('Symfony', $tester->getDisplay());
        self::assertContains(UserAuditEntry::SUPERUSER_FAILED, $this->auditActions());
    }

    public function testACommandIsRefusedWithoutATerminal(): void
    {
        $tester = $this->runConsole('about', [], interactive: false);

        self::assertSame(ConsoleCommandEvent::RETURN_CODE_DISABLED, $tester->getStatusCode());
        self::assertStringContainsString('needs Coreware authentication', $tester->getErrorOutput());
    }

    public function testUnattendedCommandsDoNotAsk(): void
    {
        $tester = $this->runConsole('list', [], interactive: false);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('Coreware authentication', $tester->getErrorOutput());
    }

    public function testInternalAuthorizationAllowsANonInteractiveChildCommand(): void
    {
        $authorization = static::getContainer()->get(ConsoleCommandAuthorization::class);
        $authorization->beginInternalCommands();
        try {
            $tester = $this->runConsole('about', [], interactive: false);
        } finally {
            $authorization->endInternalCommands();
        }

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('needs Coreware authentication', $tester->getErrorOutput());
    }

    public function testFirstUseDefinesTheSuperUserInEnvLocal(): void
    {
        $dir = sys_get_temp_dir().'/superuser-setup-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/.env.local', "APP_SECRET=abc\nAPP_SUPERUSER_USERNAME=\nAPP_SUPERUSER_PASSWORD_HASH=\n");
        try {
            $input = new ArrayInput([]);
            $input->setStream(self::inputs(['coreware.super', 'short', 'New-super-pass-1', 'New-super-pass-1']));
            $output = new BufferedOutput();

            $username = (new SuperUserSetup($dir))->run(new SymfonyStyle($input, $output));

            self::assertSame('coreware.super', $username);
            self::assertStringContainsString('Use 12 to 128 characters.', $output->fetch());
            $env = (string) file_get_contents($dir.'/.env.local');
            self::assertStringContainsString('APP_SECRET=abc', $env);
            self::assertSame(1, substr_count($env, 'APP_SUPERUSER_USERNAME='));
            self::assertStringContainsString("APP_SUPERUSER_USERNAME=coreware.super\n", $env);
            self::assertMatchesRegularExpression("/APP_SUPERUSER_PASSWORD_HASH='([^']+)'/", $env);
            preg_match("/APP_SUPERUSER_PASSWORD_HASH='([^']+)'/", $env, $hash);
            self::assertTrue((new NativePasswordHasher())->verify($hash[1], 'New-super-pass-1'));
        } finally {
            @unlink($dir.'/.env.local');
            @rmdir($dir);
        }
    }

    /** @param list<string> $inputs */
    private function runConsole(string $command, array $inputs, bool $interactive = true): ApplicationTester
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->setInputs($inputs);
        $tester->run(['command' => $command], ['interactive' => $interactive, 'capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * @param list<string> $inputs
     *
     * @return resource
     */
    private static function inputs(array $inputs)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, implode(\PHP_EOL, $inputs).\PHP_EOL);
        rewind($stream);

        return $stream;
    }

    /** @return list<string> */
    private function auditActions(): array
    {
        return array_map(static fn (UserAuditEntry $e) => $e->getAction(), $this->em()->getRepository(UserAuditEntry::class)->findBy(['target' => 'system']));
    }
}
