<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

/** A read filter that runs a callback for every chunk the stream hands up, to make a producer slow on a fake clock. */
final class SlowRead extends \php_user_filter
{
    public const NAME = 'hushscript.slowread';

    /** @var (\Closure(): void)|null */
    public static ?\Closure $onChunk = null;

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     */
    #[\Override]
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while (($bucket = stream_bucket_make_writeable($in)) !== null) {
            $consumed = (int) $consumed + (int) $bucket->datalen;
            if (self::$onChunk !== null) {
                (self::$onChunk)();
            }
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }

    /**
     * @param  resource  $stream
     * @param  \Closure(): void  $onChunk
     */
    public static function attach($stream, \Closure $onChunk): void
    {
        if (! in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }
        self::$onChunk = $onChunk;
        stream_filter_append($stream, self::NAME, STREAM_FILTER_READ);
    }
}
