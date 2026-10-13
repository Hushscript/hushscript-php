<?php

declare(strict_types=1);

$path = $argv[1] ?? '';
$floor = (float) ($argv[2] ?? '80');
if (! is_file($path) || ! is_readable($path)) {
    fwrite(STDERR, "cannot read the mutation report at {$path}\n");
    exit(1);
}
$report = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$stats = is_array($report) ? ($report['stats'] ?? null) : null;
if (! is_array($stats)) {
    fwrite(STDERR, "the mutation report has no statistics\n");
    exit(1);
}
$killed = $stats['killedCount'] ?? null;
$total = $stats['totalMutantsCount'] ?? null;
if (! is_int($killed) || ! is_int($total) || $total <= 0 || $killed < 0 || $killed > $total) {
    fwrite(STDERR, "the mutation report has invalid counts\n");
    exit(1);
}
// Infection files a mutant run that executed no test ("No tests executed!") as escaped. Such a run says
// nothing about the suite and means the covering-test selection broke, so the whole report is untrusted.
$unrun = 0;
foreach ($report as $key => $mutants) {
    if ($key === 'stats' || ! is_array($mutants)) {
        continue;
    }
    foreach ($mutants as $mutant) {
        $output = is_array($mutant) ? ($mutant['processOutput'] ?? null) : null;
        if (is_string($output) && preg_match('/No tests executed!/i', $output) === 1) {
            $unrun++;
        }
    }
}
if ($unrun > 0) {
    fwrite(STDERR, "{$unrun} mutants ran no test (\"No tests executed!\"); the covering-test selection is broken\n");
    exit(1);
}
// Infection's MSI also credits errors and timeouts. Only test kills satisfy this gate.
$score = $killed / $total * 100;
printf("test-killed mutation score %.2f%% (%d of %d), floor %.0f%%\n", $score, $killed, $total, $floor);
exit($score + 1e-9 >= $floor ? 0 : 1);
