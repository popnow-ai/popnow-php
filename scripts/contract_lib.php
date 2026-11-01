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

/**
 * Names that must never appear in this repository: third-party infrastructure and service vendors
 * the public package has no business naming. They are stored as SHA-256 digests of the lower-cased
 * word so that this file does not contain, and therefore does not match, the names it scans for.
 * Add a name with: printf %s name | shasum -a 256
 *
 * tests/unit.php scans every tracked file with these; contract_strip_vendor_lines() drops the
 * same names from the published contract before it is pinned or compared.
 */
const VENDOR_NAME_DIGESTS = [
    '2efa64f91736e51bfbcae85be793635c890cf873727a90a959ad0fbcffafbead',
    'f913085cbacece709571cd312b94473040b3911458ba09fb2b16d1229fe9433f',
    '66f4068e63b8c53e5a3ee2178092043355052454d09fa424f564fd6910084685',
    'b858f760399ed93651e22bac52481458da23ff4c00490531ede9b832992a42fa',
    'afa92e210e352872087e0ee958ff658373593e3d3f86e45d6e9cdb18aa56758a',
    '53d62381341991fc48b998beeef530c8fb036c9539af1d2881fad4794d523313',
    '3d469302580313fbb6d7bfd6bade27401ebcadca8a647fc153dc01303354c30c',
    '4f45d0540bebdfb19c8a9eea90fcb6e3bac6c37c4ccf01b669adbfa713b1345e',
    '9d6b0d69459b31a1c6c47ca7fc8466526c7b9620c76aae6d4d8e83508f713764',
    '5fa956575122f6b93ff58803085b870c524015af26bc8b775a6a5e3d13298793',
    '7ea0a2c0ff0cb88820886758ee1d840e1ca1d56185d0b06ec2342e0f2db17125',
    '2834e64f3da4f1eb41677ad96d9205c4dfe35997ee2df8a3ce7decf90d738a21',
];

/**
 * Every distinct word of $text whose digest is in $digests.
 *
 * @param list<string> $digests
 *
 * @return list<string>
 */
function vendor_name_hits(string $text, array $digests): array
{
    $wanted = array_flip($digests);
    $hits = [];
    preg_match_all('/[a-z0-9]+/', strtolower($text), $words);
    foreach (array_unique($words[0]) as $word) {
        if (isset($wanted[hash('sha256', $word)])) {
            $hits[] = $word;
        }
    }

    return $hits;
}

/**
 * The contract minus every line that names a word in $digests.
 *
 * The published contract lists every callable model identifier in one enum, and a few of those
 * identifiers contain a word the repository hygiene scan forbids. This package hard-codes none of
 * them, so the pinned copy leaves those enum lines out, and the drift check applies the same filter
 * to the live copy so that every other line is still compared byte for byte.
 *
 * @param list<string> $digests
 */
function contract_strip_vendor_lines(string $yaml, array $digests = VENDOR_NAME_DIGESTS): string
{
    $kept = [];
    foreach (explode("\n", $yaml) as $line) {
        if (vendor_name_hits($line, $digests) === []) {
            $kept[] = $line;
        }
    }

    return implode("\n", $kept);
}
