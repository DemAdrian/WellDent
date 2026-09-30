<?php

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Keep NOW()/CURDATE() in the clinic's timezone.
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_row(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val(string $sql, array $params = [])
{
    $val = q($sql, $params)->fetchColumn();
    return $val === false ? null : $val;
}

/**
 * Derived table with charged / paid / balance per patient.
 * Join as: JOIN ' . BALANCES . ' b ON b.patient_id = p.id
 * A negative balance is a credit.
 */
const BALANCES = "(SELECT p0.id AS patient_id,
        COALESCE(t0.charged, 0) AS charged,
        COALESCE(y0.paid, 0) AS paid,
        COALESCE(t0.charged, 0) - COALESCE(y0.paid, 0) AS balance
    FROM patients p0
    LEFT JOIN (SELECT patient_id, SUM(amount) AS charged FROM treatments GROUP BY patient_id) t0 ON t0.patient_id = p0.id
    LEFT JOIN (SELECT patient_id, SUM(amount) AS paid FROM payments GROUP BY patient_id) y0 ON y0.patient_id = p0.id)";

function log_activity(string $action, ?string $entity = null, ?int $entityId = null, ?string $details = null): void
{
    q('INSERT INTO activity_log (user_id, action, entity, entity_id, details) VALUES (?, ?, ?, ?, ?)',
        [current_user()['id'] ?? null, $action, $entity, $entityId, $details !== null ? mb_substr($details, 0, 255) : null]);
}
