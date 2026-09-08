<?php

namespace App\Services\Local;

use App\Enums\InstallState;
use App\Enums\LocalProjectType;

/** What ProjectTypeDetector concluded about one directory. */
final class ProjectSignature
{
    /**
     * @param  string  $marker  the file that decided the type — shown in the UI so
     *                          a wrong guess can be argued with
     * @param  list<string>  $blockers  everything standing between this project and
     *                                  a working start, not just the first thing found
     * @param  array<string,string>  $scripts  package.json scripts, kept for PortResolver
     */
    public function __construct(
        public readonly LocalProjectType $type,
        public readonly string $name,
        public readonly ?string $declaredName,
        public readonly string $marker,
        public readonly ?string $framework = null,
        public readonly ?string $version = null,
        public readonly bool $hasDocker = false,
        public readonly InstallState $installState = InstallState::Ready,
        public readonly array $blockers = [],
        public readonly ?string $packageManager = null,
        public readonly ?string $devScript = null,
    ) {}

    public function isRunnable(): bool
    {
        return $this->installState === InstallState::Ready;
    }

    public function describe(): string
    {
        return $this->framework === null
            ? $this->type->label()
            : $this->type->label().' ('.$this->framework.')';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'name' => $this->name,
            'declared_name' => $this->declaredName,
            'marker' => $this->marker,
            'framework' => $this->framework,
            'version' => $this->version,
            'has_docker' => $this->hasDocker,
            'install_state' => $this->installState->value,
            'blockers' => $this->blockers,
            'package_manager' => $this->packageManager,
            'dev_script' => $this->devScript,
        ];
    }
}
