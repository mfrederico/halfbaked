<?php

declare(strict_types=1);

namespace HalfBaked\Distillery;

use PDO;

class ExpertRegistry
{
    private PDO $db;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->db = new PDO("sqlite:{$dbPath}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->createTable();
    }

    private function createTable(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS experts (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                language TEXT DEFAULT '',
                framework TEXT DEFAULT '',
                source_url TEXT NOT NULL,
                source_type TEXT DEFAULT 'git',
                status TEXT DEFAULT 'pending',
                progress TEXT DEFAULT '{}',
                config TEXT DEFAULT '{}',
                samples_count INTEGER DEFAULT 0,
                dataset_count INTEGER DEFAULT 0,
                training_loss REAL DEFAULT 0,
                gguf_path TEXT DEFAULT '',
                gguf_size INTEGER DEFAULT 0,
                model_size TEXT DEFAULT '7b',
                error TEXT DEFAULT '',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        SQL);
    }

    public function getSetting(string $key, string $default = ''): string
    {
        $stmt = $this->db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    }

    public function setSetting(string $key, string $value): void
    {
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)');
        $stmt->execute(['key' => $key, 'value' => $value]);
    }

    public function getSettings(): array
    {
        $stmt = $this->db->query('SELECT key, value FROM settings');
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['key']] = $row['value'];
        }
        return $settings;
    }

    public function create(string $name, string $source, string $sourceType = 'git', array $config = []): string
    {
        $id = $this->generateUuid();
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO experts (id, name, source_url, source_type, config, created_at, updated_at)
            VALUES (:id, :name, :source_url, :source_type, :config, :created_at, :updated_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'source_url' => $source,
            'source_type' => $sourceType,
            'config' => json_encode($config),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    public function get(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM experts WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['progress'] = json_decode($row['progress'], true) ?: [];
        $row['config'] = json_decode($row['config'], true) ?: [];
        return $row;
    }

    public function list(): array
    {
        $stmt = $this->db->query('SELECT * FROM experts ORDER BY created_at DESC');
        $rows = $stmt->fetchAll();

        return array_map(function (array $row) {
            $row['progress'] = json_decode($row['progress'], true) ?: [];
            $row['config'] = json_decode($row['config'], true) ?: [];
            return $row;
        }, $rows);
    }

    public function update(string $id, array $data): void
    {
        $allowed = [
            'name', 'language', 'framework', 'source_url', 'source_type',
            'status', 'progress', 'config', 'samples_count', 'dataset_count',
            'training_loss', 'gguf_path', 'gguf_size', 'model_size', 'error',
        ];

        $sets = [];
        $params = ['id' => $id];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $sets[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        if (empty($sets)) {
            return;
        }

        $sets[] = "updated_at = :updated_at";
        $params['updated_at'] = date('Y-m-d H:i:s');

        $sql = 'UPDATE experts SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->db->prepare($sql)->execute($params);
    }

    public function delete(string $id): void
    {
        $this->db->prepare('DELETE FROM experts WHERE id = :id')->execute(['id' => $id]);
    }

    public function updateStatus(string $id, ExpertStatus $status, ?string $error = null): void
    {
        $data = ['status' => $status->value];
        if ($error !== null) {
            $data['error'] = $error;
        }
        $this->update($id, $data);
    }

    public function updateProgress(string $id, array $progress): void
    {
        $this->update($id, ['progress' => $progress]);
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
