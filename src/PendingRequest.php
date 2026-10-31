<?php

declare(strict_types=1);

namespace FilippoToso\WireguardHttp;

use FilippoToso\WireguardHttp\Exception\ConnectionException;
use FilippoToso\WireguardHttp\Exception\RequestException;
use FilippoToso\WireguardHttp\Exception\TunnelException;
use FilippoToso\WireguardTransport\Client;
use FilippoToso\WireguardTransport\Exception\TunnelException as TransportTunnelException;
use Psr\Http\Client\ClientExceptionInterface;
use GuzzleHttp\Psr7\Request as Psr7Request;

final class PendingRequest
{
    private const DEFAULT_TIMEOUT_SECONDS = 30.0;

    private const DEFAULT_CONNECT_TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private readonly Client $client,
        private readonly RequestBlueprint $blueprint = new RequestBlueprint(),
    ) {
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function withHeaders(array $headers): self
    {
        return $this->with($this->blueprint->withHeaders($this->stringMap($headers, 'Header names must be non-empty strings.', 'Header')));
    }

    public function withHeader(string $name, string $value): self
    {
        $this->assertHeaderName($name);

        return $this->with($this->blueprint->withHeader($name, $value));
    }

    public function accept(string $contentType): self
    {
        return $this->withHeader('Accept', $contentType);
    }

    public function acceptJson(): self
    {
        return $this->accept('application/json');
    }

    public function contentType(string $contentType): self
    {
        return $this->withHeader('Content-Type', $contentType);
    }

    public function withToken(string $token, string $type = 'Bearer'): self
    {
        $value = $type === '' ? $token : $type . ' ' . $token;

        return $this->withHeader('Authorization', $value);
    }

    public function withBasicAuth(string $username, string $password): self
    {
        return $this->withHeader('Authorization', 'Basic ' . base64_encode($username . ':' . $password));
    }

    public function withUserAgent(string $userAgent): self
    {
        return $this->withHeader('User-Agent', $userAgent);
    }

    public function withBody(string $contents, string $contentType = 'application/octet-stream'): self
    {
        return $this->with(
            $this->blueprint->withRawBody($contents)->withHeader('Content-Type', $contentType),
        );
    }

    public function asJson(): self
    {
        return $this->with($this->blueprint->usingForm(false));
    }

    public function asForm(): self
    {
        return $this->with($this->blueprint->usingForm(true));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function withQueryParameters(array $parameters): self
    {
        $blueprint = $this->blueprint;
        foreach ($parameters as $key => $value) {
            $blueprint = $blueprint->withQueryParameter((string) $key, $value);
        }

        return $this->with($blueprint);
    }

    public function baseUrl(string $url): self
    {
        return $this->with($this->blueprint->withBaseUrl($url));
    }

    public function timeout(float $seconds): self
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Timeout cannot be negative.');
        }

        return $this->with($this->blueprint->withTimeout($seconds));
    }

    public function connectTimeout(float $seconds): self
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Connection timeout cannot be negative.');
        }

        return $this->with($this->blueprint->withConnectTimeout($seconds));
    }

    /**
     * Try the request up to $times times.
     * Without $when, only transport failures are retried.
     * $when returns true to retry a failure, including an HTTP status thrown by throw().
     *
     * @param null|callable(\Throwable): bool $when
     */
    public function retry(int $times, int $sleepMilliseconds = 0, ?callable $when = null): self
    {
        if ($times < 1) {
            throw new \InvalidArgumentException('Retry attempts must be at least 1.');
        }
        if ($sleepMilliseconds < 0) {
            throw new \InvalidArgumentException('Retry delay cannot be negative.');
        }

        return $this->with($this->blueprint->withRetry($times, $sleepMilliseconds, self::closure($when)));
    }

    /**
     * Throw when the status is 400 or higher.
     * When $callback returns false, the failed response is returned instead.
     *
     * @param null|callable(Response): mixed $callback
     */
    public function throw(?callable $callback = null): self
    {
        return $this->with($this->blueprint->withThrow(self::closure($callback)));
    }

    public function withoutVerifying(): self
    {
        return $this->with($this->blueprint->withoutVerifying());
    }

    /**
     * Extra request options. Proxy and curl settings are ignored, so a call stays on the tunnel.
     *
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): self
    {
        return $this->with($this->blueprint->withOptions($options));
    }

    /**
     * @param array<string, mixed> $query
     */
    public function get(string $url, array $query = []): Response
    {
        return $this->dispatch('GET', $url, null, $query);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function head(string $url, array $query = []): Response
    {
        return $this->dispatch('HEAD', $url, null, $query);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function post(string $url, ?array $data = null): Response
    {
        return $this->dispatch('POST', $url, $data, []);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function put(string $url, ?array $data = null): Response
    {
        return $this->dispatch('PUT', $url, $data, []);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function patch(string $url, ?array $data = null): Response
    {
        return $this->dispatch('PATCH', $url, $data, []);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function delete(string $url, ?array $data = null): Response
    {
        return $this->dispatch('DELETE', $url, $data, []);
    }

    /**
     * Send a request, using withBody() when no array body is passed to a verb.
     *
     * @param array<string, mixed> $query
     */
    public function send(string $method, string $url, array $query = []): Response
    {
        return $this->dispatch($method, $url, null, $query);
    }

    public function disconnect(): void
    {
        $this->client->disconnect();
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $query
     */
    private function dispatch(string $method, string $url, ?array $data, array $query): Response
    {
        $attempts = $this->blueprint->retries ?? 1;
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return $this->attempt($method, $url, $data, $query);
            } catch (ConnectionException | RequestException $exception) {
                if ($attempt >= $attempts || !$this->shouldRetry($exception)) {
                    throw $exception;
                }
                if ($this->blueprint->retryDelayMilliseconds > 0) {
                    usleep($this->blueprint->retryDelayMilliseconds * 1000);
                }
            }
        }
    }

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $query
     */
    private function attempt(string $method, string $url, ?array $data, array $query): Response
    {
        [$absolute, $existingQuery] = $this->prepareUrl($url);
        $extra = $this->blueprint->options;
        $mergedQuery = array_merge($existingQuery, $this->blueprint->query, $query);
        $optionQuery = $extra['query'] ?? null;
        if (is_array($optionQuery)) {
            foreach ($optionQuery as $key => $value) {
                $mergedQuery[(string) $key] = $value;
            }
        }
        if ($mergedQuery !== []) {
            $absolute .= '?' . http_build_query($mergedQuery);
        }

        $headers = $this->blueprint->headers;
        $extraHeaders = $extra['headers'] ?? null;
        if (is_array($extraHeaders)) {
            foreach ($extraHeaders as $name => $value) {
                if (is_string($name) && $name !== '' && (is_string($value) || is_int($value) || is_float($value))) {
                    $headers[$name] = (string) $value;
                }
            }
        }

        $body = null;
        $contentType = null;
        $includeBody = !in_array(strtoupper($method), ['GET', 'HEAD'], true);
        if ($includeBody && $data !== null) {
            if ($this->blueprint->asForm) {
                $body = http_build_query($data);
                $contentType = 'application/x-www-form-urlencoded';
            } else {
                $encoded = json_encode($data);
                if ($encoded === false) {
                    throw new \InvalidArgumentException('The request body could not be encoded as JSON.');
                }
                $body = $encoded;
                $contentType = 'application/json';
            }
        } elseif ($includeBody && is_string($extra['body'] ?? null)) {
            $body = $extra['body'];
        } elseif ($includeBody && $this->blueprint->body !== null) {
            $body = $this->blueprint->body;
        }
        if ($contentType !== null) {
            foreach (array_keys($headers) as $existing) {
                if (strcasecmp($existing, 'Content-Type') === 0) {
                    unset($headers[$existing]);
                }
            }
            $headers['Content-Type'] = $contentType;
        }

        $verify = $this->blueprint->verify;
        if (array_key_exists('verify', $extra)) {
            $verify = $extra['verify'] !== false;
        }

        $request = new Psr7Request($method, $absolute, $headers, $body ?? '');
        try {
            $psr = $this->client
                ->withTimeout($this->number($extra['timeout'] ?? null, $this->blueprint->timeout ?? self::DEFAULT_TIMEOUT_SECONDS))
                ->withConnectTimeout($this->number($extra['connect_timeout'] ?? null, $this->blueprint->connectTimeout ?? self::DEFAULT_CONNECT_TIMEOUT_SECONDS))
                ->withVerify($verify)
                ->withFollowRedirects(($extra['allow_redirects'] ?? true) !== false)
                ->sendRequest($request);
        } catch (TransportTunnelException $exception) {
            throw new TunnelException($exception->getMessage(), previous: $exception);
        } catch (ClientExceptionInterface $exception) {
            throw new ConnectionException($exception->getMessage(), previous: $exception);
        }
        $response = new Response($psr);
        if ($this->blueprint->throw && $response->failed()) {
            $callback = $this->blueprint->throwIf;
            if ($callback === null || $callback($response) !== false) {
                throw new RequestException($response);
            }
        }

        return $response;
    }

    private function number(mixed $value, float $fallback): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return $fallback;
    }

    private function shouldRetry(\Throwable $exception): bool
    {
        $when = $this->blueprint->retryWhen;
        if ($when !== null) {
            return (bool) $when($exception);
        }

        return $exception instanceof ConnectionException;
    }

    private static function closure(?callable $callback): ?\Closure
    {
        if ($callback === null) {
            return null;
        }

        return $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function prepareUrl(string $url): array
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            $base = $this->blueprint->baseUrl;
            if ($base === null || $base === '') {
                throw new \InvalidArgumentException('A relative URL requires baseUrl().');
            }
            $url = rtrim($base, '/') . '/' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException("URL [{$url}] is not absolute.");
        }

        /** @var array<string, mixed> $existing */
        $existing = [];
        if (isset($parts['query'])) {
            $parsed = [];
            parse_str($parts['query'], $parsed);
            foreach ($parsed as $key => $value) {
                $existing[(string) $key] = $value;
            }
        }

        $host = $parts['host'];
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            throw new ConnectionException("IPv6 destination [{$host}] is not supported.");
        }
        $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }
        if (isset($parts['user'])) {
            $userInfo = $parts['user'];
            if (isset($parts['pass'])) {
                $userInfo .= ':' . $parts['pass'];
            }
            $authority = $userInfo . '@' . $authority;
        }

        $path = $parts['path'] ?? '';

        return [$parts['scheme'] . '://' . $authority . $path, $existing];
    }

    private function with(RequestBlueprint $blueprint): self
    {
        return new self($this->client, $blueprint);
    }

    /**
     * @param array<mixed, mixed> $values
     * @return array<string, string>
     */
    private function stringMap(array $values, string $nameMessage, string $valueLabel): array
    {
        $normalized = [];
        foreach ($values as $name => $value) {
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException($nameMessage);
            }
            if (!is_string($value)) {
                throw new \InvalidArgumentException("{$valueLabel} [{$name}] must be a string.");
            }
            $normalized[$name] = $value;
        }

        return $normalized;
    }

    private function assertHeaderName(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Header names must be non-empty strings.');
        }
    }
}
