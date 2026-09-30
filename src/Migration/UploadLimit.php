<?php

namespace App\Migration;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The largest mapping file an upload can carry: app.batch.upload_max_size, or less when the web
 * server's PHP (upload_max_filesize, post_max_size) accepts less. A request over post_max_size
 * reaches the app with no file and no form data, so the page checks this before sending one.
 */
final class UploadLimit
{
    public function __construct(
        #[Autowire('%app.batch.upload_max_size%')] private readonly string $configured,
    ) {
    }

    public function bytes(): int
    {
        return min(self::toBytes($this->configured), (int) UploadedFile::getMaxFilesize());
    }

    /** E.g. "200 MB", "8 MB". */
    public function label(): string
    {
        $bytes = $this->bytes();
        foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'KB' => 1 << 10] as $unit => $size) {
            if ($bytes >= $size) {
                return rtrim(rtrim(number_format($bytes / $size, 1, '.', ''), '0'), '.').' '.$unit;
            }
        }

        return $bytes.' bytes';
    }

    /** "200M", "2G", "512k" or a plain byte count, as in php.ini and the File constraint. */
    private static function toBytes(string $size): int
    {
        $size = trim($size);
        $factor = match (strtolower(substr($size, -1))) {
            'g' => 1 << 30,
            'm' => 1 << 20,
            'k' => 1 << 10,
            default => 1,
        };

        return (int) $size * $factor;
    }
}
