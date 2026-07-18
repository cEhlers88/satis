#!/usr/bin/env php
<?php
/**
 * Regeneriert den Top-Level-"blacklist" in satis.json, sodass pro Paket nur
 * die neuesten N getaggten Versionen im Satis-Build landen.
 *
 * Vorgehen:
 *   - Für jedes VCS-Repository aus satis.json werden die Tags per
 *     `git ls-remote --tags` ermittelt (nicht-destruktiv, es wird nichts
 *     an den Quell-Repos verändert).
 *   - Der Paketname wird aus der composer.json des Default-Branches gelesen
 *     (Blob-loser Shallow-Clone, nur composer.json wird nachgeladen).
 *   - Tags werden mit composer/semver semantisch sortiert; alles außer den
 *     neuesten N wandert als explizite Versionsliste in den Blacklist.
 *   - Dev-Branches (dev-*) sind nie betroffen, da nur Tags betrachtet werden.
 *
 * Der Blacklist wird bei jedem Lauf komplett neu erzeugt, d. h. mit jedem
 * neuen Release rutscht die älteste Version automatisch nach.
 *
 * Aufruf: php scripts/prune-old-versions.php [satis.json] [keep=5]
 */

$autoloadCandidates = [
    __DIR__ . '/../.tooling/satis/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
foreach ($autoloadCandidates as $autoload) {
    if (file_exists($autoload)) {
        require $autoload;
        break;
    }
}
if (!class_exists(\Composer\Semver\Semver::class)) {
    fwrite(STDERR, "composer/semver nicht gefunden – erst Satis installieren (.tooling/satis).\n");
    exit(1);
}

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

$configFile = $argv[1] ?? 'satis.json';
$keep = max(1, (int) ($argv[2] ?? getenv('KEEP_VERSIONS') ?: 5));

$config = json_decode((string) file_get_contents($configFile), true);
if (!is_array($config)) {
    fwrite(STDERR, "Konnte {$configFile} nicht lesen/parsen.\n");
    exit(1);
}

function run(string $cmd, array $args): array
{
    $escaped = array_map('escapeshellarg', $args);
    exec($cmd . ' ' . implode(' ', $escaped) . ' 2>&1', $output, $exitCode);
    return [$exitCode, $output];
}

/** Alle Tag-Namen eines Remote-Repos (dereferenzierte ^{}-Einträge bevorzugt). */
function remoteTags(string $url): array
{
    [$code, $lines] = run('git ls-remote --tags', [$url]);
    if ($code !== 0) {
        fwrite(STDERR, "WARNUNG: git ls-remote fehlgeschlagen für {$url}:\n  " . implode("\n  ", $lines) . "\n");
        return [];
    }
    $tags = [];
    foreach ($lines as $line) {
        if (!preg_match('{\trefs/tags/(.+?)(\^\{\})?$}', $line, $m)) {
            continue;
        }
        $tags[$m[1]] = true;
    }
    return array_keys($tags);
}

/** Paketname aus composer.json des Default-Branches, ohne vollen Checkout. */
function packageName(string $url): ?string
{
    $tmp = sys_get_temp_dir() . '/satis-prune-' . md5($url);
    run('rm -rf', [$tmp]);
    [$code, $lines] = run('git clone --quiet --depth 1 --filter=blob:none --no-checkout', [$url, $tmp]);
    if ($code !== 0) {
        fwrite(STDERR, "WARNUNG: Clone fehlgeschlagen für {$url}:\n  " . implode("\n  ", $lines) . "\n");
        return null;
    }
    [$code, $lines] = run('git -C ' . escapeshellarg($tmp) . ' show', ['HEAD:composer.json']);
    run('rm -rf', [$tmp]);
    if ($code !== 0) {
        fwrite(STDERR, "WARNUNG: composer.json nicht lesbar in {$url}.\n");
        return null;
    }
    $composer = json_decode(implode("\n", $lines), true);
    return is_array($composer) && isset($composer['name']) ? (string) $composer['name'] : null;
}

$parser = new VersionParser();
$tagsByPackage = [];

foreach ($config['repositories'] ?? [] as $repository) {
    if (!in_array($repository['type'] ?? '', ['vcs', 'git', 'github'], true) || empty($repository['url'])) {
        continue;
    }
    $url = $repository['url'];
    $name = packageName($url);
    if ($name === null) {
        continue;
    }

    $valid = [];
    foreach (remoteTags($url) as $tag) {
        try {
            $parser->normalize($tag);
            $valid[] = $tag;
        } catch (\UnexpectedValueException $e) {
            // Kein gültiger Versions-Tag (z. B. "release-notes") – ignorieren.
        }
    }
    $tagsByPackage[$name] = array_values(array_unique(array_merge($tagsByPackage[$name] ?? [], $valid)));
}

$blacklist = [];
foreach ($tagsByPackage as $name => $tags) {
    $sorted = Semver::rsort($tags);
    $kept = array_slice($sorted, 0, $keep);
    $pruned = array_slice($sorted, $keep);
    printf("%-45s %2d Tags, behalte: %s\n", $name, count($sorted), implode(', ', $kept) ?: '-');
    if ($pruned !== []) {
        $blacklist[$name] = implode(' || ', $pruned);
        printf("%-45s    blacklistet: %s\n", '', implode(', ', $pruned));
    }
}

if ($blacklist === []) {
    unset($config['blacklist']);
    echo "Kein Paket hat mehr als {$keep} Versionen – Blacklist leer.\n";
} else {
    ksort($blacklist);
    $config['blacklist'] = $blacklist;
}

file_put_contents(
    $configFile,
    json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);
echo "Blacklist in {$configFile} aktualisiert (" . count($blacklist) . " Pakete beschränkt).\n";
