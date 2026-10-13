<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;

require dirname(__DIR__).'/vendor/autoload.php';

$path = $argv[1] ?? '';
$lineFloor = (float) ($argv[2] ?? '90');
$branchFloor = (float) ($argv[3] ?? '85');
if (! is_file($path) || ! is_readable($path)) {
    fwrite(STDERR, "cannot read the PHP coverage report at {$path}\n");
    exit(1);
}
$coverage = require $path;
if (! $coverage instanceof CodeCoverage || ! $coverage->collectsBranchAndPathCoverage()) {
    fwrite(STDERR, "the coverage report has no branch coverage data\n");
    exit(1);
}
$report = $coverage->getReport();
$passed = true;
foreach ([
    'line' => [$report->numberOfExecutedLines(), $report->numberOfExecutableLines(), $lineFloor],
    'branch' => [$report->numberOfExecutedBranches(), $report->numberOfExecutableBranches(), $branchFloor],
] as $kind => [$covered, $total, $floor]) {
    if ($total === 0) {
        fwrite(STDERR, "the coverage report has zero executable {$kind}s\n");
        exit(1);
    }
    $percent = $covered / $total * 100;
    printf("%s coverage %.2f%% (%d of %d), floor %.0f%%\n", $kind, $percent, $covered, $total, $floor);
    $passed = $passed && $percent + 1e-9 >= $floor;
}
exit($passed ? 0 : 1);
