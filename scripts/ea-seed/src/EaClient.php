<?php

namespace EaSeed;

/**
 * Authenticated REST client for the EasyAppointments v1 API.
 *
 * The transport is injectable so the client can be exercised in unit tests
 * without a running server; production use falls through to cURL.
 */
final class EaClient
{
    /** @var callable(string,string,?string,array):array{status:int,body:string} */
    private $transport;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiToken,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? [$this, 'curlTransport'];
    }

    public function get(string $path): array
    {
        return $this->send('GET', $path, null);
    }

    /**
     * Fetch a full collection. EA paginates at Api::$default_length (20), so an
     * unpaginated GET silently truncates and records read as absent — which makes
     * a converger create duplicates on every run.
     */
    public function getAll(string $path, int $length = 1000): array
    {
        $separator = str_contains($path, '?') ? '&' : '?';

        return $this->get($path . $separator . 'length=' . $length);
    }

    public function post(string $path, array $body): array
    {
        return $this->send('POST', $path, $body);
    }

    public function put(string $path, array $body): array
    {
        return $this->send('PUT', $path, $body);
    }

    private function send(string $method, string $path, ?array $body): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiToken,
        ];

        $encoded = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);
        $url = rtrim($this->baseUrl, '/') . $path;

        $response = ($this->transport)($method, $url, $encoded, $headers);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new EaHttpException(
                $response['status'],
                "EA {$response['status']} on {$method} {$path}: {$response['body']}"
            );
        }

        if (trim($response['body']) === '') {
            return [];
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            $excerpt = substr($response['body'], 0, 200);
            throw new EaHttpException(
                $response['status'],
                "EA {$response['status']} on {$method} {$path} returned a body that did not "
                . "decode to a JSON array/object: \"$excerpt\""
            );
        }

        return $decoded;
    }

    private function curlTransport(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);

        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new EaHttpException(0, "Could not reach $url: $error");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $responseBody];
    }
}
