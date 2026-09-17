<?php

$revision = 'HEAD';
$output = null;
$force = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php create-qa-archive.php [revision] [output.zip] [--force]\n";
        echo "  revision: Git revision to archive (default: HEAD)\n";
        echo "  output.zip: Destination path (default: phase-qa-archive-<timestamp>.zip)\n";
        echo "  --force: Overwrite output if it exists\n";
        exit(0);
    } elseif ($arg === '--force') {
        $force = true;
    } elseif (strpos($arg, '-') === 0) {
        echo "Unknown option: $arg\n";
        exit(1);
    } elseif ($output === null && strpos($arg, '.zip') !== false) {
        $output = $arg;
    } else {
        $revision = $arg;
    }
}

// Ensure execution is relative to git toplevel
exec('git rev-parse --show-toplevel 2>&1', $topLevelOut, $topLevelCode);
if ($topLevelCode !== 0) {
    echo "Error: Must be run inside a Git repository.\n";
    exit(1);
}
$repoRoot = trim($topLevelOut[0]);
chdir($repoRoot);

if ($output === null) {
    $output = 'phase-qa-archive-'.date('YmdHis').'.zip';
}

if (! $force && file_exists($output)) {
    echo "Error: Output file '$output' already exists. Use --force to overwrite.\n";
    exit(1);
}

// Verify git revision
exec('git rev-parse --verify '.escapeshellarg($revision).' 2>&1', $verifyOutput, $verifyCode);
if ($verifyCode !== 0) {
    echo "Error: Invalid Git revision '$revision'.\n";
    exit(1);
}

// Reject archive if revision contains forbidden tracked files
exec('git ls-tree -r --name-only '.escapeshellarg($revision), $treeOutput, $treeCode);
if ($treeCode !== 0) {
    echo "Error: Failed to read tree for revision '$revision'.\n";
    exit(1);
}

foreach ($treeOutput as $path) {
    // Allow .gitignore placeholders
    if (str_ends_with($path, '.gitignore')) {
        continue;
    }

    $forbidden = false;
    if ($path === '.env.example') {
        // Allowed
    } elseif (preg_match('/^\.env(\..+)?$/i', $path)) {
        $forbidden = true;
    } elseif (str_starts_with($path, '.git/')) {
        $forbidden = true;
    } elseif (str_ends_with(strtolower($path), '.sqlite')) {
        $forbidden = true;
    } elseif (str_starts_with(strtolower($path), 'database/') && str_contains(strtolower($path), '.sqlite')) {
        $forbidden = true;
    } elseif (str_starts_with($path, 'storage/logs/')) {
        $forbidden = true;
    } elseif (str_starts_with($path, 'storage/framework/cache/')) {
        $forbidden = true;
    } elseif (str_starts_with($path, 'storage/framework/sessions/')) {
        $forbidden = true;
    } elseif (str_starts_with($path, 'storage/framework/views/')) {
        $forbidden = true;
    } elseif (str_starts_with($path, 'storage/app/materials/')) {
        $forbidden = true;
    } elseif (str_starts_with($path, 'storage/app/private/')) {
        $forbidden = true;
    } elseif (str_ends_with(strtolower($path), '.docx')) {
        $forbidden = true;
    } elseif (str_ends_with(strtolower($path), '.zip')) {
        // Reject nested zip archives
        $forbidden = true;
    }

    if ($forbidden) {
        echo "Error: Safe Archive Rejected.\n";
        echo "The revision '$revision' tracks a forbidden path category:\n";
        echo " -> $path\n";
        exit(1);
    }
}

echo "Generating SAFE QA archive from revision: $revision\n";
$cmd = 'git archive --format=zip --output='.escapeshellarg($output).' '.escapeshellarg($revision).' 2>&1';
exec($cmd, $cmdOutput, $returnCode);

if ($returnCode !== 0) {
    echo "Error: Failed to create archive.\n".implode("\n", $cmdOutput)."\n";
    if (file_exists($output)) {
        unlink($output);
    }
    exit(1);
}

echo "Success! Safe archive created at: $output\n";
