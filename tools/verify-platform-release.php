<?php

declare(strict_types=1);

/**
 * Offline, read-only structural verifier. Does NOT execute migrations, Composer, or tests.
 * Place this file at tools/verify-platform-release.php in the INTL monorepo.
 */
$root = dirname(__DIR__);
$complete = in_array('--complete', $argv, true);
$errors = [];
$warnings = [];
$notes = [];
$packages = [];

$expect = [
    'intl/contracts',
    'intl/workflow', 'intl/workflow-laravel',
    'intl/authorization-laravel',
    'intl/service-catalog', 'intl/service-catalog-laravel',
    'intl/dynamic-forms', 'intl/dynamic-forms-laravel',
    'intl/documents', 'intl/documents-laravel',
    'intl/billing', 'intl/billing-laravel',
    'intl/service-requests', 'intl/service-requests-laravel',
    'intl/audit-laravel',
];

$manifests = glob($root . '/packages/*/composer.json') ?: [];
if ($manifests === []) {
    $errors[] = 'No packages/*/composer.json files found. Run at the project root with this script under tools/.';
}

foreach ($manifests as $manifest) {
    $json = json_decode((string) file_get_contents($manifest), true);
    if (!is_array($json)) {
        $errors[] = 'Invalid package JSON: ' . $manifest;
        continue;
    }
    $name = $json['name'] ?? null;
    if (!is_string($name) || !preg_match('~^intl/[a-z0-9-]+$~D', $name)) {
        $errors[] = 'Unexpected/missing INTL package name: ' . $manifest;
        continue;
    }
    if (isset($packages[$name])) {
        $errors[] = 'Duplicate Composer package name: ' . $name;
        continue;
    }
    $packageDir = dirname($manifest);
    $requires = $json['require'] ?? [];
    if (!is_array($requires)) {
        $errors[] = $name . ': require must be an object.';
        $requires = [];
    }
    $autoload = $json['autoload']['psr-4'] ?? [];
    if (!is_array($autoload) || $autoload === []) {
        $errors[] = $name . ': PSR-4 autoload mapping missing.';
    }
    foreach ($autoload as $namespace => $rel) {
        if (!is_string($namespace) || !str_ends_with($namespace, '\\') || !is_string($rel)) {
            $errors[] = $name . ': invalid PSR-4 mapping.';
            continue;
        }
        if (!is_dir($packageDir . '/' . $rel)) {
            $errors[] = $name . ': PSR-4 source directory absent: ' . $rel;
        }
    }
    foreach ($requires as $dep => $constraint) {
        if (str_starts_with((string) $dep, 'intl/') && is_string($constraint)
            && (str_contains($constraint, 'dev-main') || $constraint === '@dev')) {
            $warnings[$name . ' -> ' . $dep] = 'Dev-only dependency; decide versioning and lock before independent production release.';
        }
    }
    $packages[$name] = ['manifest' => $manifest, 'require' => $requires, 'dir' => $packageDir];
}

foreach ($packages as $name => $package) {
    foreach ($package['require'] as $dep => $constraint) {
        if (str_starts_with((string) $dep, 'intl/') && !isset($packages[$dep])) {
            if ($complete) {
                $errors[] = $name . ' requires missing local package ' . $dep;
            } else {
                $warnings[$name . ':' . $dep] = 'Internal dependency not present yet: ' . $dep;
            }
        }
    }
}

$visit = [];
$walk = static function (string $name, array $stack) use (&$walk, &$visit, $packages, &$errors): void {
    $visit[$name] = 1;
    foreach ($packages[$name]['require'] as $dep => $_) {
        if (!isset($packages[$dep])) { continue; }
        if (($visit[$dep] ?? 0) === 1) {
            $errors[] = 'Circular dependency: ' . implode(' -> ', [...$stack, $dep]);
        } elseif (($visit[$dep] ?? 0) === 0) {
            $walk($dep, [...$stack, $dep]);
        }
    }
    $visit[$name] = 2;
};
foreach (array_keys($packages) as $name) {
    if (($visit[$name] ?? 0) === 0) { $walk($name, [$name]); }
}
if ($complete) {
    foreach ($expect as $name) {
        if (!isset($packages[$name])) { $errors[] = 'Complete mode: expected package absent: ' . $name; }
    }
}

$basenameOwner = [];
foreach (glob($root . '/packages/*/database/migrations/*.php') ?: [] as $migration) {
    $basename = basename($migration);
    if (isset($basenameOwner[$basename])) {
        $errors[] = 'Duplicate migration filename: ' . $basename;
    }
    $basenameOwner[$basename] = $migration;
}

$featureDir = $root . '/apps/sandbox/tests/Feature';
foreach (glob($featureDir . '/*.php') ?: [] as $test) {
    $source = (string) file_get_contents($test);
    preg_match_all('/^use\s+([^;]+);\s*$/m', $source, $matches);
    $counts = array_count_values(array_map('trim', $matches[1]));
    foreach ($counts as $import => $occurrences) {
        if ($occurrences > 1) {
            $errors[] = basename($test) . ': duplicate import: ' . $import;
        }
    }
}
$docTest = $featureDir . '/IntlDocumentsTest.php';
if (is_file($docTest)) {
    $source = (string) file_get_contents($docTest);
    foreach (['ActorResolver::class', 'WorkflowAuthorizer::class'] as $binding) {
        if (!str_contains($source, $binding)) {
            if ($complete) {
                $errors[] = 'Documents test missing Workflow bridge binding: ' . $binding;
            } else {
                $warnings['Documents test ' . $binding] = 'Original ZIP fixture may need the single-use bridge fix; do not double-apply it.';
            }
        }
    }
}

$rootComposer = $root . '/composer.json';
$sandboxComposer = $root . '/apps/sandbox/composer.json';
foreach ([$rootComposer => 'packages/*', $sandboxComposer => '../../packages/*'] as $manifest => $expectedPath) {
    if (!is_file($manifest)) {
        $warnings[$manifest] = 'Composer manifest not available in this working copy.';
        continue;
    }
    $json = json_decode((string) file_get_contents($manifest), true);
    if (!is_array($json)) { $errors[] = 'Invalid Composer JSON: ' . $manifest; continue; }
    $found = false;
    foreach (($json['repositories'] ?? []) as $repo) {
        if (is_array($repo) && ($repo['type'] ?? null) === 'path') {
            $url = (string) ($repo['url'] ?? '');
            if ($url === $expectedPath || $url === './' . $expectedPath) { $found = true; }
        }
    }
    if (!$found) {
        $warnings[$manifest] = 'Path repository ' . $expectedPath . ' is not configured/detectable.';
    }
}

$notes[] = 'Detected ' . count($packages) . ' package manifests, ' . count($basenameOwner) . ' package migrations.';
$notes[] = 'This is a structural check only: not Composer SAT solving, PHPUnit, Laravel migrations, concurrent DB testing, security pentesting, or external API verification.';
$notes[] = 'Dev-only constraints remain warnings until real, independently installable package versions and lockfiles exist.';

foreach ($notes as $message) { echo '[INFO] ' . $message . PHP_EOL; }
foreach ($warnings as $key => $message) { echo '[WARN] ' . $key . ': ' . $message . PHP_EOL; }
foreach (array_unique($errors) as $message) { echo '[ERROR] ' . $message . PHP_EOL; }
if ($errors) {
    fwrite(STDERR, 'RESULT: FAILED (' . count(array_unique($errors)) . ' structural errors)' . PHP_EOL);
    exit(1);
}
echo 'RESULT: PASSED (structural only, ' . count($warnings) . ' advisory warnings)' . PHP_EOL;
