<?php

namespace Everest\Services\Files;

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
     * Maximum number of lines for detailed diff (for performance).
     */
    public const MAX_DIFF_LINES = 5000;

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

        // If too many lines, skip detailed diff
        if (count($originalLines) > self::MAX_DIFF_LINES || count($newLines) > self::MAX_DIFF_LINES) {
            return $this->createLargeDiffSummary($originalContent, $newContent);
        }

        // Calculate the diff using Myers algorithm (simplified LCS-based approach)
        $hunks = $this->computeHunks($originalLines, $newLines);

        // Count additions and deletions
        $additions = 0;
        $deletions = 0;
        foreach ($hunks as $hunk) {
            foreach ($hunk['changes'] as $change) {
                if ($change['type'] === 'addition') {
                    $additions++;
                } elseif ($change['type'] === 'deletion') {
                    $deletions++;
                }
            }
        }

        return [
            'file' => $filename,
            'additions' => $additions,
            'deletions' => $deletions,
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
            'hunks' => [],
            'original_lines' => $originalLines,
            'new_lines' => $newLines,
            'large_file' => true,
        ];
    }

    /**
     * Compute diff hunks between two arrays of lines.
     */
    private function computeHunks(array $oldLines, array $newLines): array
    {
        $lcs = $this->longestCommonSubsequence($oldLines, $newLines);
        $changes = $this->buildChangeList($oldLines, $newLines, $lcs);
        
        return $this->groupChangesIntoHunks($changes, $oldLines, $newLines);
    }

    /**
     * Compute the Longest Common Subsequence between two arrays.
     */
    private function longestCommonSubsequence(array $old, array $new): array
    {
        $oldLen = count($old);
        $newLen = count($new);
        
        // Build LCS length table
        $lengths = array_fill(0, $oldLen + 1, array_fill(0, $newLen + 1, 0));
        
        for ($i = 1; $i <= $oldLen; $i++) {
            for ($j = 1; $j <= $newLen; $j++) {
                if ($old[$i - 1] === $new[$j - 1]) {
                    $lengths[$i][$j] = $lengths[$i - 1][$j - 1] + 1;
                } else {
                    $lengths[$i][$j] = max($lengths[$i - 1][$j], $lengths[$i][$j - 1]);
                }
            }
        }

        // Backtrack to find LCS with positions
        $lcs = [];
        $i = $oldLen;
        $j = $newLen;
        
        while ($i > 0 && $j > 0) {
            if ($old[$i - 1] === $new[$j - 1]) {
                array_unshift($lcs, ['old' => $i - 1, 'new' => $j - 1, 'line' => $old[$i - 1]]);
                $i--;
                $j--;
            } elseif ($lengths[$i - 1][$j] > $lengths[$i][$j - 1]) {
                $i--;
            } else {
                $j--;
            }
        }

        return $lcs;
    }

    /**
     * Build a list of changes (additions, deletions, unchanged) from the LCS.
     */
    private function buildChangeList(array $oldLines, array $newLines, array $lcs): array
    {
        $changes = [];
        $oldIdx = 0;
        $newIdx = 0;
        $lcsIdx = 0;

        while ($oldIdx < count($oldLines) || $newIdx < count($newLines)) {
            if ($lcsIdx < count($lcs)) {
                $lcsItem = $lcs[$lcsIdx];
                
                // Add deletions (lines in old but not in LCS)
                while ($oldIdx < $lcsItem['old']) {
                    $changes[] = [
                        'type' => 'deletion',
                        'content' => $oldLines[$oldIdx],
                        'old_line' => $oldIdx + 1,
                        'new_line' => null,
                    ];
                    $oldIdx++;
                }
                
                // Add additions (lines in new but not in LCS)
                while ($newIdx < $lcsItem['new']) {
                    $changes[] = [
                        'type' => 'addition',
                        'content' => $newLines[$newIdx],
                        'old_line' => null,
                        'new_line' => $newIdx + 1,
                    ];
                    $newIdx++;
                }
                
                // Add unchanged line
                $changes[] = [
                    'type' => 'context',
                    'content' => $lcsItem['line'],
                    'old_line' => $oldIdx + 1,
                    'new_line' => $newIdx + 1,
                ];
                $oldIdx++;
                $newIdx++;
                $lcsIdx++;
            } else {
                // Handle remaining lines after LCS is exhausted
                while ($oldIdx < count($oldLines)) {
                    $changes[] = [
                        'type' => 'deletion',
                        'content' => $oldLines[$oldIdx],
                        'old_line' => $oldIdx + 1,
                        'new_line' => null,
                    ];
                    $oldIdx++;
                }
                while ($newIdx < count($newLines)) {
                    $changes[] = [
                        'type' => 'addition',
                        'content' => $newLines[$newIdx],
                        'old_line' => null,
                        'new_line' => $newIdx + 1,
                    ];
                    $newIdx++;
                }
            }
        }

        return $changes;
    }

    /**
     * Group changes into hunks with context lines.
     */
    private function groupChangesIntoHunks(array $changes, array $oldLines, array $newLines, int $contextLines = 3): array
    {
        if (empty($changes)) {
            return [];
        }

        $hunks = [];
        $currentHunk = null;
        $lastChangeIdx = -1;

        foreach ($changes as $idx => $change) {
            $isChange = $change['type'] !== 'context';
            
            if ($isChange) {
                if ($currentHunk === null) {
                    // Start new hunk with context before
                    $contextStart = max(0, $idx - $contextLines);
                    $currentHunk = [
                        'old_start' => null,
                        'old_lines' => 0,
                        'new_start' => null,
                        'new_lines' => 0,
                        'context' => '',
                        'changes' => [],
                    ];
                    
                    // Add context lines before the change
                    for ($i = $contextStart; $i < $idx; $i++) {
                        $ctx = $changes[$i];
                        if ($currentHunk['old_start'] === null && $ctx['old_line'] !== null) {
                            $currentHunk['old_start'] = $ctx['old_line'];
                        }
                        if ($currentHunk['new_start'] === null && $ctx['new_line'] !== null) {
                            $currentHunk['new_start'] = $ctx['new_line'];
                        }
                        $currentHunk['changes'][] = [
                            'type' => 'context',
                            'content' => $ctx['content'],
                        ];
                        if ($ctx['old_line'] !== null) $currentHunk['old_lines']++;
                        if ($ctx['new_line'] !== null) $currentHunk['new_lines']++;
                    }
                }
                
                // Set start positions if not set
                if ($currentHunk['old_start'] === null) {
                    $currentHunk['old_start'] = $change['old_line'] ?? 1;
                }
                if ($currentHunk['new_start'] === null) {
                    $currentHunk['new_start'] = $change['new_line'] ?? 1;
                }
                
                // Add the change
                $currentHunk['changes'][] = [
                    'type' => $change['type'],
                    'content' => $change['content'],
                ];
                if ($change['type'] === 'deletion') {
                    $currentHunk['old_lines']++;
                } elseif ($change['type'] === 'addition') {
                    $currentHunk['new_lines']++;
                }
                
                $lastChangeIdx = $idx;
            } elseif ($currentHunk !== null) {
                // Context line after a change
                $distanceFromLastChange = $idx - $lastChangeIdx;
                
                if ($distanceFromLastChange <= $contextLines * 2) {
                    // Within context range, add to current hunk
                    $currentHunk['changes'][] = [
                        'type' => 'context',
                        'content' => $change['content'],
                    ];
                    $currentHunk['old_lines']++;
                    $currentHunk['new_lines']++;
                } else {
                    // Too far from last change, close current hunk
                    // But first add trailing context
                    $hunks[] = $currentHunk;
                    $currentHunk = null;
                }
            }
        }

        // Don't forget the last hunk
        if ($currentHunk !== null) {
            $hunks[] = $currentHunk;
        }

        // Ensure all hunks have valid start positions
        foreach ($hunks as &$hunk) {
            if ($hunk['old_start'] === null) $hunk['old_start'] = 1;
            if ($hunk['new_start'] === null) $hunk['new_start'] = 1;
        }

        return $hunks;
    }
}
