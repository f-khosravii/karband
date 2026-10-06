<?php

declare(strict_types=1);

/**
 * Phase 16: source-only inspection of an already assembled INTL tree.
 * Does not load Laravel, modify files, run migrations or contact any service.
 */

$root = realpath($argv[1] ?? '') ?: null;
if (!$root || !is_dir($root . '/packages')) {
    fwrite(STDERR, "Usage: php verify-structure.php /path/to/assembled/intl-platform\n");
    exit(1);
}

$errors = [];
$packages = [];
$maps = [];
foreach (glob($root . '/packages/*/composer.json') ?: [] as $path) {
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data['name']) || !is_array($data['require'] ?? null)) {
        $errors[] = "Invalid package composer.json: $path";
        continue;
    }
    $name = $data['name'];
    if (isset($packages[$name])) {
        $errors[] = "Duplicate package name: $name";
    }
    $packages[$name] = [
        'path' => dirname($path),
        'dependencies' => array_values(array_filter(array_keys($data['require']),
            static fn (string $dependency): bool => str_starts_with($dependency, 'intl/'))),
    ];
    foreach (($data['autoload']['psr-4'] ?? []) as $namespace => $folder) {
        $maps[$namespace] = dirname($path) . '/' . rtrim($folder, '/');
    }
}

$states = [];
$visit = static function (string $name) use (&$visit, &$states, $packages, &$errors): void {
    if (($states[$name] ?? 0) === 2) { return; }
    if (($states[$name] ?? 0) === 1) {
        $errors[] = "Cyclic dependency involving: $name";
        return;
    }
    $states[$name] = 1;
    foreach ($packages[$name]['dependencies'] as $required) {
        if (!isset($packages[$required])) {
            $errors[] = "Unresolved local Composer dependency: $name -> $required";
        } else {
            $visit($required);
        }
    }
    $states[$name] = 2;
};
foreach (array_keys($packages) as $name) { $visit($name); }

$files = [];
foreach ([$root . '/packages', $root . '/apps/sandbox/tests', $root . '/tools'] as $folder) {
    if (!is_dir($folder)) { continue; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
$imports = 0;
foreach ($files as $file) {
    $source = (string) file_get_contents($file);
    preg_match_all('/^use\s+(Intl\\\\[A-Za-z0-9_\\\\]+)\s*(?:as\s+\w+)?\s*;/m', $source, $matches);
    $seen = [];
    foreach ($matches[1] as $class) {
        $imports++;
        if (isset($seen[$class])) { $errors[] = "Duplicate import: $file: $class"; }
        $seen[$class] = true;
        $found = false;
        foreach ($maps as $namespace => $src) {
            if (str_starts_with($class, $namespace)) {
                $relative = str_replace('\\', '/', substr($class, strlen($namespace)));
                if (is_file($src . '/' . $relative . '.php')) { $found = true; break; }
            }
        }
        if (!$found) { $errors[] = "Missing mapped Intl class import: $file: $class"; }
    }
}
echo 'Packages: ', count($packages), "\n";
echo 'PHP source/test/tool files scanned: ', count($files), "\n";
echo 'Intl imports checked: ', $imports, "\n";
echo 'Local Composer dependency graph: ', $errors === [] ? 'PASS' : 'ISSUES', "\n";
foreach ($errors as $error) { echo '[ERROR] ', $error, "\n"; }
$blocker = false;
$phase12 = $root . '/apps/sandbox/tests/Feature/IntlPhase12ReferenceFlowTest.php';
$paymentService = $root . '/packages/billing-laravel/src/Services/PaymentService.php';
if (is_file($phase12) && is_file($paymentService)) {
    $old = str_contains((string) file_get_contents($phase12), '->dispatch($payment->id)');
    $new = str_contains((string) file_get_contents($paymentService),
        'dispatch(string $paymentId, int $confirmedAmount, string $confirmedCurrency, int $expectedRevision)');
    if ($old && $new) { $blocker = true; echo "[KNOWN BLOCKER] Phase 12 test uses obsolete Billing dispatch() signature; update fixture before running complete suite.\n"; }
}
if ($errors !== []) { exit(2); }
if ($blocker && in_array("--release-gate", $argv, true)) { exit(3); }
exit(0);
