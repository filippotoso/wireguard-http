<?php

declare(strict_types=1);

namespace FilippoToso\WireguardHttp;

use Psr\Http\Message\ResponseInterface;
use FilippoToso\WireguardHttp\Exception\RequestException;

final class Response
{
    private string $body;

    public function __construct(private readonly ResponseInterface $psr)
    {
        $this->body = (string) $psr->getBody();
        if ($psr->getBody()->isSeekable()) {
            $psr->getBody()->rewind();
        }
    }

    public function status(): int
    {
        return $this->psr->getStatusCode();
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Decode the body as JSON. Pass a dot path to read one value.
     * An empty body decodes to null. A missing path returns $default.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (trim($this->body) === '') {
            return $key === null ? null : $default;
        }

        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        if ($key === null) {
            return $decoded;
        }

        if (!is_array($decoded)) {
            return $default;
        }

        $cursor = $decoded;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public function ok(): bool
    {
        return $this->status() === 200;
    }

    public function successful(): bool
    {
        $status = $this->status();

        return $status >= 200 && $status < 300;
    }

    public function redirect(): bool
    {
        $status = $this->status();

        return $status >= 300 && $status < 400;
    }

    public function failed(): bool
    {
        return $this->status() >= 400;
    }

    public function clientError(): bool
    {
        $status = $this->status();

        return $status >= 400 && $status < 500;
    }

    public function serverError(): bool
    {
        $status = $this->status();

        return $status >= 500 && $status < 600;
    }

    public function header(string $name): string
    {
        return $this->psr->getHeaderLine($name);
    }

    /**
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->psr->getHeaders() as $name => $values) {
            $normalized = [];
            foreach ($values as $value) {
                $normalized[] = $value;
            }
            $headers[$name] = $normalized;
        }

        return $headers;
    }

    /**
     * Throw when the status is 400 or higher.
     * When $callback returns false, the failed response is returned instead.
     */
    public function throw(?callable $callback = null): self
    {
        if ($this->failed() && ($callback === null || $callback($this) !== false)) {
            throw new RequestException($this);
        }

        return $this;
    }

    public function toPsrResponse(): ResponseInterface
    {
        return $this->psr;
    }
}
