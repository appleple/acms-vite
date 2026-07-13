<?php

declare(strict_types=1);

/**
 * src/ServiceProvider.php（バージョンの単一情報源）のプラグインバージョンを設定する。
 *
 * 使い方:
 *   php scripts/version.php 1.2.3     # 明示的にバージョンを設定
 *   php scripts/version.php patch     # 現在のバージョンを上げる（patch|minor|major）
 */

require __DIR__ . '/Packager.php';

use Acms\PluginTools\Packager;

$root = dirname(__DIR__);
$arg = $argv[1] ?? '';

if ($arg === '') {
    fwrite(STDERR, "Usage: php scripts/version.php <X.Y.Z|patch|minor|major>\n");
    exit(1);
}

try {
    $packager = new Packager($root);
    $current = $packager->currentVersion();

    if (in_array($arg, ['patch', 'minor', 'major'], true)) {
        $new = Packager::bumpVersion($current, $arg);
    } elseif (Packager::isValidVersion($arg)) {
        $new = $arg;
    } else {
        fwrite(STDERR, "Invalid version '{$arg}' (expected X.Y.Z or patch|minor|major).\n");
        exit(1);
    }

    $packager->setVersion($new);
    echo "Version: {$current} -> {$new}\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
