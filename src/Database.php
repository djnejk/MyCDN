<?php

declare(strict_types=1);

namespace MyCDN;

use PDO;
use PDOException;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $db = $this->config['database'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'] ?? 3306,
            $db['name'],
            $db['charset'] ?? 'utf8mb4'
        );

        $password = $db['password'] ?? $db['pass'] ?? '';

        $this->pdo = new PDO($dsn, $db['user'], $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        if (($this->config['app']['auto_migrate'] ?? false) === true) {
            $this->migrate();
        }

        return $this->pdo;
    }

    private function migrate(): void
    {
        $schema = file_get_contents(dirname(__DIR__) . '/schema.sql');
        if ($schema === false) {
            throw new PDOException('Cannot read schema.sql');
        }

        $schema = str_replace('{{prefix}}', $this->tablePrefix(), $schema);
        $this->pdo?->exec($schema);
        $this->ensureColumn($this->tablePrefix() . 'files', 'sha256', 'CHAR(64) NULL AFTER size_bytes');
        $this->ensureColumn($this->tablePrefix() . 'files', 'metadata', 'JSON NULL AFTER sha256');
        $this->ensureColumn($this->tablePrefix() . 'files', 'width', 'INT UNSIGNED NULL AFTER metadata');
        $this->ensureColumn($this->tablePrefix() . 'files', 'height', 'INT UNSIGNED NULL AFTER width');
        $this->ensureColumn($this->tablePrefix() . 'files', 'color_scheme', 'VARCHAR(32) NULL AFTER height');
        $this->ensureColumn($this->tablePrefix() . 'files', 'category', "VARCHAR(100) NOT NULL DEFAULT 'general' AFTER size_bytes");
        $this->ensureIndex($this->tablePrefix() . 'files', 'idx_files_sha256', 'sha256');
        $this->ensureIndex($this->tablePrefix() . 'files', 'idx_files_category', 'category');
        $this->dropColumnIfExists($this->tablePrefix() . 'files', 'url');
    }

    public function tableName(string $name): string
    {
        return '`' . $this->tablePrefix() . $name . '`';
    }

    private function tablePrefix(): string
    {
        $prefix = (string) ($this->config['database']['table_prefix'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
            throw new PDOException('Database table_prefix may contain only letters, numbers and underscores.');
        }

        return $prefix;
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $stmt = $this->pdo?->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->pdo?->quote($column));
        if ($stmt?->fetch() !== false) {
            return;
        }

        try {
            $this->pdo?->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1060) {
                throw $exception;
            }
        }
    }

    private function ensureIndex(string $table, string $index, string $column): void
    {
        $stmt = $this->pdo?->query('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ' . $this->pdo?->quote($index));
        if ($stmt?->fetch() !== false) {
            return;
        }

        try {
            $this->pdo?->exec('ALTER TABLE `' . $table . '` ADD INDEX `' . $index . '` (`' . $column . '`)');
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1061) {
                throw $exception;
            }
        }
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        $stmt = $this->pdo?->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->pdo?->quote($column));
        if ($stmt?->fetch() === false) {
            return;
        }

        try {
            $this->pdo?->exec('ALTER TABLE `' . $table . '` DROP COLUMN `' . $column . '`');
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1091) {
                throw $exception;
            }
        }
    }
}
