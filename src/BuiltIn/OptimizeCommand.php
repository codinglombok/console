<?php

declare(strict_types=1);

namespace LombokClarion\Console\BuiltIn;

use LombokClarion\Console\Command;
use LombokClarion\Container\Container;
use LombokClarion\Container\ContainerCompiler;
use LombokClarion\Config\ConfigCompiler;
use LombokClarion\View\AssetPublisher;

/**
 * `lombokclarion optimize` — the single build step that produces
 * services.compiled.php and config.compiled.php ahead of deploy, so
 * request-time boot does zero reflection and zero schema parsing (§5).
 */
final class OptimizeCommand implements Command
{
    /**
     * @param list<class-string> $extraRootIds classes only referenced from
     *        the route table (controllers), so the container compiler
     *        knows to compile them too
     * @param list<class-string> $externallyProvided ids supplied via
     *        CompiledContainer::instance() at request boot (e.g. PDO) —
     *        see ContainerCompiler
     */
    public function __construct(
        private readonly Container $devContainer,
        private readonly string $servicesOutputPath,
        private readonly array $extraRootIds = [],
        private readonly array $externallyProvided = [],
        private readonly ?array $configSchema = null,
        private readonly ?string $configOutputPath = null,
        /** @var array<string, string> logical name => source path */
        private readonly array $assets = [],
        private readonly ?string $publicAssetsDir = null,
        private readonly ?string $assetManifestPath = null,
    ) {
    }

    public static function signature(): string
    {
        return 'optimize';
    }

    public function run(array $arguments): int
    {
        (new ContainerCompiler())->compileToFile(
            $this->devContainer,
            $this->servicesOutputPath,
            $this->extraRootIds,
            $this->externallyProvided
        );
        echo "Wrote {$this->servicesOutputPath}\n";

        if ($this->configSchema !== null && $this->configOutputPath !== null) {
            (new ConfigCompiler())->compileToFile($this->configSchema, $this->configOutputPath);
            echo "Wrote {$this->configOutputPath}\n";
        }

        if ($this->assets !== [] && $this->publicAssetsDir !== null && $this->assetManifestPath !== null) {
            $manifest = (new AssetPublisher())->publish($this->assets, $this->publicAssetsDir, $this->assetManifestPath);
            foreach ($manifest as $logical => $hashed) {
                echo "Published asset: $logical => $hashed\n";
            }
            echo "Wrote {$this->assetManifestPath}\n";
        }

        return 0;
    }
}
