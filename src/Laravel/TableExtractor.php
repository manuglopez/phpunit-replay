<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/TableExtractor.php
 *
 * Deviation from Pest: no typed class constants (package targets PHP 8.2).
 *
 * Extracts SQL table names either from a raw DML query (`fromSql`, SPEC.md §10) or from a
 * migration file's PHP source (`fromMigrationSource`, SPEC.md §7.2.1 / §10): `Schema::create|
 * table|drop|dropIfExists|dropColumn|dropColumns|rename`, raw `CREATE|ALTER|DROP|TRUNCATE|
 * RENAME TABLE`, `INSERT INTO`, `UPDATE ... SET`, `DELETE FROM`, and `DB::table('x')`.
 */
final class TableExtractor
{
    private const DML_PREFIXES = ['select', 'insert', 'update', 'delete', 'with', 'replace'];

    private const IDENTIFIER = '(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|\w+)';

    /**
     * The table "name" a test file records when one of its statements touches tables nothing
     * can name (`CALL proc()`, `EXEC …`): the rules then treat that file as touching any table.
     */
    public const UNKNOWN = '*';

    /**
     * Prefix of a table a test file recorded while a `migrate` or `db:seed` command ran
     * (`TableTracker`): written into the database every database test of the process runs on.
     */
    public const BOOTSTRAP = '@';

    /** Statements that run code whose tables cannot be read from the statement itself. */
    private const OPAQUE_PREFIXES = ['call', 'exec', 'execute', 'do', 'merge'];

    /** What a from, update, using or truncate list looks for next: a nesting, an item, or its end. */
    private const LIST_TOKEN = '/[(),;]|\b(?:where|inner|left|right|cross|full|natural|join|group|order|limit|having|union|except|intersect|window|offset|fetch|for|returning|on|using|straight_join|set|values|restart|continue|cascade|restrict)\b/i';

    /**
     * @return list<string> Sorted, deduped table names referenced by the query; `[UNKNOWN]` when
     *         they cannot be read. A function the query calls (`select refresh_totals()`) is
     *         not followed: a stored function's tables are a known limit.
     */
    public static function fromSql(string $sql): array
    {
        $sql = self::withoutLeadingComments($sql);

        // `(select …) union (select …)`: Laravel's MySQL and PostgreSQL grammars wrap a union.
        $trimmed = ltrim($sql, " \t\n\r\0\x0B(");

        if ($trimmed === '') {
            return [];
        }

        if (preg_match('/^[a-zA-Z]+/', $trimmed, $prefixMatch) !== 1) {
            return [];
        }

        $prefix = strtolower($prefixMatch[0]);

        if (in_array($prefix, self::OPAQUE_PREFIXES, true)) {
            return [self::UNKNOWN];
        }

        if ($prefix !== 'truncate' && ! in_array($prefix, self::DML_PREFIXES, true)) {
            return [];
        }

        // Matched on a copy whose quoted contents are blanked: a string reading `from posts`
        // is not a table, and a reserved word quoted as a name (`order`) ends no list.
        // Offsets are the same in both, so names are read from the original.
        $masked = self::masked($sql);
        $qualified = '(' . self::IDENTIFIER . '(?:\s*\.\s*' . self::IDENTIFIER . ')*)';
        $names = [];

        if (preg_match_all('/\b(?:into|join)\s+' . $qualified . '/i', $masked, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[1] as [$text, $offset]) {
                $names[] = substr($sql, $offset, strlen($text));
            }
        }

        // Lists: `from a, b x, (select …) y`, `update a, b set`, `delete … using b`, `truncate a, b`.
        if (preg_match_all('/\b(?:from|update|using|truncate(?:\s+table)?)\s+/i', $masked, $starts, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($starts[0] as [$text, $offset]) {
                foreach (self::listItems($masked, $offset + strlen($text)) as [$from, $length]) {
                    $item = substr($sql, $from, $length);

                    if (preg_match('/^\s*' . $qualified . '/', $item, $m) === 1) {
                        $names[] = $m[1];
                    }
                }
            }
        }

        $tables = [];

        foreach ($names as $qualified) {
            $name = self::unqualified($qualified);

            if ($name === '') {
                continue;
            }

            if (self::isSchemaMeta($name)) {
                continue;
            }

            $tables[strtolower($name)] = true;
        }

        $out = array_map(strval(...), array_keys($tables));
        sort($out);

        return $out;
    }

    private static function withoutLeadingComments(string $sql): string
    {
        while (true) {
            $sql = ltrim($sql);

            if (str_starts_with($sql, '/*')) {
                $end = strpos($sql, '*/');
                $sql = $end === false ? '' : substr($sql, $end + 2);

                continue;
            }

            if (str_starts_with($sql, '--')) {
                $end = strpos($sql, "\n");
                $sql = $end === false ? '' : substr($sql, $end + 1);

                continue;
            }

            return $sql;
        }
    }

    /** `$sql` with the contents of every quoted string and identifier replaced by `_`. */
    private static function masked(string $sql): string
    {
        if (strpbrk($sql, '\'"`') === false) {
            return $sql;
        }

        return (string) preg_replace_callback(
            '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'?|"(?:[^"]|"")*"?|`(?:[^`]|``)*`?/s',
            static fn (array $m): string => $m[0][0] . str_repeat('_', max(0, strlen($m[0]) - 2)) . (strlen($m[0]) > 1 ? $m[0][strlen($m[0]) - 1] : ''),
            $sql,
        );
    }

    /**
     * The comma-separated items of a list starting at `$offset` of `$masked`, as offset and
     * length: up to a clause keyword, a `;`, or the `)` closing the list's own parenthesis.
     *
     * @return list<array{int, int}>
     */
    private static function listItems(string $masked, int $offset): array
    {
        $items = [];
        $depth = 0;
        $start = $offset;
        $length = strlen($masked);
        $i = $offset;

        while ($i < $length) {
            if ($depth > 0) {
                $i += strcspn($masked, '()', $i);

                if ($i >= $length) {
                    break;
                }

                $depth += $masked[$i] === '(' ? 1 : -1;
                $i++;

                continue;
            }

            if (preg_match(self::LIST_TOKEN, $masked, $m, PREG_OFFSET_CAPTURE, $i) !== 1) {
                $i = $length;

                break;
            }

            [$token, $at] = $m[0];

            if ($token === '(') {
                $depth++;
                $i = $at + 1;

                continue;
            }

            if ($token === ',') {
                $items[] = [$start, $at - $start];
                $start = $at + 1;
                $i = $start;

                continue;
            }

            // `)`, `;` or a clause keyword: the list ends here.
            $i = $at;

            break;
        }

        $items[] = [$start, $i - $start];

        return $items;
    }

    /** @return list<string> Table names referenced by `Schema::` calls, raw SQL, or `DB::table()`. */
    public static function fromMigrationSource(string $php): array
    {
        return self::migrationTables($php)['tables'];
    }

    /**
     * {@see self::fromMigrationSource()}, and whether the source also names a table this
     * cannot read: `Schema::create($tableNames['roles'], ...)`, `DB::table($table)`, a
     * `config()` call. Such a migration touches tables nobody can name, so what selects by
     * its tables must not narrow by the ones it could read (`Rules\MigrationRule`).
     *
     * @return array{tables: list<string>, unresolved: bool}
     */
    public static function migrationTables(string $php): array
    {
        $tables = [];
        $call = '(?:Schema::\s*|Schema::connection\s*\([^)]*\)\s*->\s*)(?:create|table|drop|dropIfExists|dropColumn|dropColumns|rename)\s*\(\s*';
        $unresolved = preg_match('/' . $call . '(?![\'"])\S/', $php) === 1
            || preg_match('/DB::table\s*\(\s*(?![\'"])\S/', $php) === 1;

        $schemaPattern = '/' . $call . '[\'"]([^\'"]+)[\'"](?:\s*,\s*[\'"]([^\'"]+)[\'"])?/';

        if (preg_match_all($schemaPattern, $php, $matches) !== false) {
            foreach ($matches[1] as $i => $primary) {
                $tables[strtolower(self::lastDottedSegment($primary))] = true;

                $secondary = $matches[2][$i] ?? '';

                if ($secondary !== '') {
                    $tables[strtolower(self::lastDottedSegment($secondary))] = true;
                }
            }
        }

        $qualified = '(' . self::IDENTIFIER . '(?:\s*\.\s*' . self::IDENTIFIER . ')*)';

        $sqlPatterns = [
            '/(?:CREATE|ALTER|DROP|TRUNCATE|RENAME)\s+TABLE(?:\s+IF\s+(?:NOT\s+)?EXISTS)?\s+' . $qualified . '/i',
            '/INSERT\s+(?:IGNORE\s+)?INTO\s+' . $qualified . '/i',
            '/UPDATE\s+' . $qualified . '\s+SET\b/i',
            '/DELETE\s+FROM\s+' . $qualified . '/i',
        ];

        foreach ($sqlPatterns as $pattern) {
            if (preg_match_all($pattern, $php, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $name) {
                $lower = strtolower(self::unqualified($name));

                if ($lower !== '' && ! self::isSchemaMeta($lower)) {
                    $tables[$lower] = true;
                }
            }
        }

        if (preg_match_all('/DB::table\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $php, $matches) !== false) {
            foreach ($matches[1] as $name) {
                // `DB::table('posts as p')`: the table, not the alias.
                $name = (string) preg_replace('/\s+as\s+\w+\s*$/i', '', trim($name));
                $lower = strtolower(self::lastDottedSegment($name));

                if ($lower !== '' && ! self::isSchemaMeta($lower)) {
                    $tables[$lower] = true;
                }
            }
        }

        $out = array_map(strval(...), array_keys($tables));
        sort($out);

        return ['tables' => $out, 'unresolved' => $unresolved];
    }

    private static function unqualified(string $qualified): string
    {
        $name = '';

        foreach (explode('.', $qualified) as $segment) {
            $segment = trim($segment, " \t\n\r\"`[]");

            if ($segment === '') {
                continue;
            }

            if (self::isSchemaMeta($segment)) {
                return '';
            }

            $name = $segment;
        }

        return $name;
    }

    private static function lastDottedSegment(string $name): string
    {
        $position = strrpos($name, '.');

        return $position === false ? $name : substr($name, $position + 1);
    }

    private static function isSchemaMeta(string $name): bool
    {
        $lower = strtolower($name);

        return in_array($lower, ['sqlite_master', 'sqlite_sequence', 'migrations'], true)
            || str_starts_with($lower, 'pg_')
            || str_starts_with($lower, 'information_schema');
    }
}
