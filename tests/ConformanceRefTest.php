<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use PHPUnit\Framework\TestCase;

final class ConformanceRefTest extends TestCase
{
    private const ROOT = __DIR__.'/..';

    // The conformance ref is pinned in one file; nothing else may restate it.
    public function testTheConformanceRefIsPinnedInOneFileOnly(): void
    {
        $raw = (string) file_get_contents(self::ROOT.'/conformance-ref');

        self::assertMatchesRegularExpression('/^v\d+\.\d+\.\d+\n$/', $raw);
        $tag = trim($raw);
        foreach (['compose.yaml', 'justfile', 'scripts/conformance-dir.sh', 'scripts/generate.php', '.github/workflows/ci.yml'] as $name) {
            if (! is_file(self::ROOT.'/'.$name)) {
                continue;
            }
            $content = (string) file_get_contents(self::ROOT.'/'.$name);
            self::assertStringNotContainsString($tag, $content, "{$name} restates the conformance tag; read conformance-ref instead");
            self::assertSame(0, preg_match('/#v\d+\.\d+\.\d+/', $content), "{$name} pins a conformance tag");
        }
    }
}
