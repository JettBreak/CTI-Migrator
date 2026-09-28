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

    /**
     * Undoes migrate() for a rollback: the link XML with <ACCTNO> back on $restoredAccountNo and the
     * <OLDACCTNO> tag migrate() wrote removed. Null when the XML is no longer exactly what migrate()
     * left (the link changed since), so it must not be touched.
     *
     * Before this tool, core's links carried no <OLDACCTNO>, so removing it restores them exactly.
     */
    public static function revert(string $xml, string $currentAccountNo, string $restoredAccountNo): ?string
    {
        $oldTag = '<OLDACCTNO>'.$restoredAccountNo.'</>';
        if (1 !== substr_count($xml, self::accountTag($currentAccountNo)) || 1 !== substr_count($xml, $oldTag)) {
            return null;
        }

        return str_replace([self::accountTag($currentAccountNo), $oldTag], [self::accountTag($restoredAccountNo), ''], $xml);
    }
}
