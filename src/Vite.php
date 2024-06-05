<?php

namespace Acms\Plugins\Vite;

use Acms\Services\Facades\Cache;
use Acms\Services\Facades\Storage;

class Vite
{
    /**
     * @var \Acms\Services\Cache\Contracts\AdapterInterface
     */
    protected $cache;

    /**
     * @var string
     */
    protected $devServerUrl;

    /**
     * @var string
     */
    protected $manifestPath;

    /**
     * @var 'development' | 'production
     */
    protected $environment;

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
     * @param string|string[] $entrypoints
     * @param array{
     *   outDir?: string,
     * } $options
     * @return string
     */
    public function generateHtml($entrypoints, array $options): string
    {
        $entrypoints = is_string($entrypoints) ? [$entrypoints] : $entrypoints;
        $defaultOptions = [
            'outDir' => 'dist',
            'entrypointAttribute' => [
                'script' => [],
                'link' => []
            ]
        ];

        /**
         * @var array{
         *  outDir: string,
         *  entrypointAttribute: array{
         *    script: array<string, mixed>,
         *    link: array<string, mixed>,
         *  },
         * } $config
         */
        $config = array_merge($defaultOptions, $options);

        $cssLinkTags = $this->createCssLinkTags($entrypoints, $config['outDir']);
        $importedChankLinkTags = $this->createImportedChankLinkTags($entrypoints, $config['outDir']);
        $entrypointTags = $this->createEntrypointTags($entrypoints, $config['outDir'], $config['entrypointAttribute']);
        $modulePreloadLinkTags = $this->createModulePreloadLinkTags($entrypoints, $config['outDir']);
        return $cssLinkTags . "\n" . $importedChankLinkTags . "\n" . $entrypointTags . "\n" . $modulePreloadLinkTags;
    }

    public function generateReactRefreshHtml(): string
    {
        if (!$this->isDevelopmentMode()) {
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
            $this->devServerUrl . '/@react-refresh'
        );
    }

    /**
     * Create script tags
     * @param string[] $entrypoints
     * @param string $outDir
     * @param array{
     *   script?: array<string, mixed>,
     *   link?: array<string, mixed>,
     * } $attributes
     * @return string
     */
    public function createEntrypointTags(
        array $entrypoints,
        string $outDir,
        array $attribute = []
    ): string {
        if ($this->isDevelopmentMode()) {
            $scriptTags = array_map(
                function (string $entrypoint) {
                    $attributes = [
                        'type' => 'module',
                        'src' => $this->devServerUrl . '/' . $entrypoint,
                    ];
                    return $this->createScriptTag($attributes);
                },
                $entrypoints
            );
            return $this->createScriptTag([
                'type' => 'module',
                'src' => $this->devServerUrl . '/@vite/client'
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
            function (string $path) use ($attribute) {
                if ($this->isCssPath($path)) {
                    $attributes = array_merge(
                        [
                            'rel' => 'stylesheet',
                            'href' => $path,
                        ],
                        $attribute['link'] ?? []
                    );
                    return $this->createLinkTag($attributes);
                }
                $attributes = array_merge(
                    [
                        'type' => 'module',
                        'src' => $path,
                    ],
                    $attribute['script'] ?? []
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
    public function createCssLinkTags(array $entrypoints, string $outDir): string
    {
        if ($this->isDevelopmentMode()) {
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
    public function createImportedChankLinkTags(array $entrypoints, string $outDir): string
    {
        if ($this->isDevelopmentMode()) {
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
    public function createModulePreloadLinkTags(
        array $entrypoints,
        string $outDir
    ): string {
        if ($this->isDevelopmentMode()) {
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

    public function isDevelopmentMode()
    {
        return $this->environment === 'development';
    }

    /**
     * Get manifest
     * @return array<string, mixed>
     */
    protected function getManifest(): array
    {
        $path = findTemplate($this->manifestPath);
        if ($this->cache->has(md5($path))) {
            return json_decode($this->cache->get(md5($path)), true);
        }
        try {
            $manifest = Storage::get($path);
            if (empty($manifest)) {
                throw new \RuntimeException('Empty Manifest.');
            }
        } catch (\Exception $e) {
            return [];
        }
        $this->cache->put(md5($path), $manifest);
        return json_decode($manifest, true);
    }

    /**
     * Parse the attributes into key="value" strings.
     *
     * @param array $attributes
     * @return array
     */
    protected function parseAttributes($attributes)
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
    protected function isCssPath($path)
    {
        return preg_match('/\.(css|less|sass|scss|styl|stylus|pcss|postcss)$/', $path) === 1;
    }
}
