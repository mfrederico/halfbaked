<?php

declare(strict_types=1);

namespace HalfBaked\Distillery;

class RepoManager
{
    public function clone(string $url, string $targetDir): void
    {
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $cmd = sprintf(
            'git clone --depth=1 %s %s 2>&1',
            escapeshellarg($url),
            escapeshellarg($targetDir)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to start git clone");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException("git clone failed (exit {$exitCode}): {$stderr}");
        }
    }

    public function isGitUrl(string $url): bool
    {
        // https:// or http://
        if (preg_match('#^https?://.+\..+/.+#', $url)) {
            return true;
        }
        // git@host:user/repo.git
        if (preg_match('#^git@[\w.-]+:.+/.+#', $url)) {
            return true;
        }
        // SCP-style: host.tld:user/repo.git (no git@ prefix)
        if (preg_match('#^[\w][\w.-]*\.[a-z]{2,}:(?!\d).+/.+#', $url)) {
            return true;
        }
        return false;
    }

    public function getRepoName(string $url): string
    {
        // Strip trailing .git
        $url = preg_replace('/\.git$/', '', $url);
        // Strip trailing /
        $url = rtrim($url, '/');
        // Get last path component
        $parts = preg_split('#[/:]#', $url);
        return end($parts) ?: 'unknown';
    }

    public function isLocalPath(string $path): bool
    {
        return is_dir($path);
    }

    public function detectSourceType(string $source): string
    {
        if ($this->isGitUrl($source)) {
            return 'git';
        }
        if ($this->isLocalPath($source)) {
            return 'local';
        }
        throw new \RuntimeException("Source is neither a valid git URL nor a local directory: {$source}");
    }
}
