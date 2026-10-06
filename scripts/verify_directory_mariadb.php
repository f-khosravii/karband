<?php

declare(strict_types=1);

require_once __DIR__ . '/../apps/sandbox/vendor/autoload.php';

$app = require_once __DIR__ . '/../apps/sandbox/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => '127.0.0.1',
    'database.connections.mysql.port' => '3307',
    'database.connections.mysql.database' => 'intl_sandbox',
    'database.connections.mysql.username' => 'intl_test',
    'database.connections.mysql.password' => 'intl_pass',
    'intl-service-recipients.connection' => 'mysql',
    'intl-service-catalog.connection' => 'mysql',
    'intl-service-requests.connection' => 'mysql',
    'intl-dynamic-forms.connection' => 'mysql',
    'intl-documents.connection' => 'mysql',
    'intl-billing.connection' => 'mysql',
    'intl-external-auth.connection' => 'mysql',
    'intl-workflow.connection' => 'mysql',
    'intl-journey.connection' => 'mysql',
    'intl-directory.connection' => 'mysql',
]);

$db = $app['db']->connection('mysql');

echo "=== 1. Clean Migration Verification on MariaDB 10.6 ===\n";
Illuminate\Support\Facades\Artisan::call('migrate:fresh', [
    '--database' => 'mysql',
    '--force' => true,
]);
echo Illuminate\Support\Facades\Artisan::output();

// Inspect created directory tables
$tables = ['intl_directory_organizations', 'intl_directory_organization_roles', 'intl_directory_users', 'intl_directory_memberships', 'intl_directory_external_references'];
foreach ($tables as $table) {
    $exists = $db->getSchemaBuilder()->hasTable($table);
    echo "Table [{$table}]: " . ($exists ? "EXISTS" : "MISSING") . "\n";
    if ($exists) {
        $cols = $db->getSchemaBuilder()->getColumnListing($table);
        echo "  Columns: " . implode(', ', $cols) . "\n";
    }
}

// Verify Foreign Keys & Constraints
echo "\n=== 2. Constraint Verification on MariaDB 10.6 ===\n";
$fks = $db->select("
    SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME 
    FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = 'intl_sandbox' AND REFERENCED_TABLE_NAME IS NOT NULL
    AND TABLE_NAME LIKE 'intl_directory%'
");
foreach ($fks as $fk) {
    echo "FK: {$fk->TABLE_NAME}.{$fk->CONSTRAINT_NAME} -> {$fk->REFERENCED_TABLE_NAME}\n";
}

$uniques = $db->select("
    SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'intl_sandbox' AND NON_UNIQUE = 0
    AND TABLE_NAME LIKE 'intl_directory%'
");
foreach ($uniques as $u) {
    echo "Unique: {$u->TABLE_NAME}.{$u->INDEX_NAME} ({$u->COLUMN_NAME})\n";
}

echo "\n=== 3. Upgrade Verification from v0.2 Schema ===\n";
// Insert sample v0.2 data
$db->table('intl_external_sessions')->insert([
    'id' => (string) Illuminate\Support\Str::ulid(),
    'provider' => 'epishkhan',
    'external_session_id' => 'sess-v02-test',
    'subject_id' => 'SUBJ-V02-1',
    'organization_id' => 'AG-100',
    'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);

$initialExtSessionCount = $db->table('intl_external_sessions')->count();
echo "Pre-Directory v0.2 external sessions count: {$initialExtSessionCount}\n";

// Ensure Directory tables are empty initially (no automatic ambiguous backfill)
$orgCount = $db->table('intl_directory_organizations')->count();
$userCount = $db->table('intl_directory_users')->count();
$memCount = $db->table('intl_directory_memberships')->count();
$refCount = $db->table('intl_directory_external_references')->count();

echo "Directory counts (must all be 0): orgs={$orgCount}, users={$userCount}, memberships={$memCount}, refs={$refCount}\n";

if ($orgCount !== 0 || $userCount !== 0 || $memCount !== 0 || $refCount !== 0) {
    throw new RuntimeException("Directory migration performed illegal automatic backfill!");
}

// Verify v0.2 data integrity
$afterExtSessionCount = $db->table('intl_external_sessions')->count();
if ($afterExtSessionCount !== $initialExtSessionCount) {
    throw new RuntimeException("Pre-existing data altered!");
}
echo "Pre-existing v0.2 data untouched: SUCCESS\n";

echo "\n=== MARIADB VERIFICATION: SUCCESS ===\n";
