<?php

declare(strict_types=1);

namespace Acms\PluginTools;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * Release helper shared by scripts/package.php, scripts/version.php and scripts/release.php.
 * acms-plugin-skeleton の Packager を基に、GitHub（CI 公開）と Bitbucket（ローカルビルド＋手動配布）の
 * 両運用を composer.json の extra.acms-plugin で切り替えられるようにしたもの。
 * スクリプトはプラグイン間で共通（この Acms\PluginTools 名前空間のまま）にでき、差分は composer.json だけ。
 *
 * プラグインのバージョンの単一情報源は src/ServiceProvider.php の $version。
 * composer.json に "version" は持たせない（git tag と競合するため）。
 */
final class Packager
{
    /** zip の中に絶対に含めないもの（src/ 側にあった場合の保険）。 */
    private const IGNORES = ['composer.json', 'composer.lock'];

    /** extra.acms-plugin.extras 未指定時に src/ と一緒に同梱するルート直下の既定候補。 */
    private const DEFAULT_EXTRAS = ['README.md', 'LICENSE', 'images'];

    public function __construct(private string $root)
    {
    }

    /**
     * composer.json の autoload.psr-4（src/ に対応する名前空間）末尾からプラグイン名を導出する。
     */
    public function pluginName(): string
    {
        return self::pluginNameFromComposer($this->composerContents());
    }

    /**
     * リリース運用モード: "github"（CI が Release として公開・zip 名は {Name}.zip・build は非管理）
     * または "local"（ローカルでバージョン付き zip を作りリポに含める・手動で GDrive へ）。
     * composer.json の extra.acms-plugin.release で指定（未指定なら "github"）。
     */
    public function releaseMode(): string
    {
        $mode = $this->extraConfig()['release'] ?? 'github';

        return in_array($mode, ['github', 'local'], true) ? $mode : 'github';
    }

    /**
     * src/ と一緒に同梱するルート直下の相対パス一覧。
     * composer.json の extra.acms-plugin.extras で上書きできる（未指定なら DEFAULT_EXTRAS）。
     *
     * @return list<string>
     */
    public function extras(): array
    {
        $configured = $this->extraConfig()['extras'] ?? null;
        if (is_array($configured)) {
            return array_values(array_filter(array_map('strval', $configured), static fn($s) => $s !== ''));
        }

        return self::DEFAULT_EXTRAS;
    }

    /**
     * 生成する zip のベース名（拡張子なし）。local モードはバージョンを付ける（例: GoogleDrive1.1.1）。
     */
    public function zipBaseName(): string
    {
        $name = $this->pluginName();

        return $this->releaseMode() === 'local' ? $name . $this->currentVersion() : $name;
    }

    /**
     * src/ServiceProvider.php に宣言されている現在のバージョンを読む。
     */
    public function currentVersion(): string
    {
        return self::versionFromServiceProvider(
            (string) file_get_contents($this->serviceProviderPath())
        );
    }

    /**
     * src/ServiceProvider.php の $version を書き換え、書き込んだ値を返す。
     */
    public function setVersion(string $newVersion): string
    {
        $path = $this->serviceProviderPath();
        $code = (string) file_get_contents($path);
        file_put_contents($path, self::replaceVersion($code, $newVersion));

        return $newVersion;
    }

    /**
     * build/{ベース名}.zip（トップ階層は {Name}/…）を作成し、その zip パスを返す。
     */
    public function build(): string
    {
        $name = $this->pluginName();
        $srcDir = $this->root . '/src';
        if (!is_dir($srcDir)) {
            throw new RuntimeException("src/ not found at {$srcDir}");
        }

        // プラグインが自前の実行時依存を持つ場合は src/vendor/ へ先に vendoring する。
        if (is_file($srcDir . '/composer.json')) {
            $this->installRuntimeDeps($srcDir);
        }

        $buildDir = $this->root . '/build';
        $this->ensureDir($buildDir);

        $stageDir = $buildDir . '/' . $name;
        $this->removeTree($stageDir);
        $this->copyTree($srcDir, $stageDir);

        foreach ($this->extras() as $extra) {
            $from = $this->root . '/' . $extra;
            if (file_exists($from)) {
                $this->copyTree($from, $stageDir . '/' . $extra);
            }
        }

        foreach (self::IGNORES as $ignore) {
            $this->removeTree($stageDir . '/' . $ignore);
        }

        $zipPath = $buildDir . '/' . $this->zipBaseName() . '.zip';
        $this->zipTree($stageDir, $name, $zipPath);
        $this->removeTree($stageDir);

        return $zipPath;
    }

    // ---------------------------------------------------------------------
    // 純粋ヘルパー（副作用なし）
    // ---------------------------------------------------------------------

    public static function pluginNameFromComposer(string $contents): string
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode($contents, true);
        if (!is_array($data)) {
            throw new RuntimeException('composer.json is not valid JSON.');
        }

        $psr4 = $data['autoload']['psr-4'] ?? null;
        if (!is_array($psr4) || $psr4 === []) {
            throw new RuntimeException('composer.json has no autoload.psr-4 entry.');
        }

        // src/ に対応する名前空間を優先する（配布されるプラグインルート）。
        $key = null;
        foreach ($psr4 as $namespace => $path) {
            foreach ((array) $path as $p) {
                if (rtrim((string) $p, '/') === 'src') {
                    $key = (string) $namespace;
                    break 2;
                }
            }
        }
        $key ??= (string) array_key_first($psr4);

        $segments = array_values(array_filter(
            explode('\\', trim($key, '\\')),
            static fn(string $s): bool => $s !== ''
        ));
        if ($segments === []) {
            throw new RuntimeException("Cannot derive a plugin name from the psr-4 key '{$key}'.");
        }

        return (string) end($segments);
    }

    public static function versionFromServiceProvider(string $code): string
    {
        if (preg_match('/\$version\s*=\s*\'([\d.]+)\'/', $code, $m) !== 1) {
            throw new RuntimeException('Could not find $version in ServiceProvider.php.');
        }

        return $m[1];
    }

    public static function isValidVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1;
    }

    public static function bumpVersion(string $current, string $part): string
    {
        if (!self::isValidVersion($current)) {
            throw new RuntimeException("Current version '{$current}' is not X.Y.Z.");
        }

        [$major, $minor, $patch] = array_map('intval', explode('.', $current));
        switch ($part) {
            case 'major':
                $major++;
                $minor = 0;
                $patch = 0;
                break;
            case 'minor':
                $minor++;
                $patch = 0;
                break;
            case 'patch':
                $patch++;
                break;
            default:
                throw new RuntimeException("Unknown version part '{$part}' (expected major|minor|patch).");
        }

        return "{$major}.{$minor}.{$patch}";
    }

    public static function replaceVersion(string $code, string $newVersion): string
    {
        if (!self::isValidVersion($newVersion)) {
            throw new RuntimeException("Version '{$newVersion}' is not X.Y.Z.");
        }

        $count = 0;
        $result = preg_replace(
            '/(\$version\s*=\s*\')[\d.]+(\')/',
            '${1}' . $newVersion . '${2}',
            $code,
            1,
            $count
        );
        if ($result === null || $count === 0) {
            throw new RuntimeException('Could not update $version in ServiceProvider.php.');
        }

        return $result;
    }

    // ---------------------------------------------------------------------
    // 内部ヘルパー
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function extraConfig(): array
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode($this->composerContents(), true);
        $config = $data['extra']['acms-plugin'] ?? [];

        return is_array($config) ? $config : [];
    }

    private function composerContents(): string
    {
        $composerJson = $this->root . '/composer.json';
        if (!is_file($composerJson)) {
            throw new RuntimeException("composer.json not found at {$composerJson}");
        }

        return (string) file_get_contents($composerJson);
    }

    private function serviceProviderPath(): string
    {
        $path = $this->root . '/src/ServiceProvider.php';
        if (!is_file($path)) {
            throw new RuntimeException("ServiceProvider.php not found at {$path}");
        }

        return $path;
    }

    private function installRuntimeDeps(string $srcDir): void
    {
        $this->run(sprintf(
            'composer install --working-dir=%s --no-dev --optimize-autoloader --no-interaction --no-progress',
            escapeshellarg($srcDir)
        ));
    }

    private function zipTree(string $stageDir, string $name, string $zipPath): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension (ext-zip) is required to build the package.');
        }
        if (is_file($zipPath)) {
            unlink($zipPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not open {$zipPath} for writing.");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stageDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $relative = str_replace('\\', '/', ltrim(substr($item->getPathname(), strlen($stageDir)), '/\\'));
            $entry = $name . '/' . $relative;
            if ($item->isDir()) {
                $zip->addEmptyDir($entry);
            } else {
                $zip->addFile($item->getPathname(), $entry);
            }
        }

        $zip->close();
    }

    private function copyTree(string $from, string $to): void
    {
        if (is_dir($from)) {
            $this->ensureDir($to);
            foreach (scandir($from) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $this->copyTree($from . '/' . $item, $to . '/' . $item);
            }

            return;
        }

        $this->ensureDir(dirname($to));
        if (!copy($from, $to)) {
            throw new RuntimeException("Failed to copy {$from} to {$to}");
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $item);
        }
        @rmdir($path);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create directory {$dir}");
        }
    }

    private function run(string $command): void
    {
        echo $command, "\n";
        $status = 1;
        passthru($command, $status);
        if ($status !== 0) {
            throw new RuntimeException("Command failed (exit {$status}): {$command}");
        }
    }
}
