<?php

declare(strict_types=1);

/**
 * 配布用プラグイン zip（build/{Name}.zip）を作成する。
 *
 * 使い方:
 *   php scripts/package.php                        # zip を作成
 *   php scripts/package.php --check-version=1.2.3  # ServiceProvider の $version == 1.2.3 を検証
 */

require __DIR__ . '/Packager.php';

use Acms\PluginTools\Packager;

$root = dirname(__DIR__);

try {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--check-version=')) {
            $expected = substr($arg, strlen('--check-version='));
            $actual = (new Packager($root))->currentVersion();
            if ($expected !== $actual) {
                fwrite(STDERR, "Version mismatch: tag='{$expected}' but ServiceProvider \$version='{$actual}'.\n");
                exit(1);
            }
            echo "Version OK: {$actual}\n";
            exit(0);
        }
    }

    $zipPath = (new Packager($root))->build();
    printf("Created %s (%s bytes)\n", $zipPath, number_format((float) filesize($zipPath)));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
