<?php

declare(strict_types=1);

namespace Karband\Tests\Architecture;

final class ArchitectureTestHelper
{
    private static ?array $packages = null;

    /**
     * Discover all packages dynamically under packages/ directory (recursive).
     *
     * @return array<string, array{
     *     path: string,
     *     composer_path: string,
     *     composer: array,
     *     name: string,
     *     namespace: ?string,
     *     is_core: bool,
     *     is_adapter: bool,
     *     php_files: array<string>
     * }>
     */
    public static function getPackages(): array
    {
        if (self::$packages !== null) {
            return self::$packages;
        }

        $root = dirname(__DIR__, 2);
        $packagesDir = $root . '/packages';
        $packages = [];

        $directoryIterator = new \RecursiveDirectoryIterator($packagesDir, \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directoryIterator, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getFilename() === 'composer.json') {
                $composerPath = $item->getPathname();
                $pkgDir = $item->getPath();
                
                // Avoid vendor dirs
                if (str_contains($composerPath, '/vendor/') || str_contains($composerPath, '\\vendor\\')) {
                    continue;
                }

                $content = file_get_contents($composerPath);
                $composer = json_decode($content, true);
                if (!is_array($composer) || !isset($composer['name'])) {
                    continue;
                }

                $name = $composer['name'];
                $isAdapter = str_ends_with($name, '-laravel') || str_ends_with($name, '-panel');
                $isCore = !$isAdapter;

                // Find PSR-4 namespace
                $namespace = null;
                if (isset($composer['autoload']['psr-4'])) {
                    $namespace = array_key_first($composer['autoload']['psr-4']);
                }

                // Collect PHP files
                $phpFiles = [];
                $srcDir = $pkgDir . '/src';
                if (is_dir($srcDir)) {
                    $srcIterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
                    );
                    foreach ($srcIterator as $file) {
                        if ($file->isFile() && $file->getExtension() === 'php') {
                            $phpFiles[] = $file->getPathname();
                        }
                    }
                }

                $packages[$name] = [
                    'path' => $pkgDir,
                    'composer_path' => $composerPath,
                    'composer' => $composer,
                    'name' => $name,
                    'namespace' => $namespace,
                    'is_core' => $isCore,
                    'is_adapter' => $isAdapter,
                    'php_files' => $phpFiles,
                ];
            }
        }

        self::$packages = $packages;
        return self::$packages;
    }

    /**
     * Extract all use imports and fully-qualified class names from a PHP file with line numbers.
     *
     * @return array<int, array{class: string, line: int, kind: string}>
     */
    public static function extractImportsAndReferences(string $filePath): array
    {
        $code = file_get_contents($filePath);
        $tokens = token_get_all($code);
        $references = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // 1. Detect `use ...;` statements
            if (is_array($token) && $token[0] === T_USE) {
                $useLine = $token[2];
                $i++;

                // Skip whitespace
                while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                    $i++;
                }

                // Skip closures use ($var) or trait uses inside classes (rough check: function keyword before or open paren)
                if ($i < $count && $tokens[$i] === '(') {
                    continue;
                }

                // Check for `use function` or `use const`
                if ($i < $count && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_FUNCTION, T_CONST], true)) {
                    $i++;
                    while ($i < $count && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                        $i++;
                    }
                }

                $prefix = '';
                $inGroup = false;
                $groupImports = [];
                $currentPart = '';

                while ($i < $count && $tokens[$i] !== ';') {
                    $t = $tokens[$i];

                    if ($t === '{') {
                        $inGroup = true;
                        $prefix = trim($currentPart);
                        $currentPart = '';
                    } elseif ($t === '}') {
                        if (trim($currentPart) !== '') {
                            $groupImports[] = trim($currentPart);
                            $currentPart = '';
                        }
                        $inGroup = false;
                    } elseif ($t === ',') {
                        if ($inGroup) {
                            if (trim($currentPart) !== '') {
                                $groupImports[] = trim($currentPart);
                                $currentPart = '';
                            }
                        } else {
                            if (trim($currentPart) !== '') {
                                $references[] = ['class' => self::cleanImportName(trim($currentPart)), 'line' => $useLine, 'kind' => 'use'];
                                $currentPart = '';
                            }
                        }
                    } else {
                        if (is_array($t)) {
                            $currentPart .= $t[1];
                        } else {
                            $currentPart .= $t;
                        }
                    }
                    $i++;
                }

                if ($inGroup || !empty($groupImports)) {
                    if (trim($currentPart) !== '') {
                        $groupImports[] = trim($currentPart);
                    }
                    foreach ($groupImports as $sub) {
                        $full = rtrim($prefix, '\\') . '\\' . ltrim(self::cleanImportName($sub), '\\');
                        $references[] = ['class' => $full, 'line' => $useLine, 'kind' => 'use_group'];
                    }
                } elseif (trim($currentPart) !== '') {
                    $references[] = ['class' => self::cleanImportName(trim($currentPart)), 'line' => $useLine, 'kind' => 'use'];
                }
            }

            // 2. Detect inline FQCNs / class references like `Karband\...` or `\Karband\...`
            if (is_array($token) && ($token[0] === T_NAME_FULLY_QUALIFIED || $token[0] === T_NAME_QUALIFIED || $token[0] === T_STRING)) {
                $name = $token[1];
                $line = $token[2];

                // If preceded by namespace or use, skip (handled above)
                $prevIdx = $i - 1;
                while ($prevIdx >= 0 && is_array($tokens[$prevIdx]) && $tokens[$prevIdx][0] === T_WHITESPACE) {
                    $prevIdx--;
                }
                if ($prevIdx >= 0 && is_array($tokens[$prevIdx]) && in_array($tokens[$prevIdx][0], [T_USE, T_NAMESPACE], true)) {
                    continue;
                }

                // If starting with Karband\ or \Karband\ or Illuminate\ or Spatie\
                if (str_starts_with($name, 'Karband\\') || str_starts_with($name, '\\Karband\\')
                    || str_starts_with($name, 'Illuminate\\') || str_starts_with($name, '\\Illuminate\\')
                    || str_starts_with($name, 'Spatie\\') || str_starts_with($name, '\\Spatie\\')) {
                    $cleanName = ltrim($name, '\\');
                    $references[] = ['class' => $cleanName, 'line' => $line, 'kind' => 'fqcn'];
                }
            }
        }

        return $references;
    }

    /**
     * Helper to extract imports list.
     *
     * @return array<string>
     */
    public static function extractImports(string $filePath): array
    {
        $refs = self::extractImportsAndReferences($filePath);
        return array_values(array_unique(array_column($refs, 'class')));
    }

    private static function cleanImportName(string $raw): string
    {
        // Handle aliases like `Foo\Bar as Baz`
        if (preg_match('/^(.*?)\s+as\s+/i', $raw, $m)) {
            $raw = $m[1];
        }
        return trim(ltrim($raw, '\\'));
    }

    /**
     * Map of capability to owned table prefixes and exact table names.
     */
    public static function getCapabilityTableMap(): array
    {
        return [
            'audit' => ['audit_', 'activity_log'],
            'authorization' => ['permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions'],
            'billing' => ['billing_', 'payment_intents', 'payment_attempts', 'payment_events', 'full_refunds', 'refund_events'],
            'directory' => ['directory_', 'directory_organizations', 'directory_organization_roles', 'directory_users', 'directory_memberships', 'directory_external_references'],
            'documents' => ['document_', 'document_drafts', 'document_definitions', 'document_sets', 'document_files'],
            'dynamic-forms' => ['dynamic_form_', 'form_drafts', 'form_definitions', 'form_answers'],
            'external-auth' => ['external_auth_', 'external_sessions'],
            'request-journey' => ['request_journey_', 'journey_definitions', 'request_journeys'],
            'service-catalog' => ['service_catalog_', 'catalog_services', 'catalog_service_revisions'],
            'service-recipients' => ['service_recipient', 'recipients', 'recipient_identity_changes', 'recipient_representatives'],
            'service-requests' => ['service_request', 'service_requests', 'request_cancellations', 'request_representatives', 'request_corrections', 'request_quotes', 'request_quote_proposals'],
            'workflow' => ['workflow_', 'workflow_definitions', 'workflow_instances', 'workflow_history'],
        ];
    }

    /**
     * Extract all SQL table accesses in a PHP file with line numbers.
     *
     * @return array<int, array{table: string, line: int, owner: string}>
     */
    public static function extractTableAccesses(string $filePath): array
    {
        $code = file_get_contents($filePath);
        $lines = explode("\n", $code);
        $tableMap = self::getCapabilityTableMap();
        $accesses = [];

        foreach ($lines as $lineNum => $lineContent) {
            // Find ->table('...'), DB::table('...'), ->from('...'), ->join('...')
            if (preg_match_all('/(?:->table\(|DB::table\(|->from\(|->join\(|->leftJoin\(|->rightJoin\(|->crossJoin\()\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $lineContent, $matches)) {
                foreach ($matches[1] as $table) {
                    // Identify capability owning this table
                    $owner = 'unknown';
                    foreach ($tableMap as $cap => $tables) {
                        foreach ($tables as $t) {
                            if ($table === $t || str_starts_with($table, $t)) {
                                $owner = $cap;
                                break 2;
                            }
                        }
                    }
                    $accesses[] = [
                        'table' => $table,
                        'line' => $lineNum + 1,
                        'owner' => $owner,
                    ];
                }
            }
        }

        return $accesses;
    }
}
