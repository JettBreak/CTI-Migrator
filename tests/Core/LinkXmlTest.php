<?php

namespace App\Tests\Core;

use App\Core\LinkXml;
use PHPUnit\Framework\TestCase;

final class LinkXmlTest extends TestCase
{
    public function testMigrateSwapsTheAccountAndRecordsTheOldNumber(): void
    {
        self::assertSame(
            '<ACCTNO>999</><ACCTTYPE>SAVINGS ACCOUNT</><ACCT>SA</><PRIMARY>Y</><OLDACCTNO>123</>',
            LinkXml::migrate('<ACCTNO>123</><ACCTTYPE>SAVINGS ACCOUNT</><ACCT>SA</><PRIMARY>Y</>', '123', '999'),
        );
    }

    public function testMigratingAgainOverwritesThePreviousOldNumber(): void
    {
        self::assertSame(
            '<ACCTNO>777</><ACCT>SA</><OLDACCTNO>999</>',
            LinkXml::migrate('<ACCTNO>999</><ACCT>SA</><OLDACCTNO>123</>', '999', '777'),
        );
    }

    public function testReplacementValuesAreTakenLiterally(): void
    {
        self::assertSame('<ACCTNO>$1\\0</><ACCT>SA</><OLDACCTNO>1</>', LinkXml::migrate('<ACCTNO>1</><ACCT>SA</><OLDACCTNO>x</>', '1', '$1\\0'));
    }

    public function testNamesAccountNeedsTheExactTag(): void
    {
        self::assertTrue(LinkXml::namesAccount('<ACCTNO>123</><ACCT>SA</>', '123'));
        self::assertFalse(LinkXml::namesAccount('<ACCTNO>1234</><ACCT>SA</>', '123'));
        self::assertFalse(LinkXml::namesAccount('<ACCTNO>123</>', '123'));
    }
}
