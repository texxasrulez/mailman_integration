<?php

class MailmanClient
{
    private $rc;
    private $config;
    private $baseUrl;

    public function __construct(rcube $rc, array $config)
    {
        $this->rc = $rc;
        $this->config = $config;
        $this->baseUrl = rtrim((string) $config['api_url'], '/');
    }

    public function isConfigured()
    {
        return $this->baseUrl !== '' && $this->config['api_user'] !== '' && $this->config['api_password'] !== '';
    }

    public function get($path, array $query = [])
    {
        return $this->request('GET', $path, [], $query);
    }

    public function post($path, array $payload = [], array $query = [])
    {
        return $this->request('POST', $path, $payload, $query);
    }

    public function delete($path, array $query = [])
    {
        return $this->request('DELETE', $path, [], $query);
    }

    public function request($method, $path, array $payload = [], array $query = [])
    {
        if (!$this->isConfigured()) {
            return $this->failure('not_configured', 'Mailman API credentials are incomplete.', $method, $path, '');
        }

        $url = $this->buildUrl($path, $query);
        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($this->config['api_user'] . ':' . $this->config['api_password']),
        ];

        $body = '';
        if ($method !== 'GET' && !empty($payload)) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($payload);
        }

        if (function_exists('curl_init')) {
            return $this->requestWithCurl($method, $path, $url, $headers, $body);
        }

        return $this->requestWithStreams($method, $path, $url, $headers, $body);
    }

    private function buildUrl($path, array $query)
    {
        $path = '/' . ltrim($path, '/');
        $query = array_filter($query, static function ($value) {
            return $value !== null && $value !== '';
        });

        $url = $this->baseUrl . $path;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    private function requestWithCurl($method, $path, $url, array $headers, $body)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(1, (int) $this->config['timeout']));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(1, min(5, (int) $this->config['timeout'])));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool) $this->config['tls_verify']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->config['tls_verify'] ? 2 : 0);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            return $this->failure('transport_error', $error ?: 'Unknown Mailman transport error.', $method, $path, $url);
        }

        return $this->normalizeResponse($status, $raw, $contentType, $method, $path, $url);
    }

    private function requestWithStreams($method, $path, $url, array $headers, $body)
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => max(1, (int) $this->config['timeout']),
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
                'content' => $body,
            ],
            'ssl' => [
                'verify_peer' => (bool) $this->config['tls_verify'],
                'verify_peer_name' => (bool) $this->config['tls_verify'],
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $meta = isset($http_response_header) ? $http_response_header : [];
        $status = 0;
        $contentType = '';

        foreach ($meta as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $headerLine, $match)) {
                $status = (int) $match[1];
            }
            if (stripos($headerLine, 'Content-Type:') === 0) {
                $contentType = trim(substr($headerLine, 13));
            }
        }

        if ($raw === false && $status === 0) {
            return $this->failure('transport_error', 'Mailman API request failed.', $method, $path, $url);
        }

        return $this->normalizeResponse($status, (string) $raw, $contentType, $method, $path, $url);
    }

    private function normalizeResponse($status, $raw, $contentType, $method, $path, $url)
    {
        $data = null;

        if (stripos($contentType, 'json') !== false || preg_match('/^\s*[\[{]/', $raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $decoded;
            }
        }

        if ($status >= 200 && $status < 300) {
            return [
                'success' => true,
                'status' => $status,
                'data' => $data !== null ? $data : ['raw' => $raw],
                'error' => null,
                'request' => [
                    'method' => $method,
                    'path' => $path,
                    'url' => $url,
                ],
            ];
        }

        $message = 'Mailman API request failed.';
        if (is_array($data) && !empty($data['description'])) {
            $message = (string) $data['description'];
        } elseif (is_array($data) && !empty($data['title'])) {
            $message = (string) $data['title'];
        }

        return [
            'success' => false,
            'status' => $status,
            'data' => $data,
            'error' => [
                'code' => 'http_' . $status,
                'message' => $message,
            ],
            'request' => [
                'method' => $method,
                'path' => $path,
                'url' => $url,
            ],
        ];
    }

    private function failure($code, $message, $method, $path, $url)
    {
        return [
            'success' => false,
            'status' => 0,
            'data' => null,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'request' => [
                'method' => $method,
                'path' => $path,
                'url' => $url,
            ],
        ];
    }
}
