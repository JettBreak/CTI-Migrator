<?php

namespace App\Core;

/**
 * The tag format core uses in prlinkxx.xml1, e.g. "<ACCTNO>123</><ACCT>SA</>".
 */
final class LinkXml
{
    public static function accountTag(string $accountNo): string
    {
        return '<ACCTNO>'.$accountNo.'</>';
    }

    public static function namesAccount(string $xml, string $accountNo): bool
    {
        return str_contains($xml, self::accountTag($accountNo)) && str_contains($xml, '<ACCT>');
    }

    /**
     * The link XML after renaming the account: <ACCTNO> carries the new number and
     * <OLDACCTNO> records the number it replaced (overwriting any earlier one).
     */
    public static function migrate(string $xml, string $oldAccountNo, string $newAccountNo): string
    {
        $xml = str_replace(self::accountTag($oldAccountNo), self::accountTag($newAccountNo), $xml);
        $oldTag = '<OLDACCTNO>'.$oldAccountNo.'</>';
        $replaced = preg_replace_callback('#<OLDACCTNO>[^<]*</>#', static fn () => $oldTag, $xml, 1, $count);

        return $count > 0 ? $replaced : $xml.$oldTag;
    }
}
