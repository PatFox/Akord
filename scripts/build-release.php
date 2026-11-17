<?php

/*
 * Builds a self-contained release archive of Akord:
 *
 *   php scripts/build-release.php v1.2.3
 *
 * Writes dist/akord-v1.2.3.zip (production vendor/, compiled public/build, VERSION) and
 * dist/akord-v1.2.3.zip.sha256. The sources are the files git would commit from the working
 * tree (tracked plus untracked-not-ignored), so a clean checkout of a tag builds exactly that tag.
 * scripts/akord.ps1 installs and updates instances from these archives.
 */

declare(strict_types=1);

$version = $argv[1] ?? '';

if (preg_match('/^v\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $version) !== 1) {
    fwrite(STDERR, "Usage: php scripts/build-release.php vMAJOR.MINOR.PATCH[-prerelease]\n");
    exit(1);
}

$root = dirname(__DIR__);
$dist = $root.'/dist';
$build = $dist.'/build';
$archive = "{$dist}/akord-{$version}.zip";

// Development-only paths, never shipped.
$excluded = [
    '.github/', 'tests/', 'integrations/', '.omp-plugin/', 'scripts/build-release.php',
    'PLAN.md', 'phpunit.xml', 'phpstan.neon', 'pint.json', '.editorconfig', '.gitattributes',
];

// Needed to compile the assets, removed afterwards.
$buildOnly = ['node_modules', 'package.json', 'package-lock.json', '.npmrc', 'vite.config.js'];

function step(string $message): void
{
    fwrite(STDOUT, "==> {$message}\n");
}

function run(string $command, string $cwd): void
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $cwd);

    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException("Command failed: {$command}");
    }
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeTree($path.'/'.$entry);
        }
    }

    rmdir($path);
}

step("Collecting sources for {$version}");
removeTree($build);
@unlink($archive);
@unlink($archive.'.sha256');
mkdir($build, 0777, true);

// -z: NUL-separated and never quoted. Without it git escapes non-ASCII names
// (Livewire's ⚡ single-file pages) and those files would be skipped.
$listing = shell_exec('git -C '.escapeshellarg($root).' ls-files -z --cached --others --exclude-standard');

if (! is_string($listing) || $listing === '') {
    throw new RuntimeException('git ls-files failed');
}

$files = array_filter(explode("\0", $listing), fn (string $file): bool => $file !== '');

$copied = 0;

foreach ($files as $file) {
    foreach ($excluded as $prefix) {
        if ($file === $prefix || str_starts_with($file, $prefix)) {
            continue 2;
        }
    }

    // Tracked files deleted in the working tree are listed but absent.
    if (! is_file("{$root}/{$file}")) {
        fwrite(STDOUT, "    skipped (not in the working tree): {$file}\n");

        continue;
    }

    $target = "{$build}/{$file}";

    if (! is_dir(dirname($target))) {
        mkdir(dirname($target), 0777, true);
    }

    copy("{$root}/{$file}", $target);
    $copied++;
}

step("Copied {$copied} files");

step('Installing PHP dependencies (no dev)');
run('composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress', $build);

step('Compiling assets');
run('npm ci --no-audit --no-fund', $build);
run('npm run build', $build);

foreach ($buildOnly as $path) {
    removeTree("{$build}/{$path}");
}

file_put_contents("{$build}/VERSION", $version."\n");

step("Writing {$archive}");
$zip = new ZipArchive;

if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException("Cannot create {$archive}");
}

$entries = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($build, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

foreach ($entries as $entry) {
    $name = str_replace('\\', '/', substr($entry->getPathname(), strlen($build) + 1));

    // Flag names as UTF-8 so unzip tools on Windows don't decode ⚡ as CP437.
    $entry->isDir()
        ? $zip->addEmptyDir($name, ZipArchive::FL_ENC_UTF_8)
        : $zip->addFile($entry->getPathname(), $name, flags: ZipArchive::FL_OVERWRITE | ZipArchive::FL_ENC_UTF_8);
}

if (! $zip->close()) {
    throw new RuntimeException("Cannot write {$archive}");
}

$hash = hash_file('sha256', $archive);
file_put_contents($archive.'.sha256', $hash.'  '.basename($archive)."\n");
removeTree($build);

step(sprintf('Done: %s (%.1f MB, sha256 %s)', $archive, filesize($archive) / 1048576, $hash));
