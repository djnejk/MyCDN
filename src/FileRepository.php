<?php

declare(strict_types=1);

namespace MyCDN;

use PDO;

final class FileRepository
{
    private readonly string $table;

    public function __construct(private readonly PDO $pdo, string $tablePrefix = '')
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/', $tablePrefix)) {
            throw new \InvalidArgumentException('Database table prefix may contain only letters, numbers and underscores.');
        }

        $this->table = '`' . $tablePrefix . 'files`';
    }

    public function create(array $file): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $this->table . '
                (uid, original_name, stored_name, relative_path, mime_type, extension, size_bytes, sha256, metadata, width, height, color_scheme, category, alt_text, uploaded_by, source_ip)
             VALUES
                (:uid, :original_name, :stored_name, :relative_path, :mime_type, :extension, :size_bytes, :sha256, :metadata, :width, :height, :color_scheme, :category, :alt_text, :uploaded_by, :source_ip)'
        );
        $stmt->execute([
            ':uid' => $file['uid'],
            ':original_name' => $file['original_name'],
            ':stored_name' => $file['stored_name'],
            ':relative_path' => $file['relative_path'],
            ':mime_type' => $file['mime_type'],
            ':extension' => $file['extension'],
            ':size_bytes' => $file['size_bytes'],
            ':sha256' => $file['sha256'],
            ':metadata' => $file['metadata'],
            ':width' => $file['width'],
            ':height' => $file['height'],
            ':color_scheme' => $file['color_scheme'],
            ':category' => $file['category'],
            ':alt_text' => $file['alt_text'],
            ':uploaded_by' => $file['uploaded_by'],
            ':source_ip' => $file['source_ip'],
        ]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? $file;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->table . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $file = $stmt->fetch();

        return $file ?: null;
    }

    public function findByUid(string $uid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->table . ' WHERE uid = :uid LIMIT 1');
        $stmt->execute([':uid' => $uid]);
        $file = $stmt->fetch();

        return $file ?: null;
    }

    public function list(int $limit = 50, int $offset = 0, ?string $query = null, ?string $category = null): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $where = [];
        $params = [];

        if ($query !== null && trim($query) !== '') {
            $where[] = '(uid LIKE :query OR sha256 LIKE :query OR original_name LIKE :query OR alt_text LIKE :query OR category LIKE :query)';
            $params[':query'] = '%' . $query . '%';
        }

        if ($category !== null && trim($category) !== '') {
            $where[] = 'category = :category';
            $params[':category'] = $category;
        }

        $sql = 'SELECT * FROM ' . $this->table;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function categories(): array
    {
        $stmt = $this->pdo->query(
            'SELECT category, COUNT(*) AS file_count
             FROM ' . $this->table . '
             GROUP BY category
             ORDER BY category ASC'
        );

        return $stmt->fetchAll();
    }

    public function updateMetadata(int $id, string $category, ?string $altText): ?array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->table . '
             SET category = :category, alt_text = :alt_text
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':category' => $category,
            ':alt_text' => $altText,
        ]);

        return $this->findById($id);
    }

    public function delete(int $id): ?array
    {
        $file = $this->findById($id);
        if ($file === null) {
            return null;
        }

        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->table . ' WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $file;
    }
}
