# WireGuard HTTP

A PHP HTTP client with a Laravel-style API. Give it a WireGuard configuration and it carries those requests through the tunnel.

The handshake, encryption, and TCP stack live in `filippo-toso/wireguard-transport`, which this client uses. Other programs keep the host's normal routes. Nothing here needs root, a TUN device, or a separate WireGuard binary.

## Requirements

- PHP 8.2 or newer, with the openssl and sodium extensions

## Install

```bash
composer require filippo-toso/wireguard-http
```

## Usage

```php
use FilippoToso\WireguardHttp\WireguardHttp;

$http = WireguardHttp::withConfigFile(__DIR__ . '/wg0.conf');

$response = $http->get('https://ifconfig.co/json');

if ($response->successful()) {
    $address = $response->json('ip');
}

$created = $http
    ->withToken('secret-token')
    ->acceptJson()
    ->timeout(5)
    ->post('https://api.example.com/items', [
        'name' => 'Ada',
    ]);

$created->throw();

$http->disconnect();
```

`withConfig()` accepts the configuration text instead of a file.

Array bodies are sent as JSON. `asForm()` sends `application/x-www-form-urlencoded`. `withBody($xml, 'application/xml')->post($url)` sends that raw body. Passing an array to the verb replaces `withBody()`. `get()` and `head()` take query parameters as the second argument. A failed status stays on the response until you call `throw()` on the pending request or on the response.

Hostnames in request URLs are resolved inside the tunnel, using the DNS addresses from the configuration. The WireGuard endpoint hostname is resolved on the host, because that UDP socket is how the tunnel itself is reached.

## Configuration files

Pass a normal wg-quick file: one `[Interface]` and at least one `[Peer]`, including `PrivateKey`, `Address`, `PublicKey`, `AllowedIPs`, and `Endpoint`. Repeated `Address`, `DNS`, and `AllowedIPs` lines are joined. A bare IP is treated as `/32` or `/128`. `DNS`, `MTU`, `ListenPort`, `PresharedKey`, and `PersistentKeepalive` are kept. DNS search domains are ignored. `Table`, `FwMark`, `SaveConfig`, and the `PreUp` / `PostUp` / `PreDown` / `PostDown` hooks are accepted and ignored, because this process does not run shell hooks. `CheckAlive` and `CheckAliveInterval` are accepted and ignored.

A key may be an environment-variable reference such as `$WG_PRIVATE_KEY` or `${WG_PUBLIC_KEY}`. The variable is read when the tunnel starts.

`disconnect()` drops the session. Further requests on that client fail. The tunnel also stops when the client is destroyed.

## Limitations

- Only this client's HTTP calls enter the tunnel.
- Traffic inside the tunnel is IPv4. IPv6 destinations and IPv6 WireGuard endpoints are rejected.
- TLS is 1.2, terminated by OpenSSL. `withoutVerifying()` skips certificate checks.
- HTTP is 1.1, and redirects are followed unless `withOptions(['allow_redirects' => false])` is set.
- AmneziaWG and other non-standard keys are rejected.
