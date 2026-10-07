<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use Hushscript\Generated\Catalog;
use Hushscript\Generated\Hints;
use Hushscript\Generated\Scopes;
use Hushscript\Tests\Support\Conformance;
use PHPUnit\Framework\TestCase;

use function Hushscript\Scripts\emitCatalog;
use function Hushscript\Scripts\emitHints;
use function Hushscript\Scripts\emitScopes;
use function Hushscript\Scripts\generate;

require_once __DIR__.'/../scripts/generate.php';

final class GeneratedTest extends TestCase
{
    private const ROOT = __DIR__.'/..';

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        $spec = json_decode((string) file_get_contents(self::ROOT.'/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);

        /** @var array<string, mixed> $spec */
        return $spec;
    }

    public function testTheCommittedTablesAreWhatTheGeneratorEmitsFromTheCommittedSpec(): void
    {
        $spec = self::spec();

        self::assertSame(emitCatalog($spec), file_get_contents(self::ROOT.'/src/Generated/Catalog.php'));
        self::assertSame(emitScopes($spec), file_get_contents(self::ROOT.'/src/Generated/Scopes.php'));
        self::assertSame(emitHints(Conformance::json('hints.json')), file_get_contents(self::ROOT.'/src/Generated/Hints.php'));
    }

    public function testTheCatalogCarriesEveryEntryOfTheSpec(): void
    {
        $catalog = self::spec()['x-error-catalog'];
        self::assertIsArray($catalog);

        self::assertCount(count($catalog), Catalog::ENTRIES);
        self::assertSame(['code' => 3001, 'status' => 402], array_intersect_key(Catalog::ENTRIES['insufficient_balance'], ['code' => 0, 'status' => 0]));
    }

    public function testTheScopeMapHasEveryOperationThatDeclaresAScope(): void
    {
        $declared = [];
        $paths = self::spec()['paths'];
        self::assertIsArray($paths);
        foreach ($paths as $path => $operations) {
            self::assertIsArray($operations);
            foreach ($operations as $method => $operation) {
                if (is_array($operation) && isset($operation['x-required-scope'])) {
                    $declared[] = strtoupper((string) $method).' '.$path;
                }
            }
        }
        sort($declared);
        $mapped = array_keys(Scopes::OPERATIONS);
        sort($mapped);

        self::assertSame($declared, $mapped);
        self::assertSame('account:read', Scopes::OPERATIONS['GET /v1/account']);
    }

    public function testEveryHintSlugOfSpec93IsPresent(): void
    {
        $slugs = ['unauthorized', 'pat_scope_missing', 'insufficient_balance', 'rate_limited', 'too_many_active_jobs', 'agent_purchase_capped', 'agent_pack_locked', 'pat_purchase_requires_app', 'pat_purchase_requires_authentication', 'no_default_card', 'unsupported_media', 'duration_unreadable', 'duration_out_of_range', 'size_out_of_range', 'daily_limit', 'policy_version_stale', 'wrong_state', 'resume_file_mismatch', 'resume_file_required', 'resume_job_missing', 'stream_size_mismatch', 'default_card_required'];
        $present = array_keys(Hints::TABLE);
        sort($slugs);
        sort($present);

        self::assertSame($slugs, $present);
    }

    public function testOpenapiIsTheSameInEveryRepo(): void
    {
        self::assertStringEndsWith("\n", (string) file_get_contents(self::ROOT.'/openapi.json'));
    }

    public function testGenerateWritesTheSpecByteForByteAndIsIdempotent(): void
    {
        $root = sys_get_temp_dir().'/hs-gen-'.bin2hex(random_bytes(4));
        mkdir($root.'/src/Generated', 0o777, true);
        copy(self::ROOT.'/conformance-ref', $root.'/conformance-ref');
        $source = self::ROOT.'/openapi.json';
        putenv('CONFORMANCE_DIR='.Conformance::dir());

        try {
            generate($root, $source);
            $first = [];
            foreach (['openapi.json', 'src/Generated/Catalog.php', 'src/Generated/Scopes.php', 'src/Generated/Hints.php'] as $file) {
                $first[$file] = file_get_contents($root.'/'.$file);
            }
            generate($root, $source);
            foreach ($first as $file => $content) {
                self::assertSame($content, file_get_contents($root.'/'.$file), $file.' changed on the second run');
            }
            self::assertSame(file_get_contents($source), $first['openapi.json']);
            self::assertSame(file_get_contents(self::ROOT.'/src/Generated/Catalog.php'), $first['src/Generated/Catalog.php']);
        } finally {
            putenv('CONFORMANCE_DIR='.Conformance::dir());
            foreach (['openapi.json', 'conformance-ref', 'src/Generated/Catalog.php', 'src/Generated/Scopes.php', 'src/Generated/Hints.php'] as $file) {
                @unlink($root.'/'.$file);
            }
            @rmdir($root.'/src/Generated');
            @rmdir($root.'/src');
            @rmdir($root);
        }
    }

    public function testGenerateAddsATrailingNewlineToASpecWithoutOne(): void
    {
        $root = sys_get_temp_dir().'/hs-gen-'.bin2hex(random_bytes(4));
        mkdir($root.'/src/Generated', 0o777, true);
        $source = $root.'/spec.json';
        file_put_contents($source, rtrim((string) file_get_contents(self::ROOT.'/openapi.json')));
        putenv('CONFORMANCE_DIR='.Conformance::dir());

        try {
            generate($root, $source);
            self::assertStringEndsWith("}\n", (string) file_get_contents($root.'/openapi.json'));
        } finally {
            foreach (['openapi.json', 'spec.json', 'src/Generated/Catalog.php', 'src/Generated/Scopes.php', 'src/Generated/Hints.php'] as $file) {
                @unlink($root.'/'.$file);
            }
            @rmdir($root.'/src/Generated');
            @rmdir($root.'/src');
            @rmdir($root);
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function badInputs(): iterable
    {
        yield 'catalog missing' => ['emitCatalog', ['paths' => []]];
        yield 'catalog duplicate slug' => ['emitCatalog', ['x-error-catalog' => [['slug' => 'a', 'code' => 1, 'status' => 400, 'meaning' => 'm'], ['slug' => 'a', 'code' => 2, 'status' => 400, 'meaning' => 'm']]]];
        yield 'catalog string code' => ['emitCatalog', ['x-error-catalog' => [['slug' => 'a', 'code' => '1', 'status' => 400, 'meaning' => 'm']]]];
        yield 'catalog empty meaning' => ['emitCatalog', ['x-error-catalog' => [['slug' => 'a', 'code' => 1, 'status' => 400, 'meaning' => '']]]];
        yield 'catalog not an object' => ['emitCatalog', 'spec'];
        yield 'scopes without paths' => ['emitScopes', ['x' => 1]];
        yield 'scope not a string' => ['emitScopes', ['paths' => ['/v1/a' => ['get' => ['x-required-scope' => 5]]]]];
        yield 'hints empty' => ['emitHints', []];
        yield 'hint empty text' => ['emitHints', ['a' => '']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badInputs')]
    public function testTheGeneratorRejectsBadInput(string $function, mixed $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        match ($function) {
            'emitCatalog' => emitCatalog($input),
            'emitScopes' => emitScopes($input),
            default => emitHints($input),
        };
    }
}
