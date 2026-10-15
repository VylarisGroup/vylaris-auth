<?php
declare(strict_types=1);

namespace Vylaris\Core;

use PDO;
use PDOStatement;

/** Dünner PDO-Wrapper – ausschließlich Prepared Statements. */
final class Database
{
    public function __construct(private PDO $pdo, private string $driver)
    {
    }

    public static function fromConfig(Config $config): self
    {
        $driver = (string) $config->get('db.driver', 'mysql');
        if ($driver === 'sqlite') {
            $pdo = new PDO('sqlite:' . $config->get('db.path', ':memory:'));
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config->get('db.host'),
                (int) $config->get('db.port', 3306),
                $config->get('db.name'),
                $config->get('db.charset', 'utf8mb4')
            );
            $pdo = new PDO($dsn, (string) $config->get('db.user'), (string) $config->get('db.password'));
            $pdo->exec("SET time_zone = '+00:00'");
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        return new self($pdo, $driver);
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** INSERT und neue ID zurückgeben. */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->ident($table),
            implode(', ', array_map([$this, 'ident'], $cols)),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        $this->run($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /** UPDATE ... WHERE <spalte> = ? ; gibt betroffene Zeilen zurück. */
    public function update(string $table, array $data, string $whereCol, mixed $whereVal): int
    {
        $set = implode(', ', array_map(fn ($c) => $this->ident($c) . ' = ?', array_keys($data)));
        $sql = sprintf('UPDATE %s SET %s WHERE %s = ?', $this->ident($table), $set, $this->ident($whereCol));
        return $this->run($sql, [...array_values($data), $whereVal])->rowCount();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Bezeichner nur aus festem Code, nie aus Benutzereingaben; trotzdem validiert. */
    private function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Ungültiger Bezeichner.');
        }
        return $this->driver === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
    }
}
