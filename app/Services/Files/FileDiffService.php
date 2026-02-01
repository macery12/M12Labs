<?php

namespace Everest\Services\Files;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

class FileDiffService
{
    /**
     * Text file extensions that support diff comparison.
     */
    public const TEXT_EXTENSIONS = [
        'txt', 'md', 'markdown', 'json', 'yaml', 'yml', 'xml',
        'html', 'htm', 'css', 'scss', 'sass', 'less',
        'js', 'jsx', 'ts', 'tsx', 'mjs', 'cjs',
        'php', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'hpp',
        'cs', 'go', 'rs', 'swift', 'kt', 'scala',
        'sh', 'bash', 'zsh', 'fish', 'ps1', 'bat', 'cmd',
        'sql', 'lua', 'pl', 'pm', 'r', 'R',
        'toml', 'ini', 'cfg', 'conf', 'config', 'env', 'properties',
        'htaccess', 'gitignore', 'dockerignore', 'editorconfig',
        'vue', 'svelte', 'astro',
        'log', 'csv', 'tsv',
        'Dockerfile', 'Makefile', 'Rakefile', 'Gemfile',
        'gradle', 'sbt', 'pom',
    ];

    /**
     * Maximum file size in bytes for diff calculation (1MB).
     */
    public const MAX_DIFF_SIZE = 1048576;

    /**
     * Check if a file is a text file based on its extension.
     */
    public function isTextFile(string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $basename = basename($filename);

        // Check if it's a common config file without extension
        if (in_array($basename, ['Dockerfile', 'Makefile', 'Rakefile', 'Gemfile', '.env', '.gitignore', '.dockerignore', '.editorconfig', '.htaccess', '.pteroignore'])) {
            return true;
        }

        return in_array($extension, self::TEXT_EXTENSIONS);
    }

    /**
     * Calculate the diff between original and new content.
     */
    public function calculateDiff(string $originalContent, string $newContent, string $filename): array
    {
        // If either content is too large, skip detailed diff
        if (strlen($originalContent) > self::MAX_DIFF_SIZE || strlen($newContent) > self::MAX_DIFF_SIZE) {
            return $this->createLargeDiffSummary($originalContent, $newContent);
        }

        $originalLines = explode("\n", $originalContent);
        $newLines = explode("\n", $newContent);

        $builder = new UnifiedDiffOutputBuilder(
            "--- a/{$filename}\n+++ b/{$filename}\n",
            true
        );
        $differ = new Differ($builder);

        $diff = $differ->diff($originalContent, $newContent);

        // Calculate additions and deletions
        $additions = 0;
        $deletions = 0;

        $diffLines = explode("\n", $diff);
        foreach ($diffLines as $line) {
            if (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
                $additions++;
            } elseif (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                $deletions++;
            }
        }

        // Create a summary of changes
        $hunks = $this->parseHunks($diff);

        return [
            'file' => $filename,
            'additions' => $additions,
            'deletions' => $deletions,
            'diff' => $diff,
            'hunks' => $hunks,
            'original_lines' => count($originalLines),
            'new_lines' => count($newLines),
            'is_new_file' => empty(trim($originalContent)),
        ];
    }

    /**
     * Create a summary for large files where detailed diff is not practical.
     */
    private function createLargeDiffSummary(string $originalContent, string $newContent): array
    {
        $originalLines = substr_count($originalContent, "\n") + 1;
        $newLines = substr_count($newContent, "\n") + 1;

        return [
            'additions' => max(0, $newLines - $originalLines),
            'deletions' => max(0, $originalLines - $newLines),
            'diff' => null,
            'hunks' => [],
            'original_lines' => $originalLines,
            'new_lines' => $newLines,
            'large_file' => true,
        ];
    }

    /**
     * Parse diff output into hunks for easier frontend rendering.
     */
    private function parseHunks(string $diff): array
    {
        $lines = explode("\n", $diff);
        $hunks = [];
        $currentHunk = null;

        foreach ($lines as $line) {
            // Skip header lines
            if (str_starts_with($line, '---') || str_starts_with($line, '+++')) {
                continue;
            }

            // New hunk
            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@(.*)$/', $line, $matches)) {
                if ($currentHunk !== null) {
                    $hunks[] = $currentHunk;
                }

                $currentHunk = [
                    'old_start' => (int) $matches[1],
                    'old_lines' => isset($matches[2]) ? (int) $matches[2] : 1,
                    'new_start' => (int) $matches[3],
                    'new_lines' => isset($matches[4]) ? (int) $matches[4] : 1,
                    'context' => trim($matches[5] ?? ''),
                    'changes' => [],
                ];
                continue;
            }

            if ($currentHunk !== null) {
                $type = 'context';
                $content = $line;

                if (str_starts_with($line, '+')) {
                    $type = 'addition';
                    $content = substr($line, 1);
                } elseif (str_starts_with($line, '-')) {
                    $type = 'deletion';
                    $content = substr($line, 1);
                } elseif (str_starts_with($line, ' ')) {
                    $content = substr($line, 1);
                }

                $currentHunk['changes'][] = [
                    'type' => $type,
                    'content' => $content,
                ];
            }
        }

        if ($currentHunk !== null) {
            $hunks[] = $currentHunk;
        }

        return $hunks;
    }
}
