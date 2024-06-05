<?php

namespace Acms\Plugins\Vite;

use ACMS_App;
use Acms\Services\Facades\Application;
use Acms\Services\Common\HookFactory;

class ServiceProvider extends ACMS_App
{
    /**
     * @var string
     */
    public $version = '1.0.1';

    /**
     * @var string
     */
    public $name = 'Vite';

    /**
     * @var string
     */
    public $author = 'com.appleple';

    /**
     * @var bool
     */
    public $module = false;

    /**
     * @var false|string
     */
    public $menu = false;

    /**
     * @var string
     */
    public $desc = 'Viteを利用するための拡張アプリです。';

    /**
     * サービスの初期処理
     */
    public function init()
    {
        Application::singleton('vite.helper', Vite::class, [
            env('VITE_DEV_SERVER_URL'),
            env('VITE_MANIFEST_PATH'),
            env('VITE_ENVIRONMENT', 'development')
        ]);
        $hook = HookFactory::singleton();
        $hook->attach('vite.hook', new Hook());
    }

    /**
     * インストールする前の環境チェック処理
     *
     * @return bool
     */
    public function checkRequirements()
    {
        return true;
    }

    /**
     * インストールするときの処理
     * データベーステーブルの初期化など
     *
     * @return void
     */
    public function install()
    {
    }

    /**
     * アンインストールするときの処理
     * データベーステーブルの始末など
     *
     * @return void
     */
    public function uninstall()
    {
    }

    /**
     * アップデートするときの処理
     *
     * @return bool
     */
    public function update()
    {
        return true;
    }

    /**
     * 有効化するときの処理
     *
     * @return bool
     */
    public function activate()
    {
        return true;
    }

    /**
     * 無効化するときの処理
     *
     * @return bool
     */
    public function deactivate()
    {
        return true;
    }
}
