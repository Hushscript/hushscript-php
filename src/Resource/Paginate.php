<?php

declare(strict_types=1);

namespace Hushscript\Resource;

use Hushscript\Exception\HushscriptException;
use Hushscript\Page;

/** @internal */
final class Paginate
{
    private function __construct()
    {
    }

    /**
     * Walks a cursor-paged list one page at a time, yielding every item in order. The walk is lazy:
     * closing the generator (or leaving a `foreach` over it) sends no further request. A next cursor of
     * null or an empty string ends the walk; one equal to the cursor just sent would repeat forever, so it
     * raises `pagination_loop` once the page's items have been yielded.
     *
     * @template T
     *
     * @param  \Closure(?string): Page<T>  $fetch
     * @return \Generator<int, T>
     */
    public static function walk(\Closure $fetch): \Generator
    {
        $cursor = null;
        while (true) {
            $sent = $cursor;
            $page = $fetch($sent);
            foreach ($page->items as $item) {
                yield $item;
            }
            $cursor = $page->nextCursor;
            if ($cursor === null || $cursor === '') {
                return;
            }
            self::refuseRepeat($sent, $cursor);
        }
    }

    /**
     * A next cursor equal to the non-null cursor just sent would repeat the same page forever.
     *
     * @throws HushscriptException
     */
    public static function refuseRepeat(?string $sent, ?string $next): void
    {
        if ($sent !== null && $next === $sent) {
            throw new HushscriptException('The API returned the same page cursor it was sent; stopping instead of repeating it.', 'pagination_loop');
        }
    }
}
