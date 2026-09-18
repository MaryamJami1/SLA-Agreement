<?php
/**
 * Database access: one PDO connection per request, and a transaction helper that retries a
 * deadlock once (plan Section 9).
 */
declare(strict_types=1);

/** Open a connection with the plan's settings (exceptions, real prepares, utf8mb4, +05:00, strict mode). */
function db_connect(array $c): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['DB_HOST'], (int) $c['DB_PORT'], $c['DB_NAME']);
    $pdo = new PDO($dsn, $c['DB_USER'], $c['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+05:00'");
    // Strict mode: out-of-range or truncated values are errors, never silently changed.
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    return $pdo;
}

/** The request's shared connection. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db_connect($GLOBALS['APP_CONFIG']);
    }
    return $pdo;
}

/**
 * Run $work($pdo) in one transaction and commit. Any exception rolls back and is rethrown.
 * A deadlock (MySQL error 1213) is retried once; a second deadlock throws TryAgainException.
 * $work must be safe to run twice (it re-reads everything under its own locks).
 */
function db_transaction(callable $work, ?PDO $pdo = null)
{
    $pdo = $pdo ?? db();
    for ($attempt = 1; ; $attempt++) {
        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $isDeadlock = $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1213;
            if (!$isDeadlock) {
                throw $e;
            }
            if ($attempt >= 2) {
                throw new TryAgainException('The system was busy with another change to the same record. Please try again.', 0, $e);
            }
        }
    }
}
