<?php

declare(strict_types=1);

namespace FilippoToso\WireguardHttp;

use FilippoToso\WireguardHttp\Exception\ConnectionException;
use FilippoToso\WireguardHttp\Exception\InvalidConfigException;
use FilippoToso\WireguardHttp\Exception\TunnelException;
use FilippoToso\WireguardTransport\Client;
use FilippoToso\WireguardTransport\Exception\ConnectionException as TransportConnectionException;
use FilippoToso\WireguardTransport\Exception\InvalidConfigException as TransportInvalidConfigException;
use FilippoToso\WireguardTransport\Exception\TunnelException as TransportTunnelException;
use FilippoToso\WireguardTransport\WireguardTransport;

final class WireguardHttp
{
    /**
     * Connect using a wg-quick configuration file and return a pending request
     * bound to that tunnel.
     *
     * @param array<string, mixed> $options
     */
    public static function withConfigFile(string $path, array $options = []): PendingRequest
    {
        self::rejectUnknown($options);

        return new PendingRequest(self::open(static fn (): Client => WireguardTransport::withConfigFile($path)));
    }

    /**
     * Connect using the contents of a wg-quick configuration.
     *
     * @param array<string, mixed> $options
     */
    public static function withConfig(string $contents, array $options = []): PendingRequest
    {
        self::rejectUnknown($options);

        return new PendingRequest(self::open(static fn (): Client => WireguardTransport::withConfig($contents)));
    }

    /**
     * @param callable(): Client $connect
     */
    private static function open(callable $connect): Client
    {
        try {
            return $connect();
        } catch (TransportInvalidConfigException $exception) {
            throw new InvalidConfigException($exception->getMessage(), previous: $exception);
        } catch (TransportTunnelException $exception) {
            throw new TunnelException($exception->getMessage(), previous: $exception);
        } catch (TransportConnectionException $exception) {
            throw new ConnectionException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function rejectUnknown(array $options): void
    {
        if ($options === []) {
            return;
        }

        throw new \InvalidArgumentException('Unknown option [' . implode(', ', array_map(strval(...), array_keys($options))) . '].');
    }
}
