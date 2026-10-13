<?php

declare(strict_types=1);

// Infection runs this in place of vendor/bin/phpunit (infection.json5, phpUnit.customPath).
// With --only-covering-test-cases, Infection passes a mutant's covering tests as one --filter
// alternation. A line covered by several hundred tests yields a pattern past PCRE's compiled-size
// limit; PHPUnit then matches nothing, prints "No tests executed!" and exits 0, and Infection
// records the mutant as escaped without running a test. A filter that does not compile is widened
// instead: to the covering test methods, else to their classes, else to the whole suite. Every
// step still selects each covering test, so no mutant runs fewer tests than cover it.

namespace Hushscript\Scripts\InfectionPhpunit;

function compiles(string $pattern): bool
{
    return @preg_match($pattern, '') !== false;
}

/**
 * Splits Infection's `/A::m|B::n with data set "k"/` filter (each test preg_quote'd) into class
 * short names and methods; null when the filter has another shape.
 *
 * @return non-empty-array<string, array<string, true>>|null
 */
function coveringMethods(string $filter): ?array
{
    if (strlen($filter) < 3 || $filter[0] !== '/' || $filter[-1] !== '/') {
        return null;
    }
    $body = substr($filter, 1, -1);
    $tests = [];
    $current = '';
    $length = strlen($body);
    for ($i = 0; $i < $length; $i++) {
        if ($body[$i] === '\\') {
            if (++$i === $length) {
                return null;
            }
            $current .= $body[$i];
        } elseif ($body[$i] === '|') {
            $tests[] = $current;
            $current = '';
        } else {
            $current .= $body[$i];
        }
    }
    $tests[] = $current;
    $identifier = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';
    $methods = [];
    foreach ($tests as $test) {
        $parts = explode('::', $test, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $method = explode(' with data set ', $parts[1], 2)[0];
        if (preg_match($identifier, $parts[0]) !== 1 || preg_match($identifier, $method) !== 1) {
            return null;
        }
        $methods[$parts[0]][$method] = true;
    }

    return $methods;
}

/**
 * @param list<string> $args
 *
 * @return list<string>
 */
function widen(array $args): array
{
    $at = array_search('--filter', $args, true);
    if (! is_int($at) || ! isset($args[$at + 1]) || compiles($args[$at + 1])) {
        return $args;
    }
    $methods = coveringMethods($args[$at + 1]);
    if ($methods !== null) {
        $byMethod = [];
        foreach ($methods as $class => $names) {
            $byMethod[] = $class.'::(?:'.implode('|', array_keys($names)).')';
        }
        $candidates = [
            'covering methods' => '/(?:'.implode('|', $byMethod).')\b/',
            'covering classes' => '/(?:'.implode('|', array_keys($methods)).')::/',
        ];
        foreach ($candidates as $scope => $pattern) {
            if (compiles($pattern)) {
                fwrite(STDERR, "infection-phpunit: the covering-test filter does not compile; running its {$scope}\n");
                $args[$at + 1] = $pattern;

                return $args;
            }
        }
    }
    fwrite(STDERR, "infection-phpunit: the covering-test filter does not compile; running the whole suite\n");
    array_splice($args, $at, 2);

    return $args;
}

// PHPUnit reads its arguments from $_SERVER['argv']; anything unexpected there reaches it unchanged.
$command = $_SERVER['argv'] ?? null;
if (is_array($command) && array_is_list($command) && $command !== [] && array_filter($command, 'is_string') === $command) {
    /** @var non-empty-list<string> $command */
    $_SERVER['argv'] = $GLOBALS['argv'] = [$command[0], ...widen(array_slice($command, 1))];
    $_SERVER['argc'] = $GLOBALS['argc'] = count($_SERVER['argv']);
}

require dirname(__DIR__).'/vendor/bin/phpunit';
