<?php

declare(strict_types=1);

namespace Relay\Infrastructure\Persistence;

use PDO;
use Relay\Kernel\Database;
use RuntimeException;

/**
 * Applies the .sql files in database/migrations, once each, in name order.
 *
 * The schema is written once and translated per engine through four
 * placeholders rather than kept as two parallel sets of files. Parallel files
 * drift -- someone adds an index to the MySQL copy, forgets the SQLite one, and
 * the test suite stops exercising the index the production query depends on.
 * The substitution list is deliberately tiny; anything needing more than this
 * belongs in engine-specific code, not in a template.
 */
final class Migrator
{
    private const PLACEHOLDERS = [
        Database::DRIVER_MYSQL => [
            '{{PK}}' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            '{{DATETIME}}' => 'DATETIME(3)',
            '{{JSON}}' => 'JSON',
            '{{ENGINE}}' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        Database::DRIVER_SQLITE => [
            '{{PK}}' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            // SQLite has no date type; ISO-8601 strings sort correctly as text,
            // which is all the queries here need.
            '{{DATETIME}}' => 'TEXT',
            '{{JSON}}' => 'TEXT',
            '{{ENGINE}}' => '',
        ],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath,
    ) {
    }

    /** @return list<string> names of the migrations applied by this call */
    public function migrate(): array
    {
        $this->ensureLedger();

        $applied = $this->appliedNames();
        $ran = [];

        foreach ($this->pending($applied) as $name => $path) {
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException("Cannot read migration '{$path}'.");
            }

            $sql = $this->render($sql);

            // One transaction per migration. MySQL commits DDL implicitly, so
            // this guarantees nothing there -- it matters for SQLite, and it
            // keeps the ledger write next to the change on both.
            Database::transactional($this->pdo, function () use ($sql, $name): void {
                foreach ($this->splitStatements($sql) as $statement) {
                    $this->pdo->exec($statement);
                }

                $insert = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (name, applied_at) VALUES (:name, :at)'
                );
                $insert->execute([':name' => $name, ':at' => gmdate('Y-m-d H:i:s')]);
            });

            $ran[] = $name;
        }

        return $ran;
    }

    private function render(string $sql): string
    {
        $driver = Database::driver($this->pdo);
        $map = self::PLACEHOLDERS[$driver]
            ?? throw new RuntimeException("No migration placeholders for driver '{$driver}'.");

        $rendered = strtr($sql, $map);

        // Catch a placeholder that was added to a migration but never taught to
        // the map -- otherwise it reaches the engine as literal text and the
        // error message points at a syntax error, not at the real cause.
        if (preg_match('/\{\{[A-Z_]+\}\}/', $rendered, $m) === 1) {
            throw new RuntimeException("Unknown placeholder {$m[0]} for driver '{$driver}'.");
        }

        return $rendered;
    }

    /**
     * Split a migration file into statements.
     *
     * Comments are removed BEFORE splitting, not after, and the scanner tracks
     * quoted strings. Both details were learned the hard way from this very
     * schema: a comment reading "a disk fills up at 03:00; the digest still
     * answers" was cut in half by a naive explode on ";", and the engine was
     * handed a CREATE TABLE missing its second half.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $inString = false;
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inString) {
                $current .= $char;
                // '' is an escaped quote inside a SQL string, not the end of one.
                if ($char === "'" && $next === "'") {
                    $current .= $next;
                    $i += 2;
                    continue;
                }
                if ($char === "'") {
                    $inString = false;
                }
                $i++;
                continue;
            }

            if ($char === "'") {
                $inString = true;
                $current .= $char;
                $i++;
                continue;
            }

            // Line comment: drop everything through the newline, keeping the
            // newline so the statement stays readable in an error message.
            if ($char === '-' && $next === '-') {
                $newline = strpos($sql, "
", $i);
                if ($newline === false) {
                    break;
                }
                $current .= "
";
                $i = $newline + 1;
                continue;
            }

            if ($char === ';') {
                $statement = trim($current);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        $statement = trim($current);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    private function ensureLedger(): void
    {
        $map = self::PLACEHOLDERS[Database::driver($this->pdo)];
        $this->pdo->exec(strtr(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'id {{PK}}, name VARCHAR(191) NOT NULL, applied_at {{DATETIME}} NOT NULL,'
            . ' CONSTRAINT uq_schema_migrations_name UNIQUE (name)) {{ENGINE}}',
            $map,
        ));
    }

    /** @return list<string> */
    private function appliedNames(): array
    {
        return $this->pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * @param list<string> $applied
     * @return array<string,string> name => absolute path, sorted by name
     */
    private function pending(array $applied): array
    {
        $files = glob(rtrim($this->migrationsPath, '/\\') . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_STRING);

        $pending = [];
        foreach ($files as $path) {
            $name = basename($path);
            if (!in_array($name, $applied, true)) {
                $pending[$name] = $path;
            }
        }

        return $pending;
    }
}
