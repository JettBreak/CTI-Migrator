<?php

namespace App\Tests\Service;

use App\Service\CsvResponseFactory;
use PHPUnit\Framework\TestCase;

final class CsvResponseFactoryTest extends TestCase
{
    public function testQuotesCommasAndNeutralisesFormulas(): void
    {
        $response = (new CsvResponseFactory())->create('x.csv', ['a', 'b', 'c'], [
            ['Santos, Maria', '=HYPERLINK("http://evil")', '@SUM(1)'],
            ['-2+3', 'plain', null],
        ]);

        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        self::assertSame(
            "a,b,c\n\"Santos, Maria\",\"'=HYPERLINK(\"\"http://evil\"\")\",'@SUM(1)\n'-2+3,plain,\n",
            $csv,
        );
        self::assertStringContainsString('attachment; filename=x.csv', $response->headers->get('Content-Disposition') ?? '');
    }
}
