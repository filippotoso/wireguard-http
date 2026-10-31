<?php

declare(strict_types=1);

namespace FilippoToso\WireguardHttp;

/**
 * Immutable options for one not-yet-sent HTTP call.
 *
 * @internal
 */
final class RequestBlueprint
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param array<string, mixed> $options
     * @param null|\Closure(\Throwable): bool $retryWhen
     * @param null|\Closure(Response): mixed $throwIf
     */
    public function __construct(
        public array $headers = [],
        public ?string $body = null,
        public bool $asForm = false,
        public array $query = [],
        public ?string $baseUrl = null,
        public ?float $timeout = null,
        public ?float $connectTimeout = null,
        public ?int $retries = null,
        public int $retryDelayMilliseconds = 0,
        public ?\Closure $retryWhen = null,
        public bool $throw = false,
        public ?\Closure $throwIf = null,
        public bool $verify = true,
        public array $options = [],
    ) {
    }

    public function withHeader(string $name, string $value): self
    {
        $copy = clone $this;
        foreach (array_keys($copy->headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($copy->headers[$existing]);
            }
        }
        $copy->headers[$name] = $value;

        return $copy;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $copy = $this;
        foreach ($headers as $name => $value) {
            $copy = $copy->withHeader($name, $value);
        }

        return $copy;
    }

    public function withRawBody(string $contents): self
    {
        $copy = clone $this;
        $copy->body = $contents;

        return $copy;
    }

    public function usingForm(bool $asForm): self
    {
        $copy = clone $this;
        $copy->asForm = $asForm;

        return $copy;
    }

    public function withQueryParameter(string $key, mixed $value): self
    {
        $copy = clone $this;
        $copy->query[$key] = $value;

        return $copy;
    }

    public function withBaseUrl(string $url): self
    {
        $copy = clone $this;
        $copy->baseUrl = $url;

        return $copy;
    }

    public function withTimeout(float $seconds): self
    {
        $copy = clone $this;
        $copy->timeout = $seconds;

        return $copy;
    }

    public function withConnectTimeout(float $seconds): self
    {
        $copy = clone $this;
        $copy->connectTimeout = $seconds;

        return $copy;
    }

    /**
     * @param null|\Closure(\Throwable): bool $when
     */
    public function withRetry(int $times, int $sleepMilliseconds, ?\Closure $when): self
    {
        $copy = clone $this;
        $copy->retries = $times;
        $copy->retryDelayMilliseconds = $sleepMilliseconds;
        $copy->retryWhen = $when;

        return $copy;
    }

    /**
     * @param null|\Closure(Response): mixed $throwIf
     */
    public function withThrow(?\Closure $throwIf): self
    {
        $copy = clone $this;
        $copy->throw = true;
        $copy->throwIf = $throwIf;

        return $copy;
    }

    public function withoutVerifying(): self
    {
        $copy = clone $this;
        $copy->verify = false;

        return $copy;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): self
    {
        $copy = clone $this;
        $copy->options = array_replace($copy->options, $options);

        return $copy;
    }
}
