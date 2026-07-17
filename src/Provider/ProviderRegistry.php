<?php

declare(strict_types=1);

namespace FancyGit\Provider;

final class ProviderRegistry
{
    /** @var array<string, GitProvider> */
    private array $providers = [];

    public function register(GitProvider $provider): self
    {
        $this->providers[$provider->kind()] = $provider;

        return $this;
    }

    public function get(string $kind): ?GitProvider
    {
        return $this->providers[$kind] ?? null;
    }

    /** @return array{provider:GitProvider,ref:array<string,mixed>}|null */
    public function identify(array $remote): ?array
    {
        foreach ($this->providers as $provider) {
            if (($ref = $provider->identify($remote)) !== null) {
                return compact('provider', 'ref');
            }
        }

        return null;
    }
}
