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

    /** @return list<string> Sorted, deduped table names referenced by the query; `[UNKNOWN]` when they cannot be read. */
    public static function fromSql(string $sql): array
    {
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

        $qualified = '(' . self::IDENTIFIER . '(?:\s*\.\s*' . self::IDENTIFIER . ')*)';

        if ($prefix === 'truncate') {
            return preg_match('/^truncate\s+(?:table\s+)?' . $qualified . '/i', $trimmed, $m) === 1 && self::unqualified($m[1]) !== ''
                ? [strtolower(self::unqualified($m[1]))]
                : [];
        }

        if (! in_array($prefix, self::DML_PREFIXES, true)) {
            return [];
        }

        $names = [];

        if (preg_match_all('/\b(?:from|into|update|join)\s+' . $qualified . '/i', $sql, $matches) !== false) {
            $names = $matches[1];
        }

        // A comma join, `from a, b as x, c`: the from list up to the next clause.
        if (preg_match_all('/\bfrom\s+(.+?)(?=\b(?:where|inner|left|right|cross|full|natural|join|group|order|limit|having|union|except|intersect|window|offset|fetch|for|returning|on|using|straight_join)\b|[();]|$)/is', $sql, $lists) !== false) {
            foreach ($lists[1] as $list) {
                foreach (array_slice(explode(',', $list), 1) as $item) {
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
