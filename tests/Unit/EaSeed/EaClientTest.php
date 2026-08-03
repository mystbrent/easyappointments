<?php

namespace Tests\Unit\EaSeed;

use EaSeed\EaClient;
use EaSeed\EaHttpException;
use Tests\TestCase;

class EaClientTest extends TestCase
{
    public function testGetSendsBearerTokenAndDecodesJson()
    {
        $seen = [];
        $client = new EaClient('http://ea.test', 'tok_abc', function ($method, $url, $body, $headers) use (&$seen) {
            $seen = compact('method', 'url', 'body', 'headers');
            return ['status' => 200, 'body' => '[{"id":7,"name":"Test Drive"}]'];
        });

        $result = $client->get('/api/v1/services');

        $this->assertSame('GET', $seen['method']);
        $this->assertSame('http://ea.test/api/v1/services', $seen['url']);
        $this->assertContains('Authorization: Bearer tok_abc', $seen['headers']);
        $this->assertSame(7, $result[0]['id']);
    }

    public function testPostEncodesTheBody()
    {
        $seen = [];
        $client = new EaClient('http://ea.test', 'tok_abc', function ($method, $url, $body, $headers) use (&$seen) {
            $seen = compact('method', 'body');
            return ['status' => 201, 'body' => '{"id":9}'];
        });

        $result = $client->post('/api/v1/services', ['name' => 'Showroom Visit']);

        $this->assertSame('POST', $seen['method']);
        $this->assertSame('{"name":"Showroom Visit"}', $seen['body']);
        $this->assertSame(9, $result['id']);
    }

    public function testEmptyResponseBodyDecodesToEmptyArray()
    {
        $client = new EaClient('http://ea.test', 'tok_abc', fn() => ['status' => 204, 'body' => '']);

        $this->assertSame([], $client->put('/api/v1/settings/company_name', ['value' => 'X']));
    }

    public function testNonSuccessStatusThrowsWithStatusAndBody()
    {
        $client = new EaClient('http://ea.test', 'tok_abc', fn() => [
            'status' => 401,
            'body' => 'Unauthorized',
        ]);

        try {
            $client->get('/api/v1/services');
            $this->fail('Expected EaHttpException');
        } catch (EaHttpException $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringContainsString('Unauthorized', $e->getMessage());
        }
    }
}
