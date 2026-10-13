<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use Hushscript\Transcribe\StreamView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StreamViewTest extends TestCase
{
    private const BYTES = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ+/';

    /** @return resource */
    private static function stream()
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, self::BYTES);

        return $stream;
    }

    public function testTheViewStartsAtTheStreamStartAndReportsTheDeclaredSizeAsReadOnly(): void
    {
        $stream = self::stream();
        fseek($stream, 10);
        $view = StreamView::of($stream, 40);
        self::assertIsResource($view);

        self::assertSame(0, ftell($view));
        self::assertSame('0123', fread($view, 4));
        $stat = fstat($view);
        self::assertIsArray($stat);
        self::assertSame(40, $stat['size']);
        self::assertSame(0100444, $stat['mode']);
        fclose($view);
        self::assertIsResource($stream, 'closing the view leaves the stream open');
    }

    public function testTheViewEndsWhereTheStreamEnds(): void
    {
        $view = StreamView::of(self::stream(), 64);
        self::assertIsResource($view);

        self::assertSame('0123', fread($view, 4));
        self::assertFalse(feof($view));
        self::assertSame(substr(self::BYTES, 4), stream_get_contents($view));
        self::assertTrue(feof($view));
    }

    public function testASeekFromTheEndCountsFromTheDeclaredSize(): void
    {
        $view = StreamView::of(self::stream(), 40);
        self::assertIsResource($view);

        self::assertSame(0, fseek($view, -10, SEEK_END));
        self::assertSame(30, ftell($view));
        self::assertSame('uv', fread($view, 2));
        self::assertSame(0, fseek($view, 5));
        self::assertSame('56', fread($view, 2));
    }

    public function testAWindowThatIsNotOpenReadsNothingAndIsAtItsEnd(): void
    {
        $view = new StreamView();

        self::assertFalse($view->stream_read(10));
        self::assertTrue($view->stream_eof());
        self::assertFalse($view->stream_seek(0, SEEK_SET));
        self::assertSame(0, $view->stream_tell());
    }

    public function testAnOpenWindowReadsAtLeastOneByteAndNothingOnceClosed(): void
    {
        $view = new StreamView();
        $view->context = stream_context_create(['hushscript-view' => ['stream' => self::stream(), 'size' => 64]]);
        $opened = null;

        self::assertTrue($view->stream_open('hushscript-view://stream', 'rb', 0, $opened));
        self::assertFalse($view->stream_eof());
        self::assertSame('0', $view->stream_read(1));
        self::assertSame('1', $view->stream_read(0));
        self::assertSame(2, $view->stream_tell());
        self::assertSame(substr(self::BYTES, 2), $view->stream_read(100));
        self::assertSame('', $view->stream_read(100));
        self::assertTrue($view->stream_eof());
        $view->stream_close();
        self::assertFalse($view->stream_read(1));
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function unusableContexts(): iterable
    {
        yield 'not a stream' => ['stream', 64];
        yield 'size not a whole number' => [null, '64'];
        yield 'neither' => ['stream', '64'];
    }

    #[DataProvider('unusableContexts')]
    public function testAWindowOpenedWithoutAStreamAndASizeFails(mixed $stream, mixed $size): void
    {
        $view = new StreamView();
        $view->context = stream_context_create(['hushscript-view' => ['stream' => $stream ?? self::stream(), 'size' => $size]]);
        $opened = null;

        self::assertFalse($view->stream_open('hushscript-view://stream', 'rb', 0, $opened));
        self::assertFalse($view->stream_read(1));
    }
}
