# acms-vite

a-blog cms でViteを利用するための拡張アプリです。

## 動作条件

* php 7.3 以上
* a-blog cms Ver. 3.1.0 以上

## ダウンロード

[Vite for a-blog cms](https://github.com/appleple/acms-vite/raw/master/build/Vite.zip)

## インストール

1. ダウンロードしたファイルを解凍し、`Vite` ディレクトリを a-blog cms の `extensions` ディレクトリに配置します。
2. 管理画面にログインし、`拡張アプリ管理` から `Vite` をインストールします。

### 注意

config.server.php で HOOK_ENABLE を有効にしておく必要があります。

```php
define('HOOK_ENABLE', 1);
```

## 使い方

a-blog cms で Vite を利用してバンドルしたファイルを読み込むためには、[バックエンドとの統合](https://vitejs.dev/guide/backend-integration)で記載されている仕様に合わせて、script タグや link タグを出力する必要があります。

本拡張アプリを利用することで、Vite の仕様に合わせてタグを出力することができるようになり、a-blog cms のテンプレート内で簡単に Vite を利用することができます。

### Vite の設定

Vite の設定は `vite.config.js` に記述します。詳細は [Vite のドキュメント](https://vitejs.dev/config/) を参照してください。

`build.manifest` を `true` に設定し、`rollupOptions.input` でエントリーポイントとなるファイルを指定します。
以下のサンプルは、`themes/利用テーマ/src/js/main.js` をエントリーポイントとして指定しています。

```js
import { defineConfig } from 'vite';

export default defineConfig({
    // ...
    build: {
        manifest: true,
        rollupOptions: {
            input: {
                bundle: resolve(__dirname, 'src/js/main.js'),
            },
        },
    },
});
```

この設定をすることで、manifest.json にビルドされたアセットのパスが出力されるようになります。

### スクリプトとスタイルの読み込み

Vite の設定が完了したら、a-blog cms のテンプレート内にスクリプトとスタイルを読み込むための記述を追加します。
head タグ内 `@vite()` を追加します。

```html
<!DOCTYPE html>
<head>
  @vite('src/js/main.js')
</head>
```

複数のエントリーポイントを指定する場合は、配列で指定します。

```html
<!DOCTYPE html>
<head>
  @vite(['src/js/main.js', 'src/js/main.css'])
</head>
```

これで、Vite の開発サーバーからビルドされたアセットが読み込まれるようになります。

### 本番環境での利用

Vite で本番環境用ビルドしたアセットを読み込めるようにするためには、a-blog cms 設置ディレクトリにある .env ファイルを編集します。

以下のように `VITE_ENVIRONMENT` に `production` を指定します。

```
# Vite
VITE_ENVIRONMENT=production # development | production
```

これで、Vite で本番環境用ビルドしたアセットが読み込まれるようになります。


## 設定

`@vite()` の第2引数にJSON形式でオプションを指定することができます。

### 出力ディレクトリの設定

例えば、Vite の設定で `build.outDir` を `bundle` に設定している場合、以下のように記述します。

```js
import { defineConfig } from 'vite';

export default defineConfig({
    // ...
    build: {
        outDir: 'bundle',
    },
});
```

`@vite()` の第2引数に出力ディレクトリを指定します。

```html
<!DOCTYPE html>
<head>
  @vite('src/js/main.js', {
    outDir: 'bundle'
  })
</head>
```

### タグの属性をカスタマイズする

タグの属性をカスタマイズすることができます。

以下の例では、エントリーポイントから出力された script タグに `async` 属性を追加しています。

```html
<!DOCTYPE html>
<head>
  @vite('src/js/main.js', {
    "scriptTagAttributes": {
      "async": true
    }
  })
</head>
```

また、link タグの属性もカスタマイズすることができます。
以下の例では、エントリーポイントから出力された link タグに `type="text/css"` 属性を追加しています。

```html
<!DOCTYPE html>
<head>
  @vite('src/js/main.js', {
    "linkTagAttributes": {
      "type": "text/css"
    }
  })
</head>
```

## React の利用

React と `@vitejs/plugin-react` を利用する場合、既存の `@vite` と一緒に、追加で `@viteReactRefresh` を追加する必要があります。

```html
<!DOCTYPE html>
<head>
  @viteReactRefresh
  @vite('src/js/main.jsx')
</head>
```

`@viteReactRefresh` は、React の Hot Module Replacement を有効にするためのスクリプトを出力します。

```html
<script type="module">
  import RefreshRuntime from 'http://localhost:5173/@react-refresh'
  RefreshRuntime.injectIntoGlobalHook(window)
  window.$RefreshReg$ = () => {}
  window.$RefreshSig$ = () => (type) => type
  window.__vite_plugin_react_preamble_installed__ = true
</script>
```

## グローバル変数

本拡張アプリをインストールすることで、以下のグローバル変数が利用できるようになります。

| グローバル変数名               | 説明                                                                 |
|---------------------|----------------------------------------------------------------------|
| `%{VITE_ENVIRONMENT}`  | 現在の Vite の動作モードの値を出力します（`development` または `production`）です。`development` の場合、Vite の開発サーバーを利用し、`production` の場合はビルドされたアセットを利用します。 |
| `%{VITE_DEV_SERVER_URL}`| Vite 開発サーバーのURLを出力します。Vite の開発サーバーの URL を出力します。                               |

## 高度なカスタマイズ

環境変数を利用して、Vite の設定をカスタマイズすることができます。

以下、環境変数の説明です。

| 変数名               | 説明                                                                 |
|---------------------|----------------------------------------------------------------------|
| `VITE_ENVIRONMENT`  | `development` または `production` を指定します。`development` の場合、Vite の開発サーバーを利用し、`production` の場合はビルドされたアセットを利用します。 |
| `VITE_MANIFEST_PATH`| `manifest.json` のパスを利用しているテーマディレクトリから指定します。                    |
| `VITE_DEV_SERVER_URL`| Vite の開発サーバーの URL を指定します。                               |
