<?php

declare(strict_types=1);

namespace HalfBaked\Builder;

use PDO;

class BuildRegistry
{
    private PDO $db;

    public function __construct(string $dbPath = 'data/builder.db')
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
        $this->createTables();
    }

    private function createTables(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS builds (
                id TEXT PRIMARY KEY,
                task TEXT NOT NULL,
                project_path TEXT,
                status TEXT NOT NULL DEFAULT 'scanning',
                decomposer TEXT,
                decomposer_model TEXT,
                subtask_count INTEGER DEFAULT 0,
                completed_count INTEGER DEFAULT 0,
                config TEXT,
                result TEXT,
                error TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS build_subtasks (
                id TEXT PRIMARY KEY,
                build_id TEXT NOT NULL,
                local_id TEXT,
                title TEXT NOT NULL,
                description TEXT,
                domain TEXT,
                expert_model TEXT,
                complexity TEXT DEFAULT 'medium',
                status TEXT NOT NULL DEFAULT 'pending',
                depends_on TEXT,
                work_instructions TEXT,
                acceptance_criteria TEXT,
                generated_code TEXT,
                files TEXT,
                error TEXT,
                sort_order INTEGER DEFAULT 0,
                attempts INTEGER DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (build_id) REFERENCES builds(id)
            )
        SQL);

        // Migration: add attempts column to existing databases
        try {
            $this->db->exec('ALTER TABLE build_subtasks ADD COLUMN attempts INTEGER DEFAULT 0');
        } catch (\PDOException) {
            // Column already exists
        }
    }

    public function createBuild(string $task, string $projectPath, array $config = []): string
    {
        $id = substr(bin2hex(random_bytes(16)), 0, 32);
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO builds (id, task, project_path, status, config, created_at, updated_at)
            VALUES (:id, :task, :project_path, :status, :config, :created_at, :updated_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'task' => $task,
            'project_path' => $projectPath,
            'status' => BuildStatus::Scanning->value,
            'config' => json_encode($config),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    public function getBuild(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM builds WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['config'] = json_decode($row['config'] ?? '{}', true) ?: [];
        $row['result'] = json_decode($row['result'] ?? 'null', true);
        return $row;
    }

    public function listBuilds(): array
    {
        $stmt = $this->db->query('SELECT * FROM builds ORDER BY created_at DESC');
        $rows = $stmt->fetchAll();

        return array_map(function (array $row) {
            $row['config'] = json_decode($row['config'] ?? '{}', true) ?: [];
            $row['result'] = json_decode($row['result'] ?? 'null', true);
            return $row;
        }, $rows);
    }

    public function updateBuild(string $id, array $data): void
    {
        $allowed = [
            'task', 'project_path', 'status', 'decomposer', 'decomposer_model',
            'subtask_count', 'completed_count', 'config', 'result', 'error',
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

        $sql = 'UPDATE builds SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->db->prepare($sql)->execute($params);
    }

    public function deleteBuild(string $id): void
    {
        $this->db->prepare('DELETE FROM build_subtasks WHERE build_id = :build_id')
            ->execute(['build_id' => $id]);
        $this->db->prepare('DELETE FROM builds WHERE id = :id')
            ->execute(['id' => $id]);
    }

    public function createSubtask(string $buildId, array $subtask): string
    {
        $id = substr(bin2hex(random_bytes(16)), 0, 32);
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO build_subtasks (
                id, build_id, local_id, title, description, domain, expert_model,
                complexity, depends_on, work_instructions, acceptance_criteria,
                sort_order, created_at, updated_at
            ) VALUES (
                :id, :build_id, :local_id, :title, :description, :domain, :expert_model,
                :complexity, :depends_on, :work_instructions, :acceptance_criteria,
                :sort_order, :created_at, :updated_at
            )
        SQL);

        $stmt->execute([
            'id' => $id,
            'build_id' => $buildId,
            'local_id' => $subtask['local_id'] ?? null,
            'title' => $subtask['title'],
            'description' => $subtask['description'] ?? null,
            'domain' => $subtask['domain'] ?? null,
            'expert_model' => $subtask['expert_model'] ?? null,
            'complexity' => $subtask['complexity'] ?? 'medium',
            'depends_on' => json_encode($subtask['depends_on'] ?? []),
            'work_instructions' => $subtask['work_instructions'] ?? null,
            'acceptance_criteria' => $subtask['acceptance_criteria'] ?? null,
            'sort_order' => $subtask['sort_order'] ?? 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    public function getSubtask(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM build_subtasks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['depends_on'] = json_decode($row['depends_on'] ?? '[]', true) ?: [];
        $row['files'] = json_decode($row['files'] ?? 'null', true);
        return $row;
    }

    public function getSubtasks(string $buildId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM build_subtasks WHERE build_id = :build_id ORDER BY sort_order'
        );
        $stmt->execute(['build_id' => $buildId]);
        $rows = $stmt->fetchAll();

        return array_map(function (array $row) {
            $row['depends_on'] = json_decode($row['depends_on'] ?? '[]', true) ?: [];
            $row['files'] = json_decode($row['files'] ?? 'null', true);
            return $row;
        }, $rows);
    }

    public function updateSubtask(string $id, array $data): void
    {
        $allowed = [
            'local_id', 'title', 'description', 'domain', 'expert_model',
            'complexity', 'status', 'depends_on', 'work_instructions',
            'acceptance_criteria', 'generated_code', 'files', 'error', 'sort_order', 'attempts',
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

        $sql = 'UPDATE build_subtasks SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $this->db->prepare($sql)->execute($params);
    }

    public function getSubtaskByLocalId(string $buildId, string $localId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM build_subtasks WHERE build_id = :build_id AND local_id = :local_id'
        );
        $stmt->execute(['build_id' => $buildId, 'local_id' => $localId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['depends_on'] = json_decode($row['depends_on'] ?? '[]', true) ?: [];
        $row['files'] = json_decode($row['files'] ?? 'null', true);
        return $row;
    }

    public function refreshCounts(string $buildId): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) as total, SUM(CASE WHEN status = :completed THEN 1 ELSE 0 END) as done FROM build_subtasks WHERE build_id = :build_id'
        );
        $stmt->execute(['build_id' => $buildId, 'completed' => 'completed']);
        $row = $stmt->fetch();

        $this->db->prepare(
            'UPDATE builds SET subtask_count = :subtask_count, completed_count = :completed_count, updated_at = :updated_at WHERE id = :id'
        )->execute([
            'id' => $buildId,
            'subtask_count' => (int) $row['total'],
            'completed_count' => (int) $row['done'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
