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

    public function testGetAllAppendsLengthWithQuestionMarkOnABarePath()
    {
        $seen = null;
        $client = new EaClient('http://ea.test', 'tok_abc', function ($method, $url) use (&$seen) {
            $seen = $url;
            return ['status' => 200, 'body' => '[]'];
        });

        $client->getAll('/api/v1/services');

        $this->assertSame('http://ea.test/api/v1/services?length=1000', $seen);
    }

    public function testGetAllAppendsLengthWithAmpersandWhenPathAlreadyHasAQueryString()
    {
        $seen = null;
        $client = new EaClient('http://ea.test', 'tok_abc', function ($method, $url) use (&$seen) {
            $seen = $url;
            return ['status' => 200, 'body' => '[]'];
        });

        $client->getAll('/api/v1/services?filter=active');

        $this->assertSame('http://ea.test/api/v1/services?filter=active&length=1000', $seen);
    }

    public function testGetAllHonoursACustomLength()
    {
        $seen = null;
        $client = new EaClient('http://ea.test', 'tok_abc', function ($method, $url) use (&$seen) {
            $seen = $url;
            return ['status' => 200, 'body' => '[]'];
        });

        $client->getAll('/api/v1/services', 50);

        $this->assertSame('http://ea.test/api/v1/services?length=50', $seen);
    }

    public function testEmptyBodyStillReturnsEmptyArrayEvenOnSuccess()
    {
        $client = new EaClient('http://ea.test', 'tok_abc', fn() => ['status' => 200, 'body' => '']);

        $this->assertSame([], $client->get('/api/v1/services'));
    }

    public function testNonArraySuccessBodyThrowsInsteadOfSilentlyReturningEmpty()
    {
        // A truncated/malformed body from a 2xx response must not be treated as
        // "record absent" — that was the mechanism behind EA_SERVICE_X=0 in an
        // emitted env file (a create() whose response failed to decode, whose
        // id then cast to 0 and got stored and written out).
        $client = new EaClient('http://ea.test', 'tok_abc', fn() => ['status' => 200, 'body' => 'not json']);

        try {
            $client->get('/api/v1/services');
            $this->fail('Expected EaHttpException');
        } catch (EaHttpException $e) {
            $this->assertSame(200, $e->status);
            $this->assertStringContainsString('not json', $e->getMessage());
        }
    }

    public function testNonArrayJsonScalarSuccessBodyThrows()
    {
        // json_decode('"hello"', true) === 'hello' — a valid JSON document that
        // is still not the array/object shape callers assume.
        $client = new EaClient('http://ea.test', 'tok_abc', fn() => ['status' => 200, 'body' => '"hello"']);

        $this->expectException(EaHttpException::class);
        $client->get('/api/v1/services');
    }
}
