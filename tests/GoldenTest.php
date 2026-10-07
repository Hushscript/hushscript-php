<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Conformance\Golden;
use Hushscript\Conformance\Goldens;
use Hushscript\Tests\Support\Conformance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../conformance/goldens.php';

/** Every golden of the conformance suite, byte for byte (spec 6.2). */
final class GoldenTest extends TestCase
{
    /** @return iterable<string, array{Golden}> */
    public static function goldens(): iterable
    {
        foreach (Goldens::all() as $golden) {
            yield $golden->file => [$golden];
        }
    }

    #[DataProvider('goldens')]
    public function testTheRenderingIsByteIdenticalToTheGolden(Golden $golden): void
    {
        $dir = Conformance::dir();

        self::assertSame(Goldens::expected($dir, $golden), $golden->render(Goldens::transcript($dir, $golden->transcript)));
    }

    public function testTheTableIsTheWholeDirectoryAndNothingElse(): void
    {
        $scenario = Conformance::json('scenarios/format-goldens.json');
        $expect = $scenario['expect'] ?? null;
        self::assertIsArray($expect);
        $result = $expect['result'] ?? null;
        self::assertIsArray($result);

        self::assertSame($result['checked'], count(Goldens::all()), 'the scenario counts the same goldens as the table');
        self::assertSame(['missing' => [], 'extra' => []], Goldens::drift(Conformance::dir()), 'a golden that is missing, renamed or added fails here');
    }
}
