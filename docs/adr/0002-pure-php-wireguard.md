# Carry HTTP inside a pure PHP WireGuard session

The client has to send its own HTTP calls through a WireGuard network without a wireproxy binary, root, or a TUN device. The process speaks Noise and ChaCha20-Poly1305 itself, then a small IPv4 TCP stack, and gives that byte stream to OpenSSL for TLS 1.2. Host routes stay unchanged because no other program can see the tunnel. wg-quick hooks are ignored, and only IPv4 destinations are carried.
