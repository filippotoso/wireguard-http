Superseded by [ADR 0002](0002-pure-php-wireguard.md).

# Send HTTP through a userspace WireGuard proxy

The library has to carry only its own HTTP calls through WireGuard. Bringing up a kernel interface with wg-quick needs root and, when AllowedIPs is 0.0.0.0/0, replaces the host default route for every process. Reimplementing the WireGuard handshake and a TCP stack in PHP was rejected. wireproxy already runs WireGuard in userspace and exposes a local SOCKS5 proxy, so the client gives that proxy a wg-quick configuration, waits until it listens on 127.0.0.1, and sends requests there with hostnames resolved inside the tunnel. The proxy requires a random username and password. wg-quick hooks such as PreUp and PostUp are not executed, and other programs stay off the tunnel unless they use the same proxy.
