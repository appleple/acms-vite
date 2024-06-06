<?php

declare(strict_types=1);

namespace Acms\Plugins\Vite;

use Acms\Services\Facades\Application;

class Hook
{
    /**
     * @var Vite
     */
    private $vite;

    public function __construct()
    {
        $this->vite = Application::make('vite.helper');
    }

    /**
     * 例: グローバル変数の拡張
     *
     * @param \Field &$globalVars
     * @return void
     */
    public function extendsGlobalVars(\Field &$globalVars): void
    {
        $globalVars->set('VITE_ENVIRONMENT', $this->vite->getEnvironment());
        $globalVars->set('VITE_DEV_SERVER_URL', $this->vite->getDevServerUrl());
    }

    /**
     * ビルド前（GETモジュール解決前）
     *
     * @param string &$tpl テンプレート文字列
     * @return void
     */
    public function beforeBuild(&$tpl)
    {
        $tpl = $this->resolveVite($tpl);
    }

    /**
     * @viteを解決
     *
     * @param string $string テンプレート文字列
     * @return string
     */
    private function resolveVite(string $string): string
    {
        $string = (string)preg_replace(
            "/@viteReactRefresh/",
            $this->vite->generateReactRefreshHtml(),
            $string
        );
        $string = preg_replace(
            "/@vite?[\t\s　]*\([\t'\"\s　]*([^\)\n'\"]*?)[\t'\"\n\s　]*\);?/u",
            "@vite($1)",
            $string
        );
        $regex = '/@vite\(\s*(\[(?:"[^"]*"|\'[^\']*\')(?:\s*,\s*(?:"[^"]*"|\'[^\']*\'))*\]|(?:"[^"]*"|\'[^\']*\'))\s*(?:,\s*(\{[^\)]+\}))?\s*\)/u'; // phpcs:ignore

        return (string)preg_replace_callback($regex, function ($matches) {
            // エントリーポイントの解析
            $entrypoints = $matches[1];
            if (substr($entrypoints, 0, 1) === '[') {
                // 配列形式の場合
                $entrypoints = trim($entrypoints, '[]');
                $entrypoints = array_map(
                    function ($entrypoint) {
                        return trim(trim($entrypoint), '\'"');
                    },
                    explode(',', $entrypoints)
                );
            } else {
                // 単一のエントリーポイントの場合
                $entrypoints = [trim($entrypoints, '\'"')];
            }
            // オプションの解析
            $options = isset($matches[2]) ? json_decode($matches[2], true) : [];
            $html = resolvePath($this->vite->generateHtml($entrypoints, $options), config('theme'), '/');
            return $html;
        }, $string);
    }
}
