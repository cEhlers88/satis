#!/usr/bin/env php
<?php
/**
 * Entfernt include/*.json-Dateien aus dem Build-Output, die von packages.json
 * nicht mehr referenziert werden. Nötig, weil der Workflow den alten
 * gh-pages-Stand nach public/ restauriert und Satis alte Include-Dateien
 * nicht selbst aufräumt.
 *
 * dist/-Archive werden bewusst NICHT angetastet: composer.lock-Dateien können
 * weiterhin auf alte Zips zeigen.
 *
 * Aufruf: php scripts/cleanup-orphaned-includes.php <output-dir>
 */

$outputDir = rtrim($argv[1] ?? 'public', '/');
$packagesFile = $outputDir . '/packages.json';

$packages = json_decode((string) @file_get_contents($packagesFile), true);
if (!is_array($packages)) {
    fwrite(STDERR, "Konnte {$packagesFile} nicht lesen – nichts aufzuräumen.\n");
    exit(1);
}

$referenced = array_keys($packages['includes'] ?? []);
$removed = 0;

foreach (glob($outputDir . '/include/*.json') ?: [] as $file) {
    $relative = substr($file, strlen($outputDir) + 1);
    if (!in_array($relative, $referenced, true)) {
        unlink($file);
        echo "Entfernt: {$relative}\n";
        $removed++;
    }
}

echo "{$removed} verwaiste Include-Datei(en) entfernt, " . count($referenced) . " referenziert.\n";
