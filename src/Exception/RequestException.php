<?php

declare(strict_types=1);

namespace FilippoToso\WireguardHttp\Exception;

use FilippoToso\WireguardHttp\Response;

final class RequestException extends WireguardHttpException
{
    public function __construct(private readonly Response $response)
    {
        parent::__construct(sprintf('HTTP request returned status %d.', $response->status()));
    }

    public function response(): Response
    {
        return $this->response;
    }
}
