<?php

declare(strict_types=1);

namespace SWBackupIt\Core\Storage;

use SWBackupIt\Core\Exception\ConfigurationException;

/**
 * SSRF protection for user configurable endpoints (CODE-STYLE §4.6).
 *
 * Hosts resolving to loopback, private, link-local (cloud metadata 169.254.169.254) or reserved ranges are
 * refused unless the storage target explicitly opts in (`allow_private_network`, e.g. an on-premise MinIO).
 * Only ports 1-65535 are allowed, userinfo in URLs is rejected, redirects are disabled by the adapters.
 */
final class EndpointPolicy
{
    public static function assertAllowedUrl(string $url, bool $allowPrivate): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['scheme'])) {
            throw new ConfigurationException('The endpoint URL is invalid.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ConfigurationException('Credentials in the endpoint URL are not allowed.');
        }
        if (!in_array(strtolower($parts['scheme']), ['https', 'http'], true)) {
            throw new ConfigurationException('The endpoint must use http or https.');
        }
        if (strtolower($parts['scheme']) === 'http' && !$allowPrivate) {
            throw new ConfigurationException('Unencrypted http endpoints require the private network opt-in.');
        }
        self::assertAllowedHost($parts['host'], $allowPrivate, $parts['port'] ?? null);
    }

    public static function assertAllowedHost(string $host, bool $allowPrivate, ?int $port = null): void
    {
        if ($host === '' || preg_match('/[\s\/@\\\\]/', $host) === 1) {
            throw new ConfigurationException('The host name is invalid.');
        }
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new ConfigurationException('The port is invalid.');
        }
        if ($allowPrivate) {
            return;
        }
        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === []) {
            throw new ConfigurationException('The host name could not be resolved.');
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new ConfigurationException('The endpoint points to a private or reserved network address.');
            }
        }
    }
}
