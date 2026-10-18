<?php

/**
 * Tiny, dependency-free readers for the pinned OpenAPI contract.
 *
 * Shared by tests/unit.php (offline, against contracts/openapi.yaml) and
 * scripts/check_contract.php (online, against the published contract). This is
 * not a YAML parser: it reads exactly the shapes the contract uses for the
 * enums this package hard-codes, and fails loudly on anything else.
 */

declare(strict_types=1);

/**
 * The enum values of `components.schemas.<schema>.properties.<field>`.
 *
 * Accepts both the block form (`enum:` followed by `- value` lines) and the
 * inline form (`enum: [a, b]`). Returns the values sorted, or null when the
 * schema, the field or its enum cannot be found.
 *
 * @return list<string>|null
 */
function contract_enum(string $yaml, string $schema, string $field): ?array
{
    $lines = preg_split('/\R/', $yaml) ?: [];
    $indent = static fn (string $line): int => strlen($line) - strlen(ltrim($line, ' '));

    // 1. The schema block: "    <Schema>:" under components.schemas, up to the next line at the same depth.
    $start = null;
    foreach ($lines as $index => $line) {
        if ($line === '    ' . $schema . ':') {
            $start = $index;
            break;
        }
    }
    if ($start === null) {
        return null;
    }
    $block = [];
    for ($i = $start + 1, $n = count($lines); $i < $n; $i++) {
        if (trim($lines[$i]) !== '' && $indent($lines[$i]) <= 4) {
            break;
        }
        $block[] = $lines[$i];
    }

    // 2. The field inside the schema's properties.
    $fieldAt = null;
    foreach ($block as $index => $line) {
        if (preg_match('/^(\s+)' . preg_quote($field, '/') . ':\s*(\{.*)?$/', $line) === 1) {
            $fieldAt = $index;
            break;
        }
    }
    if ($fieldAt === null) {
        return null;
    }
    $fieldIndent = $indent($block[$fieldAt]);

    // Inline flow mapping on the field line itself: `field: { type: string, enum: [a, b] }`.
    if (preg_match('/enum:\s*\[([^\]]+)\]/', $block[$fieldAt], $m) === 1) {
        return contract_sorted(explode(',', $m[1]));
    }

    // 3. The enum inside the field's block.
    for ($i = $fieldAt + 1, $n = count($block); $i < $n; $i++) {
        $line = $block[$i];
        if (trim($line) !== '' && $indent($line) <= $fieldIndent) {
            break;
        }
        if (preg_match('/^\s+enum:\s*\[([^\]]+)\]\s*$/', $line, $m) === 1) {
            return contract_sorted(explode(',', $m[1]));
        }
        if (preg_match('/^\s+enum:\s*$/', $line) === 1) {
            $enumIndent = $indent($line);
            $values = [];
            for ($j = $i + 1; $j < $n; $j++) {
                if (preg_match('/^(\s+)-\s+(.+)$/', $block[$j], $item) === 1 && strlen($item[1]) >= $enumIndent) {
                    $values[] = $item[2];
                    continue;
                }
                break;
            }

            return $values === [] ? null : contract_sorted($values);
        }
    }

    return null;
}

/**
 * @param list<string> $values
 *
 * @return list<string>
 */
function contract_sorted(array $values): array
{
    $clean = array_map(static fn (string $v): string => trim(trim($v), "'\""), $values);
    sort($clean);

    return array_values($clean);
}

/**
 * The published contract minus its leading generated-header comment lines,
 * which is exactly what contracts/openapi.yaml pins.
 */
function contract_strip_header(string $yaml): string
{
    return (string) preg_replace('/\A(?:#[^\n]*\n)+/', '', $yaml);
}
