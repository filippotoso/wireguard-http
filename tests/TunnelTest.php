<?php

declare(strict_types=1);

namespace FilippoToso\WireguardHttp\Tests;

use PHPUnit\Framework\TestCase;
use FilippoToso\WireguardHttp\Exception\ConnectionException;
use FilippoToso\WireguardHttp\Exception\InvalidConfigException;
use FilippoToso\WireguardHttp\Exception\TunnelException;
use FilippoToso\WireguardHttp\Tests\Support\TunnelOrigin;
use FilippoToso\WireguardHttp\WireguardHttp;

final class TunnelTest extends TestCase
{
    private static TunnelOrigin $origin;

    public static function setUpBeforeClass(): void
    {
        self::$origin = TunnelOrigin::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$origin->stop();
    }

    public function test_a_config_file_sends_http_through_the_tunnel(): void
    {
        $path = $this->writeConfig(self::$origin->clientConfig);
        $http = WireguardHttp::withConfigFile($path);

        try {
            $response = $http->get(self::$origin->url('/hello'));
            self::assertSame('/hello', $response->json('path'));

            $again = $http->withToken('secret-token')->get(self::$origin->url('/secure'));
            self::assertSame('Bearer secret-token', $again->json('headers.authorization'));

            $http->disconnect();
            $this->expectException(TunnelException::class);
            $this->expectExceptionMessage('disconnected');
            $http->get(self::$origin->url('/hello'));
        } finally {
            $http->disconnect();
            unlink($path);
        }
    }

    public function test_config_text_uses_the_same_tunnel(): void
    {
        $http = WireguardHttp::withConfig(self::$origin->clientConfig);

        try {
            self::assertSame('GET', $http->get(self::$origin->url('/hello'))->json('method'));
            self::assertSame('POST', $http->post(self::$origin->url('/items'), ['name' => 'Ada'])->json('method'));
        } finally {
            $http->disconnect();
        }
    }

    public function test_an_invalid_config_does_not_start_the_tunnel(): void
    {
        $path = $this->writeConfig("[Interface]\nPrivateKey = not-a-key\n");

        try {
            $this->expectException(InvalidConfigException::class);

            WireguardHttp::withConfigFile($path);
        } finally {
            unlink($path);
        }
    }

    public function test_unknown_client_options_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option [nope]');

        WireguardHttp::withConfig(self::$origin->clientConfig, ['nope' => true]);
    }

    public function test_a_missing_key_environment_variable_fails_before_the_handshake(): void
    {
        putenv('WG_HTTP_TEST_PRIVATE_KEY');
        $peer = base64_encode(sodium_crypto_scalarmult_base(random_bytes(32)));

        $this->expectException(TunnelException::class);
        $this->expectExceptionMessage('WG_HTTP_TEST_PRIVATE_KEY');

        WireguardHttp::withConfig(<<<WG
            [Interface]
            PrivateKey = \$WG_HTTP_TEST_PRIVATE_KEY
            Address = 10.0.0.1/32

            [Peer]
            PublicKey = {$peer}
            AllowedIPs = 0.0.0.0/0
            Endpoint = 127.0.0.1:1
            WG);
    }

    public function test_a_hostname_without_dns_is_rejected(): void
    {
        $config = preg_replace('/^DNS = .*$/m', '', self::$origin->clientConfig);
        self::assertIsString($config);
        $http = WireguardHttp::withConfig($config);

        try {
            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('DNS');
            $http->connectTimeout(1)->get('http://wg-http.invalid/hello');
        } finally {
            $http->disconnect();
        }
    }

    public function test_the_handshake_times_out_when_the_peer_does_not_answer(): void
    {
        $secret = random_bytes(32);
        $peer = base64_encode(sodium_crypto_scalarmult_base(random_bytes(32)));
        $http = WireguardHttp::withConfig(sprintf(
            "[Interface]\nPrivateKey = %s\nAddress = 10.0.0.1/32\n\n[Peer]\nPublicKey = %s\nAllowedIPs = 0.0.0.0/0\nEndpoint = 127.0.0.1:1\n",
            base64_encode($secret),
            $peer,
        ));

        try {
            $this->expectException(ConnectionException::class);
            $http->connectTimeout(0.4)->get('http://10.0.0.2/');
        } finally {
            $http->disconnect();
        }
    }

    private function writeConfig(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wghttp-cfg-');
        if ($path === false) {
            self::fail('Could not create a config file.');
        }
        file_put_contents($path, $contents);

        return $path;
    }
}
