<?php

declare(strict_types=1);

namespace FilippoToso\WireguardHttp\Tests;

use PHPUnit\Framework\TestCase;
use FilippoToso\WireguardHttp\Exception\ConnectionException;
use FilippoToso\WireguardHttp\Exception\RequestException;
use FilippoToso\WireguardHttp\PendingRequest;
use FilippoToso\WireguardHttp\Response;
use FilippoToso\WireguardHttp\Tests\Support\TunnelOrigin;
use FilippoToso\WireguardHttp\WireguardHttp;

final class HttpClientTest extends TestCase
{
    private static TunnelOrigin $origin;

    private static PendingRequest $client;

    private int $mark = 0;

    public static function setUpBeforeClass(): void
    {
        self::$origin = TunnelOrigin::start();
        self::$client = WireguardHttp::withConfig(self::$origin->clientConfig);
    }

    public static function tearDownAfterClass(): void
    {
        self::$client->disconnect();
        self::$origin->stop();
    }

    protected function setUp(): void
    {
        $this->mark = self::$origin->mark();
    }

    public function test_requests_are_sent_through_the_tunnel_and_resolved_there(): void
    {
        $response = $this->http()->acceptJson()->get(self::$origin->url('/hello'));

        self::assertTrue($response->ok());
        self::assertTrue($response->successful());
        self::assertSame('GET', $response->json('method'));
        self::assertSame('/hello', $response->json('path'));
        self::assertSame('application/json', $response->json('headers.accept'));
    }

    public function test_post_sends_a_json_body_by_default(): void
    {
        $response = $this->http()->post(self::$origin->url('/items'), [
            'name' => 'Ada',
        ]);

        self::assertSame('POST', $response->json('method'));
        self::assertSame('{"name":"Ada"}', $response->json('body'));
        self::assertSame('application/json', $response->json('headers.content-type'));
    }

    public function test_as_form_sends_url_encoded_fields(): void
    {
        $response = $this->http()->asForm()->post(self::$origin->url('/items'), [
            'name' => 'Ada',
            'role' => 'admin',
        ]);

        self::assertSame('name=Ada&role=admin', $response->json('body'));
        self::assertStringContainsString('application/x-www-form-urlencoded', (string) $response->json('headers.content-type'));
    }

    public function test_as_json_overrides_an_earlier_form_body(): void
    {
        $response = $this->http()->asForm()->asJson()->post(self::$origin->url('/items'), [
            'name' => 'Ada',
        ]);

        self::assertSame('{"name":"Ada"}', $response->json('body'));
    }

    public function test_put_patch_and_delete_send_json_bodies(): void
    {
        $url = self::$origin->url('/items');
        $client = $this->http();

        self::assertSame('PUT', $client->put($url, ['name' => 'Ada'])->json('method'));
        self::assertSame('PATCH', $client->patch($url, ['name' => 'Grace'])->json('method'));
        self::assertSame('{"name":"Grace"}', $client->delete($url, ['name' => 'Grace'])->json('body'));
    }

    public function test_head_is_sent_without_a_body(): void
    {
        $response = $this->http()->head(self::$origin->url('/hello'));

        self::assertSame(200, $response->status());
        self::assertSame('HEAD', $this->onlyRequest()['method']);
    }

    public function test_post_without_data_sends_no_body(): void
    {
        $response = $this->http()->post(self::$origin->url('/items'));

        self::assertSame('', $response->json('body'));
    }

    public function test_with_body_is_what_post_sends_until_an_array_is_passed(): void
    {
        $url = self::$origin->url('/items');
        $raw = $this->http()->withBody('<item/>', 'application/xml')->post($url);
        $overridden = $this->http()->withBody('<item/>', 'application/xml')->post($url, ['name' => 'Ada']);

        self::assertSame('<item/>', $raw->json('body'));
        self::assertSame('application/xml', $raw->json('headers.content-type'));
        self::assertSame('{"name":"Ada"}', $overridden->json('body'));
    }

    public function test_curl_proxy_options_cannot_leave_the_tunnel(): void
    {
        $response = $this->http()
            ->withOptions([
                'curl' => [
                    CURLOPT_PROXY => '',
                    CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
                ],
                'proxy' => 'http://127.0.0.1:9',
            ])
            ->get(self::$origin->url('/hello'));

        self::assertSame('/hello', $response->json('path'));
    }

    public function test_a_raw_body_can_be_sent_with_its_content_type(): void
    {
        $response = $this->http()
            ->withBody('{"a":1}', 'application/vnd.api+json')
            ->send('POST', self::$origin->url('/items'));

        self::assertSame('{"a":1}', $response->json('body'));
        self::assertSame('application/vnd.api+json', $response->json('headers.content-type'));
    }

    public function test_bearer_and_basic_authorization_headers_are_sent(): void
    {
        $url = self::$origin->url('/secure');
        $client = $this->http();

        $bearer = $client->withToken('secret-token')->get($url);
        self::assertSame('Bearer secret-token', $bearer->json('headers.authorization'));

        $basic = $client->withBasicAuth('ada', 'secret')->get($url);
        self::assertSame('Basic YWRhOnNlY3JldA==', $basic->json('headers.authorization'));
    }

    public function test_headers_user_agent_and_later_modifiers_do_not_leak_backward(): void
    {
        $base = $this->http()->withHeader('X-A', '1')->withUserAgent('wg-http');
        $base->withHeaders(['X-B' => '2', 'X-A' => 'replaced'])->get(self::$origin->url('/first'));
        $base->get(self::$origin->url('/second'));

        $requests = $this->requests();
        self::assertSame('replaced', $this->header($requests[0], 'x-a'));
        self::assertSame('2', $this->header($requests[0], 'x-b'));
        self::assertSame('wg-http', $this->header($requests[0], 'user-agent'));
        self::assertSame('1', $this->header($requests[1], 'x-a'));
        self::assertSame('', $this->header($requests[1], 'x-b'));
    }

    public function test_query_parameters_are_merged_onto_the_url(): void
    {
        $response = $this->http()
            ->withQueryParameters(['page' => '2'])
            ->get(self::$origin->url('/items?sort=name'), ['q' => 'ada']);

        $query = [];
        parse_str((string) $response->json('query'), $query);

        self::assertEquals(['sort' => 'name', 'page' => '2', 'q' => 'ada'], $query);
    }

    public function test_base_url_joins_relative_paths_and_absolute_urls_win(): void
    {
        $client = $this->http()->baseUrl(self::$origin->url('/api'));

        self::assertSame('/api/items', $client->get('items')->json('path'));
        self::assertSame('/hello', $client->baseUrl('https://example.invalid')->get(self::$origin->url('/hello'))->json('path'));
    }

    public function test_a_relative_url_without_a_base_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('baseUrl');

        $this->http()->get('/items');
    }

    public function test_status_helpers_describe_the_response(): void
    {
        $client = $this->http();
        $missing = $client->get(self::$origin->url('/status?code=404'));
        $failure = $client->get(self::$origin->url('/status?code=500'));
        $empty = $client->get(self::$origin->url('/empty'));
        $redirect = $client->withOptions(['allow_redirects' => false])->get(self::$origin->url('/redirect'));

        self::assertTrue($missing->clientError());
        self::assertTrue($missing->failed());
        self::assertFalse($missing->successful());
        self::assertSame('', $missing->header('x-missing'));
        self::assertTrue($failure->serverError());
        self::assertSame(204, $empty->status());
        self::assertTrue($empty->successful());
        self::assertFalse($empty->ok());
        self::assertNull($empty->json());
        self::assertTrue($redirect->redirect());
        self::assertSame('/hello', $redirect->header('Location'));

        $followed = $client->get(self::$origin->url('/redirect'));
        self::assertTrue($followed->ok());
        self::assertSame('/hello', $followed->json('path'));
    }

    public function test_throw_raises_for_failed_responses_and_returns_successful_ones(): void
    {
        $ok = $this->http()->get(self::$origin->url('/hello'))->throw();
        self::assertTrue($ok->ok());

        try {
            $this->http()->throw()->get(self::$origin->url('/status?code=404'));
            self::fail('A 404 response was returned.');
        } catch (RequestException $exception) {
            self::assertSame(404, $exception->response()->status());
        }
    }

    public function test_throw_callback_can_keep_a_failed_response(): void
    {
        $response = $this->http()
            ->throw(static fn (Response $response): bool => $response->status() !== 404)
            ->get(self::$origin->url('/status?code=404'));

        self::assertSame(404, $response->status());
    }

    public function test_retry_repeats_a_failed_response_until_it_succeeds(): void
    {
        $response = $this->http()
            ->retry(3, 0, static fn (\Throwable $error): bool => $error instanceof RequestException && $error->response()->serverError())
            ->throw()
            ->get(self::$origin->url('/flaky'));

        self::assertSame(200, $response->status());
        self::assertCount(3, $this->requests());
    }

    public function test_retry_leaves_http_errors_alone_unless_asked(): void
    {
        $response = $this->http()->retry(3, 0)->get(self::$origin->url('/status?code=503'));

        self::assertSame(503, $response->status());
        self::assertCount(1, $this->requests());
    }

    public function test_a_transport_failure_is_reported_as_a_connection_exception(): void
    {
        $this->expectException(ConnectionException::class);

        $this->http()->connectTimeout(0.2)->get('http://127.0.0.1:1/');
    }

    public function test_without_verifying_accepts_a_self_signed_certificate(): void
    {
        $client = $this->http();
        $url = self::$origin->httpsUrl('/hello');

        try {
            $client->timeout(3)->get($url);
            self::fail('The self-signed certificate was accepted.');
        } catch (ConnectionException) {
            $response = $client->withoutVerifying()->timeout(3)->get($url);
            self::assertTrue($response->json('ok'));
        }
    }

    public function test_an_ipv6_destination_is_rejected(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('IPv6');

        $this->http()->get('http://[::1]/');
    }

    public function test_a_response_exposes_the_underlying_psr7_message(): void
    {
        $response = $this->http()->get(self::$origin->url('/hello'));

        self::assertSame(200, $response->toPsrResponse()->getStatusCode());
        self::assertSame('/hello', $response->json('path'));
    }

    public function test_timeout_aborts_a_slow_response(): void
    {
        $this->expectException(ConnectionException::class);

        $this->http()->timeout(0.2)->get(self::$origin->url('/slow?seconds=2'));
    }

    private function http(): PendingRequest
    {
        return self::$client;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function header(array $request, string $name): string
    {
        $headers = $request['headers'] ?? null;
        if (!is_array($headers)) {
            return '';
        }

        $value = $headers[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requests(): array
    {
        return self::$origin->requestsSince($this->mark);
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyRequest(): array
    {
        $requests = $this->requests();
        self::assertCount(1, $requests);

        return $requests[0];
    }
}
