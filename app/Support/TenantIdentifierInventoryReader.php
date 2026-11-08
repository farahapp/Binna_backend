<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;

final class TenantIdentifierInventoryReader
{
    private const LEGACY_FIELD_PATTERN = '/(?:cr|qid|commercial|registration|license|identifier)/i';

    public function read(string $path, DateTimeImmutable $asOf): array
    {
        $realPath = realpath($path);
        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            throw new RuntimeException('Source must be an existing readable local file.');
        }

        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        if (in_array($extension, ['sqlite', 'sqlite3', 'db'], true)) {
            [$tenants, $identifiers, $tenantColumns, $identifierColumns, $identifierTablePresent] = $this->readSqlite($realPath);
            $sourceKind = 'sqlite_read_only';
        } elseif ($extension === 'sql') {
            [$tenants, $identifiers, $tenantColumns, $identifierColumns, $identifierTablePresent] = $this->readSqlDump($realPath);
            $sourceKind = 'sql_dump_parsed_without_execution';
        } else {
            throw new RuntimeException('Supported source files are .sql, .sqlite, .sqlite3, and .db.');
        }

        return $this->buildReport(
            $sourceKind,
            $tenants,
            $identifiers,
            $tenantColumns,
            $identifierColumns,
            $identifierTablePresent,
            $asOf,
        );
    }

    private function readSqlite(string $path): array
    {
        if (! extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('The local PHP pdo_sqlite extension is required for SQLite files.');
        }

        $uri = str_replace(
            ['%', ' ', '#', '?'],
            ['%25', '%20', '%23', '%3F'],
            str_replace('\\', '/', $path),
        );

        try {
            $database = new PDO('sqlite:file:'.$uri.'?mode=ro', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Unable to open SQLite source in read-only mode.', 0, $exception);
        }

        $tables = $database->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('tenants', 'tenant_identifiers')"
        )->fetchAll(PDO::FETCH_COLUMN);
        $tableNames = array_fill_keys($tables, true);

        if (! isset($tableNames['tenants'])) {
            throw new RuntimeException('SQLite source does not contain a tenants table.');
        }

        $tenantColumns = $this->sqliteColumns($database, 'tenants');
        $tenantRows = $this->selectSqliteRows($database, 'tenants', $tenantColumns);
        $identifierPresent = isset($tableNames['tenant_identifiers']);
        $identifierColumns = $identifierPresent ? $this->sqliteColumns($database, 'tenant_identifiers') : [];
        $identifierRows = $identifierPresent
            ? $this->selectSqliteRows($database, 'tenant_identifiers', $identifierColumns)
            : [];

        return [$tenantRows, $identifierRows, $tenantColumns, $identifierColumns, $identifierPresent];
    }

    private function sqliteColumns(PDO $database, string $table): array
    {
        $statement = $database->query('PRAGMA table_info("'.$table.'")');

        return array_map(
            static fn (array $column): string => (string) $column['name'],
            $statement->fetchAll(),
        );
    }

    private function selectSqliteRows(PDO $database, string $table, array $columns): array
    {
        $selectedColumns = $table === 'tenants'
            ? array_values(array_filter($columns, static fn (string $column): bool =>
                in_array($column, ['id', 'legal_type'], true) || preg_match(self::LEGACY_FIELD_PATTERN, $column) === 1
            ))
            : array_values(array_intersect(
                ['id', 'tenant_id', 'type', 'number', 'normalized_number', 'validity_status', 'expires_at'],
                $columns,
            ));

        if ($selectedColumns === []) {
            return [];
        }

        $quoted = implode(', ', array_map(static fn (string $column): string => '"'.$column.'"', $selectedColumns));
        $statement = $database->query('SELECT '.$quoted.' FROM "'.$table.'"');

        return $statement->fetchAll();
    }

    private function readSqlDump(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read SQL source file.');
        }

        $schema = $this->parseSqlSchema($contents);
        $rows = ['tenants' => [], 'tenant_identifiers' => []];

        foreach ($this->splitSqlStatements($contents) as $statement) {
            if (! preg_match(
                '/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+(?:[`"]?[A-Za-z0-9_]+[`"]?\s*\.\s*)?[`"]?([A-Za-z0-9_]+)[`"]?\s*(?:\((.*?)\))?\s*VALUES\s*(.*)$/is',
                $statement,
                $matches,
            )) {
                if (preg_match(
                    '/^\s*(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+(?:[`"]?[A-Za-z0-9_]+[`"]?\s*\.\s*)?[`"]?(tenants|tenant_identifiers)[`"]?/i',
                    $statement,
                    $unsupported,
                )) {
                    throw new RuntimeException("Unsupported INSERT form for inventory table [{$unsupported[1]}].");
                }
                continue;
            }

            $table = strtolower($matches[1]);
            if (! array_key_exists($table, $rows)) {
                continue;
            }

            $columns = isset($matches[2]) && trim($matches[2]) !== ''
                ? $this->parseColumnList($matches[2])
                : ($schema[$table] ?? []);
            if ($columns === []) {
                throw new RuntimeException("Cannot determine columns for INSERT into {$table}.");
            }

            foreach ($this->parseInsertRows($matches[3]) as $values) {
                if (count($columns) !== count($values)) {
                    throw new RuntimeException("INSERT column/value count mismatch for {$table}.");
                }

                $row = [];
                foreach ($columns as $index => $column) {
                    if ($table === 'tenants' && (
                        in_array($column, ['id', 'legal_type'], true)
                        || preg_match(self::LEGACY_FIELD_PATTERN, $column) === 1
                    )) {
                        $row[$column] = $this->sqlLiteral($values[$index], $column);
                    } elseif ($table === 'tenant_identifiers' && in_array($column, [
                        'id', 'tenant_id', 'type', 'number', 'normalized_number', 'validity_status', 'expires_at',
                    ], true)) {
                        $row[$column] = $this->sqlLiteral($values[$index], $column);
                    }
                }
                $rows[$table][] = $row;
            }
        }

        $tenantColumns = $schema['tenants'] ?? array_values(array_unique(array_merge(
            ...array_map(static fn (array $row): array => array_keys($row), $rows['tenants']),
        )));
        $identifierColumns = $schema['tenant_identifiers'] ?? array_values(array_unique(array_merge(
            ...array_map(static fn (array $row): array => array_keys($row), $rows['tenant_identifiers']),
        )));
        $identifierPresent = isset($schema['tenant_identifiers']) || $rows['tenant_identifiers'] !== [];

        if ($tenantColumns === [] && $rows['tenants'] === []) {
            throw new RuntimeException('SQL source does not contain a readable tenants table definition or rows.');
        }

        return [$rows['tenants'], $rows['tenant_identifiers'], $tenantColumns, $identifierColumns, $identifierPresent];
    }

    private function parseSqlSchema(string $sql): array
    {
        $schema = [];
        foreach ($this->splitSqlStatements($sql) as $statement) {
            if (! preg_match(
                '/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:[`"]?[A-Za-z0-9_]+[`"]?\s*\.\s*)?[`"]?([A-Za-z0-9_]+)[`"]?\s*\(/is',
                $statement,
                $matches,
                PREG_OFFSET_CAPTURE,
            )) {
                continue;
            }

            $table = strtolower($matches[1][0]);
            if (! in_array($table, ['tenants', 'tenant_identifiers'], true)) {
                continue;
            }

            $openOffset = strpos($statement, '(', $matches[0][1]);
            $definition = $this->readBalancedParentheses($statement, $openOffset);
            $columns = [];
            foreach ($this->splitTopLevel($definition, ',') as $columnDefinition) {
                if (preg_match('/^\s*[`"]?([A-Za-z_][A-Za-z0-9_]*)[`"]?\s+/s', $columnDefinition, $columnMatch)) {
                    $name = $columnMatch[1];
                    if (! in_array(strtoupper($name), [
                        'PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'CONSTRAINT', 'FOREIGN', 'CHECK', 'FULLTEXT', 'SPATIAL',
                    ], true)) {
                        $columns[] = $name;
                    }
                }
            }
            $schema[$table] = $columns;
        }

        return $schema;
    }

    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            $next = $sql[$index + 1] ?? '';

            if ($lineComment) {
                if ($character === "\n") {
                    $lineComment = false;
                    $buffer .= "\n";
                }
                continue;
            }
            if ($blockComment) {
                if ($character === '*' && $next === '/') {
                    $blockComment = false;
                    $index++;
                }
                continue;
            }
            if ($quote !== null) {
                $buffer .= $character;
                if ($character === '\\' && $quote !== '`' && $next !== '') {
                    $buffer .= $next;
                    $index++;
                } elseif ($character === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if (($character === '-' && $next === '-' && (($sql[$index + 2] ?? ' ') <= ' ')) || $character === '#') {
                $lineComment = true;
                $index += $character === '-' ? 1 : 0;
                continue;
            }
            if ($character === '/' && $next === '*') {
                $blockComment = true;
                $index++;
                continue;
            }
            if (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;
                $buffer .= $character;
                continue;
            }
            if ($character === ';') {
                if (trim($buffer) !== '') {
                    $statements[] = $buffer;
                }
                $buffer = '';
                continue;
            }
            $buffer .= $character;
        }

        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }

    private function parseColumnList(string $list): array
    {
        return array_map(
            static fn (string $column): string => trim($column, " \t\n\r\0\x0B`\""),
            $this->splitTopLevel($list, ','),
        );
    }

    private function parseInsertRows(string $input): array
    {
        $rows = [];
        $length = strlen($input);
        $quote = null;
        $depth = 0;
        $row = '';

        for ($index = 0; $index < $length; $index++) {
            $character = $input[$index];
            $next = $input[$index + 1] ?? '';

            if ($quote !== null) {
                $row .= $character;
                if ($character === '\\' && $quote !== '`' && $next !== '') {
                    $row .= $next;
                    $index++;
                } elseif ($character === $quote) {
                    if ($next === $quote) {
                        $row .= $next;
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;
                if ($depth > 0) {
                    $row .= $character;
                } elseif (! ctype_space($character) && $character !== ',') {
                    throw new RuntimeException('Unsupported trailing SQL syntax after VALUES rows.');
                }
                continue;
            }
            if ($character === '(') {
                if ($depth++ === 0) {
                    $row = '';
                } else {
                    $row .= $character;
                }
                continue;
            }
            if ($character === ')') {
                if (--$depth < 0) {
                    throw new RuntimeException('Malformed VALUES clause in SQL source.');
                }
                if ($depth === 0) {
                    $rows[] = $this->splitTopLevel($row, ',');
                } else {
                    $row .= $character;
                }
                continue;
            }
            if ($depth > 0) {
                $row .= $character;
            }
        }

        if ($depth !== 0 || $quote !== null) {
            throw new RuntimeException('Unterminated VALUES clause in SQL source.');
        }

        return $rows;
    }

    private function splitTopLevel(string $input, string $delimiter): array
    {
        $parts = [];
        $buffer = '';
        $quote = null;
        $depth = 0;
        $length = strlen($input);

        for ($index = 0; $index < $length; $index++) {
            $character = $input[$index];
            $next = $input[$index + 1] ?? '';
            if ($quote !== null) {
                $buffer .= $character;
                if ($character === '\\' && $quote !== '`' && $next !== '') {
                    $buffer .= $next;
                    $index++;
                } elseif ($character === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;
                $buffer .= $character;
            } elseif ($character === '(') {
                $depth++;
                $buffer .= $character;
            } elseif ($character === ')') {
                $depth--;
                $buffer .= $character;
            } elseif ($character === $delimiter && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';
            } else {
                $buffer .= $character;
            }
        }
        $parts[] = trim($buffer);

        return $parts;
    }

    private function readBalancedParentheses(string $input, int $openOffset): string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($input);

        for ($index = $openOffset; $index < $length; $index++) {
            $character = $input[$index];
            $next = $input[$index + 1] ?? '';
            if ($quote !== null) {
                if ($character === '\\' && $quote !== '`' && $next !== '') {
                    $index++;
                } elseif ($character === $quote) {
                    if ($next === $quote) {
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')' && --$depth === 0) {
                return substr($input, $openOffset + 1, $index - $openOffset - 1);
            }
        }

        throw new RuntimeException('Malformed CREATE TABLE definition in SQL source.');
    }

    private function sqlLiteral(string $value, string $column): ?string
    {
        $value = trim($value);
        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }
        if (strlen($value) >= 2 && in_array($value[0], ["'", '"'], true) && $value[strlen($value) - 1] === $value[0]) {
            $quote = $value[0];
            $inner = substr($value, 1, -1);
            $inner = str_replace($quote.$quote, $quote, $inner);

            return stripcslashes($inner);
        }
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $value)) {
            return $value;
        }

        throw new RuntimeException("Unsupported SQL expression in inventory field [{$column}].");
    }

    private function buildReport(
        string $sourceKind,
        array $tenants,
        array $identifiers,
        array $tenantColumns,
        array $identifierColumns,
        bool $identifierTablePresent,
        DateTimeImmutable $asOf,
    ): array {
        $tenantById = [];
        $legalTypes = ['organization' => 0, 'individual' => 0, 'missing' => 0, 'unsupported' => 0];
        $tenantIdsByType = [];
        $legacyFields = [];

        foreach ($tenantColumns as $column) {
            if (preg_match(self::LEGACY_FIELD_PATTERN, (string) $column) === 1) {
                $legacyFields[$column] = 0;
            }
        }

        foreach ($tenants as $index => $tenant) {
            $tenantId = (string) ($tenant['id'] ?? 'row-'.($index + 1));
            $legalType = strtolower(trim((string) ($tenant['legal_type'] ?? '')));
            if ($legalType === 'organization' || $legalType === 'individual') {
                $legalTypes[$legalType]++;
                $tenantIdsByType[$tenantId] = $legalType;
            } elseif ($legalType === '') {
                $legalTypes['missing']++;
                $tenantIdsByType[$tenantId] = null;
            } else {
                $legalTypes['unsupported']++;
                $tenantIdsByType[$tenantId] = null;
            }
            $tenantById[$tenantId] = $tenant;

            foreach ($legacyFields as $field => $_count) {
                if (isset($tenant[$field]) && trim((string) $tenant[$field]) !== '') {
                    $legacyFields[$field]++;
                }
            }
        }

        $typeStatus = [];
        $expiryCounts = [
            'no_expiry' => 0,
            'current_or_future' => 0,
            'expired' => 0,
            'invalid_date' => 0,
            'field_missing' => 0,
        ];
        $missingNumbers = 0;
        $mismatches = [];
        $seenByTenantAndType = [];
        $loginReadyByTenant = [];
        $duplicates = [];
        $normalizedGroups = [];
        $orphans = 0;

        foreach ($identifiers as $index => $identifier) {
            $tenantId = (string) ($identifier['tenant_id'] ?? '');
            $identifierId = (string) ($identifier['id'] ?? 'row-'.($index + 1));
            $type = strtolower(trim((string) ($identifier['type'] ?? '')));
            $status = strtolower(trim((string) ($identifier['validity_status'] ?? ''))) ?: 'missing';
            $groupKey = $type.'|'.$status;
            $typeStatus[$groupKey] ??= ['type' => $type, 'status' => $status, 'count' => 0];
            $typeStatus[$groupKey]['count']++;

            $expiry = trim((string) ($identifier['expires_at'] ?? ''));
            if (! array_key_exists('expires_at', $identifier)) {
                $expiryCounts['field_missing']++;
            } elseif ($expiry === '') {
                $expiryCounts['no_expiry']++;
            } else {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($expiry, 0, 10));
                if ($date === false || $date->format('Y-m-d') !== substr($expiry, 0, 10)) {
                    $expiryCounts['invalid_date']++;
                } elseif ($date < $asOf) {
                    $expiryCounts['expired']++;
                } else {
                    $expiryCounts['current_or_future']++;
                }
            }
            $number = (string) ($identifier['number'] ?? '');
            $normalized = $this->normalize($number);

            if (! isset($tenantById[$tenantId])) {
                $orphans++;
            }

            $expectedType = match ($tenantIdsByType[$tenantId] ?? null) {
                'organization' => 'cr',
                'individual' => 'qid',
                default => null,
            };
            if (in_array($type, ['cr', 'qid'], true) && $expectedType !== null && $type !== $expectedType) {
                $mismatches[] = [
                    'tenant_id' => $tenantId,
                    'identifier_id' => $identifierId,
                    'stored_type' => $type,
                    'expected_type' => $expectedType,
                    'masked_number' => $this->mask((string) ($identifier['number'] ?? '')),
                ];
            }

            $expectedForTenant = $tenantIdsByType[$tenantId] ?? null;
            if (
                $normalized !== ''
                && $expectedForTenant !== null
                && $type === ($expectedForTenant === 'organization' ? 'cr' : 'qid')
            ) {
                $seenByTenantAndType[$tenantId][$type] = true;
                $isUnexpired = $expiry === '' || (
                    isset($date) && $date instanceof DateTimeImmutable && $date >= $asOf
                );
                if ($status === 'valid' && $isUnexpired && $normalized !== '') {
                    $loginReadyByTenant[$tenantId] = true;
                }
            }

            if ($normalized === '') {
                $missingNumbers++;
                continue;
            }
            $normalizedGroups[$type.'|'.$normalized][] = [
                'tenant_id' => $tenantId,
                'identifier_id' => $identifierId,
                'masked_number' => $this->mask($number),
            ];
        }

        foreach ($normalizedGroups as $groupKey => $records) {
            if (count($records) > 1) {
                [$type] = explode('|', $groupKey, 2);
                $duplicates[] = [
                    'type' => $type,
                    'masked_number' => $records[0]['masked_number'],
                    'count' => count($records),
                    'records' => $records,
                ];
            }
        }

        $missingLoginIdentifier = [];
        $notLoginReady = [];
        $missingLoginCounts = ['cr' => 0, 'qid' => 0];
        $notReadyCounts = ['cr' => 0, 'qid' => 0];
        $unknownLegalTypeTenants = [];
        foreach ($tenantIdsByType as $tenantId => $legalType) {
            if ($legalType === null) {
                $unknownLegalTypeTenants[] = $tenantId;
                continue;
            }
            $expectedType = $legalType === 'organization' ? 'cr' : 'qid';
            if (! isset($seenByTenantAndType[$tenantId][$expectedType])) {
                $missingLoginIdentifier[] = $tenantId;
                $missingLoginCounts[$expectedType]++;
            }
            if (! isset($loginReadyByTenant[$tenantId])) {
                $notLoginReady[] = $tenantId;
                $notReadyCounts[$expectedType]++;
            }
        }

        usort($duplicates, static fn (array $left, array $right): int =>
            [$left['type'], $left['masked_number']] <=> [$right['type'], $right['masked_number']]
        );
        ksort($typeStatus);

        return [
            'report_version' => 1,
            'source' => [
                'kind' => $sourceKind,
                'as_of' => $asOf->format('Y-m-d'),
                'identifier_table_present' => $identifierTablePresent,
                'tenant_id_column_present' => in_array('id', $tenantColumns, true),
                'legal_type_column_present' => in_array('legal_type', $tenantColumns, true),
                'identifier_columns_present' => array_values(array_intersect(
                    ['tenant_id', 'type', 'number', 'validity_status', 'expires_at'],
                    $identifierColumns,
                )),
                'tenant_rows_found_in_source' => count($tenants),
                'identifier_rows_found_in_source' => count($identifiers),
                'dataset_completeness' => 'unknown; row counts describe only the supplied file',
                'evidence_limits' => [
                    'Rows and field values are observed in the supplied local file only.',
                    'File contents do not prove source authenticity, completeness, or current Production state.',
                    'legal_type is a recorded value, not independent evidence of the tenant legal identity.',
                    'Identifier status and expiry are reported from source fields; this tool does not verify external registries or approve identifiers.',
                    'Legacy candidate fields are counted only and are never converted into tenant identifiers.',
                ],
            ],
            'tenants' => [
                'count' => count($tenants),
                'legal_type_counts' => $legalTypes,
                'unknown_legal_type_tenant_ids' => $unknownLegalTypeTenants,
                'legacy_identifier_candidate_fields_populated_tenant_counts' => $legacyFields,
            ],
            'identifiers' => [
                'count' => count($identifiers),
                'by_type_and_status' => array_values($typeStatus),
                'by_expiry' => $expiryCounts,
                'missing_or_empty_number_count' => $missingNumbers,
                'missing_login_identifier_tenant_ids' => $missingLoginIdentifier,
                'missing_login_identifier_counts_by_expected_type' => $missingLoginCounts,
                'no_current_valid_login_identifier_tenant_ids' => $notLoginReady,
                'no_current_valid_login_identifier_counts_by_expected_type' => $notReadyCounts,
                'type_mismatch_records' => $mismatches,
                'duplicate_normalized_numbers' => $duplicates,
                'orphan_identifier_count' => $orphans,
            ],
            'privacy' => [
                'full_identifier_numbers_emitted' => false,
                'mask_format' => '*** plus last two characters where available',
            ],
        ];
    }

    private function normalize(string $number): string
    {
        $number = mb_strtoupper(trim($number));
        $number = strtr($number, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        return preg_replace('/[^A-Z0-9]/u', '', $number) ?? '';
    }

    private function mask(string $number): string
    {
        $normalized = $this->normalize($number);
        if ($normalized === '') {
            return '***';
        }

        return '***'.(strlen($normalized) > 2 ? substr($normalized, -2) : '');
    }
}
