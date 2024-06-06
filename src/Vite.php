<?php

declare(strict_types=1);

namespace Acms\Plugins\Vite;

use Acms\Plugins\Vite\Exceptions\ManifestNotFoundException;
use Acms\Services\Facades\Cache;
use Acms\Services\Facades\Storage;
use Acms\Services\Facades\Logger;
use Acms\Services\Facades\Common;

class Vite
{
    private const DEFAULT_VITE_DEV_SERVER_URL = 'http://localhost:5173';

    private const DEFAULT_MANIFEST_PATHS = [
        'dist/manifest.json',
        'dist/.vite/manifest.json',
    ];

    /**
     * @var \Acms\Services\Cache\Contracts\AdapterInterface
     */
    private $cache;

    /**
     * @var string
     */
    private $devServerUrl;

    /**
     * @var string
     */
    private $manifestPath;

    /**
     * @var 'development' | 'production'
     */
    private $environment;

    /**
     * Constructor
     * @param string $devServerUrl
     * @param string $manifestPath
     * @param 'development' | 'production' $environment
     */
    public function __construct(
        string $devServerUrl,
        string $manifestPath,
        string $environment
    ) {
        $this->devServerUrl = $devServerUrl;
        $this->manifestPath = $manifestPath;
        $this->environment = $environment;
        $this->cache = Cache::temp();
    }

    /**
     * Generate HTML
     * @see https://vitejs.dev/guide/backend-integration.html
     * @param string|string[] $entrypoints
     * @param array{
     *   outDir?: string,
     *   scriptTagAttributes?: array<string, mixed>,
     *   linkTagAttributes?: array<string, mixed>,
     * } $options
     * @return string
     */
    public function generateHtml($entrypoints, array $options): string
    {
        $entrypoints = is_string($entrypoints) ? [$entrypoints] : $entrypoints;
        $defaultOptions = [
            'outDir' => 'dist',
            'scriptTagAttributes' => [],
            'linkTagAttributes' => [],
        ];

        /**
         * @var array{
         *  outDir: string,
         *  scriptTagAttributes: array<string, mixed>,
         *  linkTagAttributes: array<string, mixed>,
         * } $config
         */
        $config = array_merge($defaultOptions, $options);

        $cssLinkTags = $this->createCssLinkTags($entrypoints, $config['outDir']);
        $importedChankLinkTags = $this->createImportedChankLinkTags($entrypoints, $config['outDir']);
        $entrypointTags = $this->createEntrypointTags(
            $entrypoints,
            $config['outDir'],
            $config['scriptTagAttributes'],
            $config['linkTagAttributes']
        );
        $modulePreloadLinkTags = $this->createModulePreloadLinkTags($entrypoints, $config['outDir']);
        return $cssLinkTags . "\n" . $importedChankLinkTags . "\n" . $entrypointTags . "\n" . $modulePreloadLinkTags;
    }

    public function generateReactRefreshHtml(): string
    {
        if (!$this->shouldUseDevServer()) {
            return '';
        }
        return sprintf(
            <<<'HTML'
            <script type="module">
                import RefreshRuntime from '%s'
                RefreshRuntime.injectIntoGlobalHook(window)
                window.$RefreshReg$ = () => {}
                window.$RefreshSig$ = () => (type) => type
                window.__vite_plugin_react_preamble_installed__ = true
            </script>
            HTML,
            $this->getDevServerUrl() . '/@react-refresh'
        );
    }

    /**
     * Get environment
     * @return 'development' | 'production'
     */
    public function getEnvironment()
    {
        return $this->shouldUseDevServer() ? 'development' : 'production';
    }

    public function getDevServerUrl()
    {
        if ($this->devServerUrl !== '') {
            return $this->devServerUrl;
        }
        return self::DEFAULT_VITE_DEV_SERVER_URL;
    }

    /**
     * Create script tags
     * @param string[] $entrypoints
     * @param string $outDir
     * @param array<string, mixed> $scriptAttributes
     * @param array<string, mixed> $linkAttributes
     * @return string
     */
    private function createEntrypointTags(
        array $entrypoints,
        string $outDir,
        array $scriptAttributes = [],
        array $linkAttributes = []
    ): string {
        if ($this->shouldUseDevServer()) {
            $scriptTags = array_map(
                function (string $entrypoint) {
                    $attributes = [
                        'type' => 'module',
                        'src' => $this->getDevServerUrl() . '/' . $entrypoint,
                    ];
                    return $this->createScriptTag($attributes);
                },
                $entrypoints
            );
            return $this->createScriptTag([
                'type' => 'module',
                'src' => $this->getDevServerUrl() . '/@vite/client'
            ]) . "\n" . implode("\n", $scriptTags);
        }

        $manifest = $this->getManifest();
        $paths = [];
        foreach ($entrypoints as $entrypoint) {
            if (array_key_exists($entrypoint, $manifest)) {
                $paths[] = '/' . $outDir . '/' . $manifest[$entrypoint]['file'];
            }
        }

        $tags = array_map(
            function (string $path) use ($scriptAttributes, $linkAttributes) {
                if ($this->isCssPath($path)) {
                    $attributes = array_merge(
                        [
                            'rel' => 'stylesheet',
                            'href' => $path,
                        ],
                        $linkAttributes
                    );
                    return $this->createLinkTag($attributes);
                }
                $attributes = array_merge(
                    [
                        'type' => 'module',
                        'src' => $path,
                    ],
                    $scriptAttributes
                );
                return $this->createScriptTag($attributes);
            },
            $paths
        );
        return implode("\n", $tags);
    }

    /**
     * Create CSS link tags
     * @param string[] $entrypoints
     * @param string $outDir
     * @return string
     */
    private function createCssLinkTags(array $entrypoints, string $outDir): string
    {
        if ($this->shouldUseDevServer()) {
            return '';
        }

        $outDir = trim($outDir, '/');
        $manifest = $this->getManifest();
        $cssPaths = [];
        foreach ($entrypoints as $entrypoint) {
            foreach ($manifest[$entrypoint]['css'] ?? [] as $file) {
                $cssPaths[] = '/' . $outDir . '/' . $file;
            }
        }

        $linkTags = array_map(
            function (string $path) {
                $attributes = [
                    'rel' => 'stylesheet',
                    'href' => $path,
                ];
                return $this->createLinkTag($attributes);
            },
            $cssPaths
        );
        return implode("\n", $linkTags);
    }

    /**
     * Create imported chank link tags
     * @param string[] $entrypoints
     * @param string $outDir
     * @return string
     */
    private function createImportedChankLinkTags(array $entrypoints, string $outDir): string
    {
        if ($this->shouldUseDevServer()) {
            return '';
        }

        $outDir = trim($outDir, '/');
        $manifest = $this->getManifest();
        $cssPaths = [];
        foreach ($entrypoints as $entrypoint) {
            foreach ($manifest[$entrypoint]['imports'] ?? [] as $import) {
                foreach ($manifest[$import]['css'] ?? [] as $file) {
                    $cssPaths[] = '/' . $outDir . '/' . $file;
                }
            }
        }

        $linkTags = array_map(
            function (string $path) {
                $attributes = [
                    'rel' => 'stylesheet',
                    'href' => $path,
                ];
                return $this->createLinkTag($attributes);
            },
            $cssPaths
        );

        return implode("\n", $linkTags);
    }

    /**
     * Create modulepreload link tags
     * @param string[] $entrypoints
     * @param string $outDir
     * @return string
     */
    private function createModulePreloadLinkTags(
        array $entrypoints,
        string $outDir
    ): string {
        if ($this->shouldUseDevServer()) {
            return '';
        }

        $outDir = trim($outDir, '/');
        $manifest = $this->getManifest();
        $preloadPaths = [];
        foreach ($entrypoints as $entrypoint) {
            foreach ($manifest[$entrypoint]['imports'] ?? [] as $import) {
                $preloadPaths[] = '/' . $outDir . '/' . $manifest[$import]['file'];
            }
        }

        $linkTags = array_map(
            function (string $path) {
                $attributes = [
                    'rel' => 'modulepreload',
                    'href' => $path,
                ];
                return $this->createLinkTag($attributes);
            },
            $preloadPaths
        );

        return implode("\n", $linkTags);
    }

    /**
     * Determine whether to use the development server.
     * @return bool
     */
    private function shouldUseDevServer(): bool
    {
        return $this->environment === 'development';
    }

    /**
     * Get manifest
     * @return array<string, mixed>
     */
    private function getManifest(): array
    {
        $path = $this->getManifestPath();
        $cacheKey = md5($path);
        if ($this->cache->has($cacheKey)) {
            return json_decode($this->cache->get($cacheKey), true);
        }
        try {
            $manifest = Storage::get($path);
            if (empty($manifest)) {
                throw new ManifestNotFoundException('Manifest file not found.');
            }
        } catch (\Exception $e) {
            Logger::error('【Vite plugin】manifest.json を取得できませんでした。', Common::exceptionArray($e));
            return [];
        }
        $this->cache->put($cacheKey, $manifest);
        return json_decode($manifest, true);
    }

    /**
     * Get manifest path
     * @return string
     */
    private function getManifestPath(): string
    {
        // Check if manifest is present in the specified path.
        $manifestPath = findTemplate($this->manifestPath);
        if (is_string($manifestPath)) {
            return $manifestPath;
        }

        // Check if manifest is present in one of the default locations.
        foreach (self::DEFAULT_MANIFEST_PATHS as $path) {
            $manifestPath = findTemplate($path);
            if (is_string($manifestPath) && Storage::exists($manifestPath)) {
                return $manifestPath;
            }
        }

        return '';
    }

    /**
     * Parse the attributes into key="value" strings.
     *
     * @param array $attributes
     * @return array
     */
    private function parseAttributes(array $attributes): array
    {
        $attributes = array_filter(
            $attributes,
            function ($value) {
                return $value !== false && $value !== null;
            }
        );
        return array_values(
            array_map(
                function ($value, $key) {
                    if ($value === true) {
                        return $key;
                    }
                    return is_int($key) ? $value : $key . '="' . $value . '"';
                },
                $attributes,
                array_keys($attributes)
            )
        );
    }

    /**
     * Create a script tag.
     *
     * @param array $attributes
     * @return string
     */
    private function createScriptTag(array $attributes): string
    {
        $attributes = $this->parseAttributes($attributes);
        return '<script ' . implode(' ', $attributes) . '></script>';
    }

    /**
     * Create a link tag.
     *
     * @param array $attributes
     * @return string
     */
    private function createLinkTag(array $attributes): string
    {
        $attributes = $this->parseAttributes($attributes);
        return '<link ' . implode(' ', $attributes) . '>';
    }

    /**
     * Determine whether the given path is a CSS file.
     *
     * @param string $path
     * @return bool
     */
    private function isCssPath(string $path): bool
    {
        return preg_match('/\.(css|less|sass|scss|styl|stylus|pcss|postcss)$/', $path) === 1;
    }
}
