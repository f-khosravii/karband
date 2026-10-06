<?php

declare(strict_types=1);

// Read-only checklist for a future INDEPENDENT test checkout. Never executes Composer/migrations.
$root = isset($argv[1]) ? realpath($argv[1]) : realpath(dirname(__DIR__, 2));
if ($root === false) { fwrite(STDERR, "Invalid project path.\n"); exit(2); }
$packages = ['contracts','workflow','workflow-laravel','authorization-laravel','service-catalog',
    'service-catalog-laravel','dynamic-forms','dynamic-forms-laravel','documents','documents-laravel',
    'billing','billing-laravel','service-requests','service-requests-laravel','audit-laravel',
    'service-recipients','service-recipients-laravel'];
$missing = [];
foreach ($packages as $package) {
    $file = $root . '/packages/' . $package . '/composer.json';
    if (!is_file($file)) { $missing[] = $package; continue; }
    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded) || ($decoded['name'] ?? null) !== 'intl/' . $package) {
        $missing[] = $package . ' (invalid composer manifest)';
    }
}
echo 'Known package manifests: ' . (count($packages) - count($missing)) . '/' . count($packages) . "\n";
if ($missing) { echo 'Missing/incomplete: ' . implode(', ', $missing) . "\n"; }
foreach (['composer.json','composer.lock','apps/sandbox/composer.json','apps/sandbox/composer.lock'] as $file) {
    echo (is_file($root . '/' . $file) ? '[present] ' : '[missing] ') . $file . "\n";
}
echo "No files or databases were changed. Output is a read-only preflight, NOT a release certificate.\n";
exit($missing ? 1 : 0);
