<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

/**
 * A `php artisan schema:dump` file (`database/schema/{connection}-schema.sql`, or `.dump`),
 * split into what `Rules\SchemaDumpRule` diffs and `Select\NonEdgeInputs` hashes: one block
 * per table, the statements that belong to no table, and the `migrations` rows.
 *
 * `RefreshDatabase` loads the dump of the test connection once per database before running
 * the migrations its `migrations` rows do not list, so coverage never credits it to a test
 * and it is not a migration file. A table's block is every statement about it, in file
 * order: `CREATE TABLE`, `DROP TABLE` (MySQL writes one before each create), `ALTER TABLE`
 * (PostgreSQL constraints and defaults), `CREATE INDEX ... ON`, `CREATE TRIGGER ... ON`,
 * `COMMENT ON TABLE|COLUMN`, a view's `CREATE VIEW`, and a PostgreSQL sequence its
 * `ALTER SEQUENCE ... OWNED BY` gives to the table. Dialects, as Laravel writes them:
 *
 * - MySQL / MariaDB (`mysqldump --routines --no-data`, then the `migrations` rows as
 *   `INSERT INTO`): backquoted names, `/*!NNNNN ...*\/` executable comments (their content is
 *   kept), `DELIMITER` blocks for triggers and routines, backslash escapes in strings;
 * - PostgreSQL (`pg_dump --schema-only`, then `-t migrations --data-only`): schema-qualified
 *   names, `$tag$` quoting, psql `\restrict` lines (a random key on every dump), and the rows
 *   as `COPY ... FROM stdin` data;
 * - SQLite (`.schema --indent`, then the `INSERT` lines of `.dump migrations`): quoted names,
 *   `CREATE TRIGGER ... BEGIN ...; END;`.
 *
 * Whitespace outside quotes and comments do not count, so re-dumping an unchanged schema
 * changes no block. Noise counts nowhere: `SET`, `LOCK`/`UNLOCK TABLES`, `PRAGMA`,
 * `BEGIN`/`COMMIT`, `SELECT pg_catalog.set_config|setval(...)`. Anything else (a function,
 * a type, an extension, an unowned sequence) is "global": no table can claim it.
 *
 * {@see self::parse()} returns null rather than guess: an unterminated string, comment or
 * dollar quote, a statement without its terminator, a `CREATE TABLE` without a readable name,
 * a binary `pg_dump -Fc` archive, or a file with no table at all.
 */
final readonly class SchemaDump
{
    private const QUALIFIED = '((?:"(?:[^"]|"")+"|`(?:[^`]|``)+`|\[[^\]]+\]|[\w$]+)(?:\s*\.\s*(?:"(?:[^"]|"")+"|`(?:[^`]|``)+`|\[[^\]]+\]|[\w$]+))*)';

    /** What the tokenizer looks at one character at a time; anything else is copied in runs. */
    private const SPECIAL = " \t\r\n\f\v-/*'\"`\$";

    private const NOISE = '/^(?:SET\b|LOCK\s+TABLES?\b|UNLOCK\s+TABLES?\b|START\s+TRANSACTION\b|BEGIN(?:\s+TRANSACTION)?$|COMMIT$|PRAGMA\b|USE\b|SELECT\s+pg_catalog\.(?:set_config|setval)\s*\()/i';

    /**
     * @param array<string, string> $blocks lowercased table => its statements, normalised
     * @param list<string>|null $migrations the `migrations` rows' names, null when none could be read
     */
    private function __construct(
        private array $blocks,
        private string $global,
        private ?array $migrations,
    ) {
    }

    /**
     * Where Laravel reads and writes a schema dump: `database/schema/{connection}-schema.sql`,
     * and `.dump` (a `pg_dump` archive, which `migrate` prefers when both exist).
     */
    public static function isDumpPath(string $rel): bool
    {
        return preg_match('#^database/schema/[^/]+-schema\.(?:sql|dump)$#', $rel) === 1;
    }

    /** A dump that does not exist (deleted): no table, no statement, no rows. */
    public static function none(): self
    {
        return new self([], '', null);
    }

    public static function parse(string $sql): ?self
    {
        if ($sql === '' || str_starts_with($sql, 'PGDMP') || str_contains($sql, "\0")) {
            return null;
        }

        $statements = self::statements($sql, str_contains($sql, '`'));

        if ($statements === null) {
            return null;
        }

        /** @var array<string, list<string>> $byTable */
        $byTable = [];
        /** @var array<string, list<string>> $bySequence */
        $bySequence = [];
        /** @var array<string, string> $sequenceOwner */
        $sequenceOwner = [];
        $global = [];
        $migrations = null;

        foreach ($statements as [$statement, $copyRows]) {
            if (preg_match(self::NOISE, $statement) === 1) {
                continue;
            }

            if (preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+' . self::QUALIFIED . '/i', $statement, $m) === 1) {
                $table = self::name($m[1]);

                if ($table === 'migrations') {
                    $migrations = [...$migrations ?? [], ...self::insertedNames($statement)];

                    continue;
                }

                $byTable[$table][] = $statement;

                continue;
            }

            if (preg_match('/^COPY\s+' . self::QUALIFIED . '\s*(\(([^)]*)\))?\s+FROM\s+stdin$/i', $statement, $m) === 1) {
                $table = self::name($m[1]);

                if ($table === 'migrations') {
                    $migrations = [...$migrations ?? [], ...self::copiedNames($m[3] ?? '', $copyRows)];

                    continue;
                }

                $byTable[$table][] = $statement . "\n" . implode("\n", $copyRows);

                continue;
            }

            $table = self::tableOf($statement);

            if ($table === false) {
                return null;
            }

            if ($table !== null) {
                $byTable[$table][] = $statement;

                continue;
            }

            if (preg_match('/^(?:CREATE|ALTER)\s+SEQUENCE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . self::QUALIFIED . '(.*)$/is', $statement, $m) === 1) {
                $sequence = self::name($m[1]);
                $bySequence[$sequence][] = $statement;

                if (preg_match('/\bOWNED\s+BY\s+' . self::QUALIFIED . '/i', $m[2], $owner) === 1) {
                    $segments = self::segments($owner[1]);

                    if (count($segments) >= 2) {
                        $sequenceOwner[$sequence] = $segments[count($segments) - 2];
                    }
                }

                continue;
            }

            $global[] = $statement;
        }

        // A sequence is its owner table's; one nothing owns belongs to no table.
        foreach ($bySequence as $sequence => $sequenceStatements) {
            $owner = $sequenceOwner[$sequence] ?? null;

            if ($owner !== null) {
                $byTable[$owner] = [...$sequenceStatements, ...$byTable[$owner] ?? []];
            } else {
                array_push($global, ...$sequenceStatements);
            }
        }

        if ($byTable === []) {
            return null;
        }

        $blocks = [];

        foreach ($byTable as $table => $tableStatements) {
            $blocks[(string) $table] = implode("\n", $tableStatements);
        }

        ksort($blocks, SORT_STRING);

        return new self($blocks, implode("\n", $global), $migrations);
    }

    /**
     * The tables whose block differs between two dumps, added and removed ones included.
     *
     * @return list<string>
     */
    public static function changedTables(self $old, self $new): array
    {
        $changed = [];

        foreach ([...array_keys($old->blocks), ...array_keys($new->blocks)] as $table) {
            if (($old->blocks[$table] ?? null) !== ($new->blocks[$table] ?? null)) {
                $changed[(string) $table] = true;
            }
        }

        $names = array_map(strval(...), array_keys($changed));
        sort($names);

        return $names;
    }

    public static function globalChanged(self $old, self $new): bool
    {
        return $old->global !== $new->global;
    }

    /** @return list<string> sorted, lowercased */
    public function tables(): array
    {
        return array_map(strval(...), array_keys($this->blocks));
    }

    public function block(string $table): string
    {
        return $this->blocks[strtolower($table)] ?? '';
    }

    /** The statements no table can claim, normalised; '' when there are none. */
    public function global(): string
    {
        return $this->global;
    }

    /** @return list<string>|null the migration names the `migrations` rows list, null when none could be read */
    public function migrations(): ?array
    {
        return $this->migrations;
    }

    /**
     * The table a statement is about: a name, null for a statement about no table, false for
     * a `CREATE TABLE` (or `ALTER TABLE`) whose name cannot be read.
     */
    private static function tableOf(string $statement): string|false|null
    {
        $patterns = [
            '/^CREATE\s+(?:OR\s+REPLACE\s+)?(?:(?:GLOBAL|LOCAL)\s+)?(?:TEMP(?:ORARY)?\s+|UNLOGGED\s+|FOREIGN\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . self::QUALIFIED . '/i',
            '/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?' . self::QUALIFIED . '/i',
            '/^ALTER\s+TABLE\s+(?:ONLY\s+)?(?:IF\s+EXISTS\s+)?(?:ONLY\s+)?' . self::QUALIFIED . '/i',
            '/^CREATE\s+(?:UNIQUE\s+)?INDEX\b.*?\sON\s+(?:ONLY\s+)?' . self::QUALIFIED . '/is',
            '/^CREATE\s+(?:OR\s+REPLACE\s+)?(?:DEFINER\s*=\s*\S+\s+)?(?:TEMP(?:ORARY)?\s+)?(?:CONSTRAINT\s+)?TRIGGER\s+(?:IF\s+NOT\s+EXISTS\s+)?' . self::QUALIFIED . '.*?\sON\s+' . self::QUALIFIED . '/is',
            '/^CREATE\s+(?:OR\s+REPLACE\s+)?(?:(?:ALGORITHM|DEFINER)\s*=\s*\S+\s+|SQL\s+SECURITY\s+\w+\s+|MATERIALIZED\s+|TEMP(?:ORARY)?\s+|RECURSIVE\s+)*VIEW\s+(?:IF\s+NOT\s+EXISTS\s+)?' . self::QUALIFIED . '/i',
            '/^COMMENT\s+ON\s+TABLE\s+' . self::QUALIFIED . '/i',
        ];

        foreach ($patterns as $i => $pattern) {
            if (preg_match($pattern, $statement, $m) === 1) {
                // The trigger pattern captures the trigger's name first, then its table.
                return self::name($i === 4 ? ($m[2] ?? '') : $m[1]);
            }
        }

        if (preg_match('/^COMMENT\s+ON\s+COLUMN\s+' . self::QUALIFIED . '/i', $statement, $m) === 1) {
            $segments = self::segments($m[1]);

            return count($segments) >= 2 ? $segments[count($segments) - 2] : false;
        }

        // A CREATE/ALTER/DROP TABLE no pattern above could read a name from.
        if (preg_match('/^(?:CREATE|ALTER|DROP)\s+(?:\w+\s+)*?TABLE\b/i', $statement) === 1) {
            return false;
        }

        return null;
    }

    /**
     * Statements in file order, each normalised (comments dropped, whitespace outside quotes
     * collapsed, MySQL executable-comment markers removed), with the data lines of a `COPY
     * ... FROM stdin`. Null on anything left open at the end of the file.
     *
     * @return list<array{string, list<string>}>|null
     */
    private static function statements(string $sql, bool $backslashEscapes): ?array
    {
        $length = strlen($sql);
        $statements = [];
        $current = '';
        $delimiter = ';';
        $conditional = 0;
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $atLineStart = $i === 0 || $sql[$i - 1] === "\n";

            // psql meta-commands (`\restrict <key>`) and `DELIMITER`, between statements only.
            if ($atLineStart && $current === '') {
                $lineEnd = strpos($sql, "\n", $i);
                $line = substr($sql, $i, $lineEnd === false ? null : $lineEnd - $i);
                $trimmed = ltrim($line);

                if (str_starts_with($trimmed, '\\')) {
                    $i = $lineEnd === false ? $length : $lineEnd + 1;

                    continue;
                }

                if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', $trimmed, $m) === 1) {
                    $delimiter = $m[1];
                    $i = $lineEnd === false ? $length : $lineEnd + 1;

                    continue;
                }
            }

            // A run of characters none of the cases below cares about, in one step.
            $plain = strcspn($sql, self::SPECIAL . $delimiter[0], $i);

            if ($plain > 0) {
                $current .= substr($sql, $i, $plain);
                $i += $plain;

                continue;
            }

            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $lineEnd = strpos($sql, "\n", $i);
                $i = $lineEnd === false ? $length : $lineEnd;
                $current = self::spaced($current);

                continue;
            }

            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                if (($sql[$i + 2] ?? '') === '!') {
                    // MySQL executable comment: its content is SQL, the marker is not.
                    $i += 3;

                    while ($i < $length && ctype_digit($sql[$i])) {
                        $i++;
                    }

                    $conditional++;
                    $current = self::spaced($current);

                    continue;
                }

                $end = strpos($sql, '*/', $i + 2);

                if ($end === false) {
                    return null;
                }

                $i = $end + 2;
                $current = self::spaced($current);

                continue;
            }

            if ($char === '*' && $conditional > 0 && ($sql[$i + 1] ?? '') === '/') {
                $conditional--;
                $i += 2;
                $current = self::spaced($current);

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $end = self::quoteEnd($sql, $i, $char, $backslashEscapes && $char === "'");

                if ($end === null) {
                    return null;
                }

                $current .= substr($sql, $i, $end - $i + 1);
                $i = $end + 1;

                continue;
            }

            if ($char === '$' && ($i === 0 || ! self::isWordChar($sql[$i - 1])) && preg_match('/\G\$([A-Za-z_]\w*)?\$/', $sql, $m, 0, $i) === 1) {
                $tag = $m[0];
                $end = strpos($sql, $tag, $i + strlen($tag));

                if ($end === false) {
                    return null;
                }

                $current .= substr($sql, $i, $end + strlen($tag) - $i);
                $i = $end + strlen($tag);

                continue;
            }

            if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                $i += strlen($delimiter);
                $statement = trim($current);

                // A SQLite trigger's body has statements of its own: it ends at `END;`.
                if ($delimiter === ';' && preg_match('/^CREATE\s+(?:TEMP(?:ORARY)?\s+)?TRIGGER\b/i', $statement) === 1 && preg_match('/\bBEGIN\b/i', $statement) === 1 && preg_match('/\bEND$/i', $statement) !== 1) {
                    $current .= ';';

                    continue;
                }

                $current = '';

                if ($statement === '') {
                    continue;
                }

                $rows = [];

                if (preg_match('/^COPY\b.*\bFROM\s+stdin$/is', $statement) === 1) {
                    $lineEnd = strpos($sql, "\n", $i);
                    $i = $lineEnd === false ? $length : $lineEnd + 1;
                    $closed = false;

                    while ($i < $length) {
                        $lineEnd = strpos($sql, "\n", $i);
                        $line = rtrim(substr($sql, $i, $lineEnd === false ? null : $lineEnd - $i), "\r");
                        $i = $lineEnd === false ? $length : $lineEnd + 1;

                        if ($line === '\\.') {
                            $closed = true;

                            break;
                        }

                        $rows[] = $line;
                    }

                    if (! $closed) {
                        return null;
                    }
                }

                $statements[] = [$statement, $rows];

                continue;
            }

            if (ctype_space($char)) {
                $current = self::spaced($current);
                $i++;

                continue;
            }

            $current .= $char;
            $i++;
        }

        if (trim($current) !== '' || $conditional > 0) {
            return null;
        }

        return $statements;
    }

    /** One space between tokens, never a leading or a doubled one. */
    private static function spaced(string $current): string
    {
        return ($current === '' || str_ends_with($current, ' ')) ? $current : $current . ' ';
    }

    /** The offset of the quote closing the one at `$start`, or null when it never closes. */
    private static function quoteEnd(string $sql, int $start, string $quote, bool $backslashEscapes): ?int
    {
        $length = strlen($sql);
        $i = $start + 1;

        while ($i < $length) {
            $char = $sql[$i];

            if ($backslashEscapes && $char === '\\') {
                $i += 2;

                continue;
            }

            if ($char === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;

                    continue;
                }

                return $i;
            }

            $i++;
        }

        return null;
    }

    private static function isWordChar(string $char): bool
    {
        return ctype_alnum($char) || $char === '_' || $char === '$';
    }

    /** @return list<string> */
    private static function insertedNames(string $statement): array
    {
        $values = stristr($statement, 'VALUES');

        if ($values === false || preg_match_all("/'((?:[^'\\\\]|''|\\\\.)*)'/", $values, $m) === false) {
            return [];
        }

        return array_map(static fn (string $name): string => str_replace(["''", "\\'"], "'", $name), $m[1]);
    }

    /**
     * @param list<string> $rows tab-separated COPY data
     * @return list<string>
     */
    private static function copiedNames(string $columns, array $rows): array
    {
        $index = 1;

        if (trim($columns) !== '') {
            $names = array_map(static fn (string $column): string => strtolower(trim($column, " \t\"")), explode(',', $columns));
            $found = array_search('migration', $names, true);
            $index = is_int($found) ? $found : 1;
        }

        $out = [];

        foreach ($rows as $row) {
            $fields = explode("\t", $row);

            if (isset($fields[$index]) && $fields[$index] !== '') {
                $out[] = $fields[$index];
            }
        }

        return $out;
    }

    /** The unqualified, unquoted, lowercased last segment of a qualified name. */
    private static function name(string $qualified): string
    {
        $segments = self::segments($qualified);

        return $segments === [] ? '' : $segments[count($segments) - 1];
    }

    /** @return list<string> */
    private static function segments(string $qualified): array
    {
        preg_match_all('/"(?:[^"]|"")+"|`(?:[^`]|``)+`|\[[^\]]+\]|[\w$]+/', $qualified, $m);

        return array_map(
            static fn (string $segment): string => strtolower(trim($segment, '"`[]')),
            $m[0],
        );
    }
}
