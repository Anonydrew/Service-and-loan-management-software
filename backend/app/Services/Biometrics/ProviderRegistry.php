<?php

declare(strict_types=1);

namespace App\Services\Biometrics;

use App\Services\Biometrics\Providers\FingerprintBridgeProvider;
use App\Services\Biometrics\Providers\SimulatedProvider;
use App\Services\Biometrics\Providers\UnconfiguredProvider;
use InvalidArgumentException;

/**
 * Resolves the configured provider for a modality. To add a real facial
 * verification provider, implement BiometricProvider and add a case here.
 */
final class ProviderRegistry
{
    public function for(string $modality): BiometricProvider
    {
        $driver = (string) config("biometrics.{$modality}.driver", 'unconfigured');

        return match (true) {
            $driver === 'simulated' && $this->simulationAllowed() => new SimulatedProvider($modality),
            $driver === 'bridge' && $modality === 'fingerprint' => new FingerprintBridgeProvider,
            in_array($modality, ['face', 'fingerprint'], true) => new UnconfiguredProvider($modality),
            default => throw new InvalidArgumentException("Unknown biometric modality [{$modality}]."),
        };
    }

    public function byName(string $name): ?BiometricProvider
    {
        foreach (['face', 'fingerprint'] as $modality) {
            $provider = $this->for($modality);
            if ($provider->name() === $name) {
                return $provider;
            }
        }

        return null;
    }

    public function simulationAllowed(): bool
    {
        return ! app()->isProduction() && (bool) config('biometrics.allow_simulated');
    }

    /** @return array<string, array{provider: string, configured: bool, simulated: bool}> */
    public function status(): array
    {
        $out = [];
        foreach (['face', 'fingerprint'] as $modality) {
            $p = $this->for($modality);
            $out[$modality] = [
                'provider' => $p->name(),
                'configured' => $p->isConfigured(),
                'simulated' => $p->isSimulated(),
                'method' => $p instanceof FingerprintBridgeProvider ? $p->mode() : null,
            ];
        }

        return $out;
    }
}
