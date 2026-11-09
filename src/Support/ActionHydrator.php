<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

use Trianity\Vpos\Api\ThreeDS\Actions;
use Trianity\Vpos\Api\ThreeDS\AuthenticateAction;
use Trianity\Vpos\Api\ThreeDS\Endpoint;
use Trianity\Vpos\Api\ThreeDS\FingerprintAction;
use Trianity\Vpos\Api\ThreeDS\SdkChallenge;
use Trianity\Vpos\Api\ThreeDS\SdkInitialization;
use Trianity\Vpos\Exceptions\MalformedActionException;

final class ActionHydrator
{
    /** @param array<string, mixed> $payload */
    public function hydrate(array $payload): Actions
    {
        $this->only($payload, ['fingerprint', 'authenticate'], 'actions');
        $fingerprint = isset($payload['fingerprint'])
            ? $this->fingerprint($this->object($payload['fingerprint'], 'actions.fingerprint'))
            : null;
        $authenticate = isset($payload['authenticate'])
            ? $this->authenticate($this->object($payload['authenticate'], 'actions.authenticate'))
            : null;

        if ($fingerprint === null && $authenticate === null) {
            throw new MalformedActionException('The gateway actions object is empty.');
        }

        return new Actions($fingerprint, $authenticate);
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(array $payload): FingerprintAction
    {
        $this->only($payload, ['browserInit', 'sdkInit'], 'actions.fingerprint');
        $browser = isset($payload['browserInit'])
            ? $this->endpoint($this->object($payload['browserInit'], 'actions.fingerprint.browserInit'))
            : null;
        $sdk = isset($payload['sdkInit'])
            ? $this->sdkInit($this->object($payload['sdkInit'], 'actions.fingerprint.sdkInit'))
            : null;
        if (($browser === null) === ($sdk === null)) {
            throw new MalformedActionException('Fingerprint actions must contain exactly one browserInit or sdkInit value.');
        }

        return new FingerprintAction($browser, $sdk);
    }

    /** @param array<string, mixed> $payload */
    private function authenticate(array $payload): AuthenticateAction
    {
        $this->only($payload, ['browserChallenge', 'sdkChallenge'], 'actions.authenticate');
        $browser = isset($payload['browserChallenge'])
            ? $this->endpoint($this->object($payload['browserChallenge'], 'actions.authenticate.browserChallenge'))
            : null;
        $sdk = isset($payload['sdkChallenge'])
            ? $this->sdkChallenge($this->object($payload['sdkChallenge'], 'actions.authenticate.sdkChallenge'))
            : null;
        if (($browser === null) === ($sdk === null)) {
            throw new MalformedActionException('Authenticate actions must contain exactly one browserChallenge or sdkChallenge value.');
        }

        return new AuthenticateAction($browser, $sdk);
    }

    /** @param array<string, mixed> $payload */
    private function endpoint(array $payload): Endpoint
    {
        $this->only($payload, ['url', 'method', 'vars'], 'endpoint');
        $url = $payload['url'] ?? null;
        $method = $payload['method'] ?? 'GET';
        $vars = $payload['vars'] ?? [];
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_string($method) || ! in_array($method, ['GET', 'POST'], true)
            || ! is_array($vars) || ($vars !== [] && array_is_list($vars))) {
            throw new MalformedActionException('The gateway action endpoint is invalid.');
        }
        foreach ($vars as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                throw new MalformedActionException('The gateway action endpoint vars must be scalar key-value pairs.');
            }
            $vars[$key] = (string) $value;
        }

        return new Endpoint($url, $method, $vars);
    }

    /** @param array<string, mixed> $payload */
    private function sdkInit(array $payload): SdkInitialization
    {
        $fields = $this->strings($payload, ['directoryServerID', 'schemeId', 'messageVersion'], 'sdkInit');

        return new SdkInitialization(...$fields);
    }

    /** @param array<string, mixed> $payload */
    private function sdkChallenge(array $payload): SdkChallenge
    {
        $fields = $this->strings(
            $payload,
            ['threeDSServerTransID', 'acsReferenceNumber', 'acsTransID', 'acsSignedContent'],
            'sdkChallenge',
        );

        return new SdkChallenge(...$fields);
    }

    /** @param list<string> $allowed @param array<string, mixed> $payload */
    private function only(array $payload, array $allowed, string $path): void
    {
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw new MalformedActionException("The gateway [{$path}] object contains unknown fields.");
        }
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new MalformedActionException("The gateway [{$path}] value must be an object.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload @param list<string> $names @return list<string> */
    private function strings(array $payload, array $names, string $path): array
    {
        $this->only($payload, $names, $path);
        $values = [];
        foreach ($names as $name) {
            if (! isset($payload[$name]) || ! is_string($payload[$name]) || $payload[$name] === '') {
                throw new MalformedActionException("The gateway [{$path}.{$name}] value is invalid.");
            }
            $values[] = $payload[$name];
        }

        return $values;
    }
}
