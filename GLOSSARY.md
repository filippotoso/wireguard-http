# WireGuard HTTP

A PHP client that sends HTTP calls through a WireGuard tunnel.

## Language

**WireGuard configuration**:
The wg-quick description of one interface and the peers it connects to.
_Avoid_: profile, vpn settings, tunnel file

**Peer**:
A remote WireGuard party named by a configuration.
_Avoid_: server, node, endpoint

**Tunnel**:
A live WireGuard session this library can send traffic through.
_Avoid_: connection, interface, adapter

**Pending request**:
An HTTP call that has not been sent yet, bound to a tunnel.
_Avoid_: request builder, client

**Response**:
The HTTP response that came back through a tunnel.
_Avoid_: result, reply
