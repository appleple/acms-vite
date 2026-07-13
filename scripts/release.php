<?php

declare(strict_types=1);

/**
 * リリースを切る: バージョンを上げ、コミットして git タグ v{X.Y.Z} を作成する。
 *
 * 使い方:
 *   php scripts/release.php patch            # bump + commit + tag （push はしない）
 *   php scripts/release.php minor --push     # コミットとタグの push まで行う
 *
 * 既定では push しない。タグの push は後戻りしづらい操作（GitHub では Release CI の発火、
 * いずれの場合も公開版の確定）なので、人が意図して実行する。自動化したい場合のみ --push を付ける。
 *
 * リリースモード（composer.json の extra.acms-plugin.release）で挙動が変わる:
 *   - github : zip はビルドしない（tag push で release.yml が composer package して Release 公開）。
 *   - local  : ここでバージョン付き zip（build/{Name}{version}.zip）を作りコミットに含める。
 *              push 後、その zip を手動で Google Drive にアップロードする。
 */

require __DIR__ . '/Packager.php';

use Acms\PluginTools\Packager;

$root = dirname(__DIR__);

$part = null;
$push = false;
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, ['patch', 'minor', 'major'], true)) {
        $part = $arg;
    } elseif ($arg === '--push') {
        $push = true;
    }
}

if ($part === null) {
    fwrite(STDERR, "Usage: php scripts/release.php <patch|minor|major> [--push]\n");
    exit(1);
}

/**
 * プロジェクトルートで git コマンドを実行する。まずコマンドを表示し、非ゼロ終了で中断する。
 */
$git = static function (string $command) use ($root): void {
    $full = 'git -C ' . escapeshellarg($root) . ' ' . $command;
    echo $full, "\n";
    $status = 1;
    passthru($full, $status);
    if ($status !== 0) {
        fwrite(STDERR, "Command failed (exit {$status}): {$full}\n");
        exit($status);
    }
};

try {
    $packager = new Packager($root);
    $mode = $packager->releaseMode();
    $current = $packager->currentVersion();
    $new = Packager::bumpVersion($current, $part);
    $tag = "v{$new}";

    // 1) バージョンを更新（src/ServiceProvider.php）
    $packager->setVersion($new);
    echo "Version: {$current} -> {$new} (mode: {$mode})\n";

    // 2) local モードのみ、配布 zip をここで作る（リポジトリに含める運用）
    if ($mode === 'local') {
        $zipPath = $packager->build();
        printf("Packaged %s (%s bytes)\n", $zipPath, number_format((float) filesize($zipPath)));
    }

    // 3) コミット & タグ（注釈付きタグにする: 軽量タグは push --follow-tags で送られないため）
    $git('add -A');
    $git('commit -m ' . escapeshellarg($tag));
    $git('tag -a ' . escapeshellarg($tag) . ' -m ' . escapeshellarg($tag));

    if ($push) {
        $git('push --follow-tags');
        echo $mode === 'github'
            ? "Pushed {$tag}. release.yml が zip をビルドして GitHub Release を公開します。\n"
            : "Pushed {$tag}. build/ のバージョン付き zip を Google Drive にアップロードしてください。\n";
    } else {
        echo "\nCreated local tag {$tag}. 公開するには push してください:\n";
        echo "  git push --follow-tags\n";
        if ($mode === 'local') {
            echo "その後 build/ のバージョン付き zip を Google Drive にアップロードしてください。\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
