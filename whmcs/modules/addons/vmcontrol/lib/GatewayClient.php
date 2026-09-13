<?php

namespace VMControl;

class GatewayClient
{
    private $baseUrl;
    private $apiKey;
    private $apiSecret;
    private $timeout;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['base_url'], '/');
        $this->apiKey = $config['api_key'];
        $this->apiSecret = $config['api_secret'];
        $this->timeout = isset($config['timeout']) ? (int) $config['timeout'] : 20;
    }

    public function status($vcenterId, $instanceUuid)
    {
        return $this->request('GET', $this->vmPath($vcenterId, $instanceUuid) . '/status', array());
    }

    public function metrics($vcenterId, $instanceUuid, $range)
    {
        if (!in_array($range, array('day', 'week', 'month'), true)) {
            $range = 'day';
        }
        return $this->request(
            'GET',
            $this->vmPath($vcenterId, $instanceUuid) . '/metrics/' . $range,
            array(),
            max($this->timeout, 45)
        );
    }

    public function vcenters()
    {
        $response = $this->request('GET', '/api/v1/vcenters', array());
        return isset($response['vcenters']) && is_array($response['vcenters'])
            ? $response['vcenters']
            : array();
    }

    public function inventory($vcenterId)
    {
        $path = '/api/v1/vcenters/' . rawurlencode($vcenterId) . '/vms';
        $response = $this->request('GET', $path, array(), max($this->timeout, 45));
        return isset($response['vms']) && is_array($response['vms'])
            ? $response['vms']
            : array();
    }

    public function consoleEvents($serviceId)
    {
        $response = $this->request(
            'GET',
            '/api/v1/services/' . rawurlencode((string) ((int) $serviceId)) . '/console-events',
            array()
        );
        return isset($response['events']) && is_array($response['events'])
            ? $response['events']
            : array();
    }

    public function action($vcenterId, $instanceUuid, $action, $serviceId, $userId)
    {
        return $this->request('POST', $this->vmPath($vcenterId, $instanceUuid) . '/actions', array(
            'action' => $action,
            'service_id' => (int) $serviceId,
            'user_id' => (int) $userId,
        ));
    }

    public function console($vcenterId, $instanceUuid, $serviceId, $userId, $displayName)
    {
        return $this->request('POST', $this->vmPath($vcenterId, $instanceUuid) . '/console', array(
            'service_id' => (int) $serviceId,
            'user_id' => (int) $userId,
            'display_name' => (string) $displayName,
        ));
    }

    private function vmPath($vcenterId, $instanceUuid)
    {
        return '/api/v1/vcenters/' . rawurlencode($vcenterId)
            . '/vms/' . rawurlencode($instanceUuid);
    }

    private function request($method, $path, array $payload, $timeoutOverride = null)
    {
        $body = $method === 'GET' ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('Could not encode Gateway request.');
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n"
            . $nonce . "\n" . hash('sha256', $body);
        $signature = hash_hmac('sha256', $canonical, $this->apiSecret);

        $handle = curl_init($this->baseUrl . $path);
        curl_setopt_array($handle, array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $method === 'GET' ? null : $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeoutOverride ? (int) $timeoutOverride : $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Content-Type: application/json',
                'X-VMControl-Key: ' . $this->apiKey,
                'X-VMControl-Timestamp: ' . $timestamp,
                'X-VMControl-Nonce: ' . $nonce,
                'X-VMControl-Signature: ' . $signature,
            ),
        ));

        $response = curl_exec($handle);
        $curlError = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($response === false) {
            throw new \RuntimeException('Gateway connection failed: ' . $curlError);
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Gateway returned an invalid response (HTTP ' . $status . ').');
        }
        if ($status < 200 || $status >= 300) {
            $message = isset($decoded['error']['message']) ? $decoded['error']['message'] : 'Gateway request failed.';
            throw new \RuntimeException($message . ' (HTTP ' . $status . ')');
        }
        return $decoded;
    }
}
