<?php

declare(strict_types=1);

/*
 * Fails when the line coverage in a PHPUnit clover report is under the floor.
 *
 *     php scripts/check-coverage.php /tmp/clover.xml 90
 *
 * pcov measures lines only, so the spec 13.2 branch floor (85) cannot be enforced here.
 */

$path = $argv[1] ?? '';
$floor = (float) ($argv[2] ?? '90');
$xml = simplexml_load_string((string) file_get_contents($path));
if ($xml === false) {
    fwrite(STDERR, "cannot read the clover report at {$path}\n");
    exit(1);
}
$totals = $xml->xpath('/coverage/project/metrics');
$metrics = is_array($totals) && isset($totals[0]) ? $totals[0] : null;
if ($metrics === null) {
    fwrite(STDERR, "the clover report has no project metrics\n");
    exit(1);
}
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
if ($statements === 0) {
    fwrite(STDERR, "the clover report has zero statements\n");
    exit(1);
}
$percent = $covered / $statements * 100;
printf("line coverage %.2f%% (%d of %d), floor %.0f%%\n", $percent, $covered, $statements, $floor);
exit($percent + 1e-9 >= $floor ? 0 : 1);
