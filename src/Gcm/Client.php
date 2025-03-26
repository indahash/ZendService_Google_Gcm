<?php
/**
 * Zend Framework (http://framework.zend.com/).
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 *
 * @copyright Copyright (c) 2005-2014 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 *
 * @category  ZendService
 */
namespace ZendService\Google\Gcm;

use ZendService\Google\Exception;
use Zend\Http\Client as HttpClient;

/**
 * Google Cloud Messaging Client
 * This class allows the ability to send out messages
 * through the Google Cloud Messaging API.
 *
 * @category   ZendService
 */
class Client
{
    /**
     * @const string Server URI
     */
    const SERVER_URI = 'https://fcm.googleapis.com/v1/projects/{project_id}/messages:send';

    /**
     * @var \Zend\Http\Client
     */
    protected $httpClient;

    /**
     * @var string
     */
    protected $apiKey;

    /**
     * Get API Key.
     *
     * @return string
     */
    public function getApiKey()
    {
        return $this->apiKey;
    }

    /**
     * Set API Key.
     *
     * @param string $apiKey
     *
     * @return Client
     *
     * @throws Exception\InvalidArgumentException
     */
    public function setApiKey($apiKey)
    {
        if (! is_string($apiKey) || empty($apiKey)) {
            throw new Exception\InvalidArgumentException('The api key must be a string and not empty');
        }
        $this->apiKey = $apiKey;

        return $this;
    }

    /**
     * Get HTTP Client.
     *
     * @throws \Zend\Http\Client\Exception\InvalidArgumentException
     *
     * @return \Zend\Http\Client
     */
    public function getHttpClient()
    {
        if (! $this->httpClient) {
            $this->httpClient = new HttpClient();
            $this->httpClient->setOptions(['strictredirects' => true]);
        }

        return $this->httpClient;
    }

    /**
     * Set HTTP Client.
     *
     * @param \Zend\Http\Client
     *
     * @return Client
     */
    public function setHttpClient(HttpClient $http)
    {
        $this->httpClient = $http;

        return $this;
    }

    /**
     * Send Message.
     *
     * @param Message $message
     *
     * @throws \Zend\Json\Exception\RuntimeException
     * @throws \ZendService\Google\Exception\RuntimeException
     * @throws \Zend\Http\Exception\RuntimeException
     * @throws \Zend\Http\Client\Exception\RuntimeException
     * @throws \Zend\Http\Exception\InvalidArgumentException
     * @throws \Zend\Http\Client\Exception\InvalidArgumentException
     * @throws \ZendService\Google\Exception\InvalidArgumentException
     *
     * @return Response
     */
    public function send(Message $message)
    {
        // === 1. Parse service account JSON string ===
        $serviceAccountJson = $this->getApiKey(); // Now returns JSON string, not a file path
        $serviceAccount = json_decode($serviceAccountJson, true);

        if (!is_array($serviceAccount) || !isset($serviceAccount['project_id'])) {
            throw new \RuntimeException('Invalid Firebase service account JSON from getApiKey().');
        }

        $projectId = $serviceAccount['project_id'];

        // === 2. Token caching ===
        $cacheFile = "/tmp/android-push-token-{$projectId}.json";
        $accessToken = null;

        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);

            if (
                is_array($cached) &&
                isset($cached['access_token'], $cached['expires_at']) &&
                (int)$cached['expires_at'] > time()
            ) {
                $accessToken = $cached['access_token'];
            }
        }

        if (!$accessToken) {
            $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
            $credentials = new \Google\Auth\Credentials\ServiceAccountCredentials($scopes, $serviceAccount);
            $tokenData = $credentials->fetchAuthToken();

            if (!isset($tokenData['access_token'], $tokenData['expires_in'])) {
                throw new \RuntimeException('Failed to obtain Firebase access token.');
            }

            $accessToken = $tokenData['access_token'];
            file_put_contents($cacheFile, json_encode([
                'access_token' => $accessToken,
                'expires_at' => time() + $tokenData['expires_in'] - 120 // 120s buffer
            ]));
        }

        // === 3. Replace {project_id} in URI ===
        $url = str_replace('{project_id}', $projectId, self::SERVER_URI);

        // === 4. Convert legacy message to v1 format ===
        $legacy = json_decode($message->toJson(), true);

        if (!is_array($legacy)) {
            throw new \RuntimeException('Invalid message JSON structure.');
        }

        $v1Payload = [
            'message' => []
        ];

        if (isset($legacy['to'])) {
            $v1Payload['message']['token'] = $legacy['to'];
        }

        if (isset($legacy['notification'])) {
            $v1Payload['message']['notification'] = $legacy['notification'];
        }

        if (isset($legacy['data'])) {
            $v1Payload['message']['data'] = $legacy['data'];
        }

        $v1PayloadJson = json_encode($v1Payload);

        $client = $this->getHttpClient();
        $client->setUri($url);
        $headers = $client->getRequest()->getHeaders();
        $headers->addHeaderLine('Authorization', 'Bearer ' . $accessToken);
        $headers->addHeaderLine('Content-length', mb_strlen($v1PayloadJson));

        $response = $client->setHeaders($headers)
            ->setMethod('POST')
            ->setRawBody($v1PayloadJson)
            ->setEncType('application/json')
            ->send();

        switch ($response->getStatusCode()) {
            case 500:
                throw new Exception\RuntimeException('500 Internal Server Error');
                break;
            case 503:
                $exceptionMessage = '503 Server Unavailable';
                if ($retry = $response->getHeaders()->get('Retry-After')) {
                    $exceptionMessage .= '; Retry After: '.$retry;
                }
                throw new Exception\RuntimeException($exceptionMessage);
                break;
            case 401:
                throw new Exception\RuntimeException('401 Forbidden; Authentication Error');
                break;
            case 400:
                throw new Exception\RuntimeException('400 Bad Request; invalid message');
                break;
        }

        if (! $response = json_decode($response->getBody(), true)) {
            throw new Exception\RuntimeException('Response body did not contain a valid JSON response');
        }

        return new Response($response, $message);
    }
}
