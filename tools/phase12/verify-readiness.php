<?php

declare(strict_types=1);

/** Read-only phase 12 inventory verification; does NOT install, migrate or contact any external service. */
$root = dirname(__DIR__, 2);
$sourceOnly = in_array('--source-only', $argv, true);
$names = [
    'workflow', 'workflow-laravel', 'authorization-laravel', 'service-catalog',
    'service-catalog-laravel', 'dynamic-forms', 'dynamic-forms-laravel',
    'documents', 'documents-laravel', 'billing', 'billing-laravel',
    'service-requests', 'service-requests-laravel', 'audit-laravel',
];
$failed = false;
foreach ($names as $name) {
    $path = $root . '/packages/' . $name . '/composer.json';
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    $ok = is_array($data) && ($data['name'] ?? null) === 'intl/' . $name;
    echo ($ok ? 'OK' : 'MISSING') . ' intl/' . $name . PHP_EOL;
    $failed = $failed || !$ok;
}
foreach ([
    'apps/sandbox/tests/Feature/IntlPhase12ReferenceFlowTest.php',
    'docs/phase12/README_FA.md',
    'docs/phase12/CONTRACT_MATRIX_FA.md',
    'docs/phase12/TEST_MATRIX_FA.md',
] as $relative) {
    $ok = is_file($root . '/' . $relative);
    echo ($ok ? 'OK' : 'MISSING') . ' ' . $relative . PHP_EOL;
    $failed = $failed || !$ok;
}
if (!$sourceOnly) {
    $installed = $root . '/apps/sandbox/vendor/composer/installed.json';
    if (!is_file($installed)) {
        echo 'PENDING apps/sandbox/vendor/composer/installed.json (Sandbox not installed)' . PHP_EOL;
        $failed = true;
    } else {
        $data = json_decode((string) file_get_contents($installed), true);
        $packages = is_array($data) ? ($data['packages'] ?? $data) : [];
        $installedNames = [];
        foreach ($packages as $package) {
            if (is_array($package) && isset($package['name'])) {
                $installedNames[$package['name']] = true;
            }
        }
        foreach (['intl/billing-laravel', 'intl/service-requests-laravel', 'intl/audit-laravel',
            'spatie/laravel-activitylog'] as $need) {
            $ok = isset($installedNames[$need]);
            echo ($ok ? 'OK' : 'NOT_INSTALLED') . ' ' . $need . PHP_EOL;
            $failed = $failed || !$ok;
        }
    }
    $migrations = glob($root . '/apps/sandbox/database/migrations/*create_activity_log_table*.php') ?: [];
    $migrationOk = count($migrations) === 1;
    echo ($migrationOk ? 'OK' : 'REVIEW') . ' Activitylog migrations matching *create_activity_log_table*: '
        . count($migrations) . PHP_EOL;
    $failed = $failed || !$migrationOk;
    echo 'NOTE: this inventory tool DOES NOT execute PHPUnit or verify DB safety.' . PHP_EOL;
}
exit($failed ? 2 : 0);
