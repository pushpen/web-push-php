<?php

/*
 * This file is part of the WebPush library.
 *
 * (c) Louis Lagrange <lagrange.louis@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Minishlink\WebPush;

use Buzz\Browser;
use Buzz\Client\AbstractClient;
use Buzz\Client\MultiCurl;
use Buzz\Exception\RequestException;
use Buzz\Message\Response;

class WebPush
{
    /** @var Browser */
    protected $browser;

    /** @var array Key is push server type and value is the API key */
    protected $apiKeys;

    /** @var array Array of array of Notifications by server type */
    private $notificationsByServerType;

    /** @var array Array of not standard endpoint sources */
    private $urlByServerType = array(
        'GCM' => 'https://android.googleapis.com/gcm/send',
    );

    /** @var int Time To Live of notifications */
    private $TTL;

    /** @var bool Automatic padding of payloads, if disabled, trade security for bandwidth */
    private $automaticPadding = true;

    /** @var boolean */
    private $payloadEncryptionSupport;

    /** @var boolean */
    private $nativePayloadEncryptionSupport;

    /**
     * WebPush constructor.
     *
     * @param array $apiKeys Some servers needs authentication. Provide your API keys here. (eg. array('GCM' => 'GCM_API_KEY'))
     * @param int|null $TTL Time To Live of notifications, default being 4 weeks.
     * @param int|null $timeout Timeout of POST request
     * @param AbstractClient|null $client
     */
    public function __construct(array $apiKeys = array(), $TTL = 2419200, $timeout = 30, AbstractClient $client = null)
    {
        $this->apiKeys = $apiKeys;
        $this->TTL = $TTL;

        $client = isset($client) ? $client : new MultiCurl();
        $client->setTimeout($timeout);
        $this->browser = new Browser($client);

        $this->payloadEncryptionSupport = version_compare(phpversion(), '5.5.9', '>=') && class_exists("\\Jose\\Util\\GCM");
        $this->nativePayloadEncryptionSupport = version_compare(phpversion(), '7.1', '>=');
    }

    /**
     * Send a notification.
     *
     * @param string $endpoint
     * @param string|null $payload If you want to send an array, json_encode it.
     * @param string|null $userPublicKey
     * @param string|null $userAuthToken
     * @param bool $flush If you want to flush directly (usually when you send only one notification)
     *
     * @return array|bool Return an array of information if $flush is set to true and the queued requests has failed.
     *                    Else return true.
     * @throws \ErrorException
     */
    public function sendNotification($endpoint, $payload = null, $userPublicKey = null, $userAuthToken = null, $flush = false)
    {
        if (isset($userAuthToken) && is_bool($userAuthToken)) {
            throw new \ErrorException('The API has changed: sendNotification now takes the optional user auth token as parameter.');
        }

        if(isset($payload)) {
            if (strlen($payload) > Encryption::MAX_PAYLOAD_LENGTH) {
                throw new \ErrorException('Size of payload must not be greater than '.Encryption::MAX_PAYLOAD_LENGTH.' octets.');
            }

            if ($this->automaticPadding) {
                $payload = Encryption::automaticPadding($payload);
            }
        }

        // sort notification by server type
        $type = $this->sortEndpoint($endpoint);
        $this->notificationsByServerType[$type][] = new Notification($endpoint, $payload, $userPublicKey, $userAuthToken);

        if ($flush) {
            $res = $this->flush();

            // if there has been a problem with at least one notification
            if (is_array($res)) {
                // if there was only one notification, return the informations directly
                if (count($res) === 1) {
                    return $res[0];
                }

                return $res;
            }

            return true;
        }

        return true;
    }

    /**
     * Flush notifications. Triggers the requests.
     *
     * @return array|bool If there are no errors, return true.
     *                    If there were no notifications in the queue, return false.
     *                    Else return an array of information for each notification sent (success, statusCode, headers).
     *
     * @throws \ErrorException
     */
    public function flush()
    {
        if (empty($this->notificationsByServerType)) {
            return false;
        }

        // if GCM is present, we should check for the API key
        if (array_key_exists('GCM', $this->notificationsByServerType)) {
            if (empty($this->apiKeys['GCM'])) {
                throw new \ErrorException('No GCM API Key specified.');
            }
        }

        // for each endpoint server type
        $responses = array();
        foreach ($this->notificationsByServerType as $serverType => $notifications) {
            switch ($serverType) {
                case 'GCM':
                    $responses += $this->sendToGCMEndpoints($notifications);
                    break;
                case 'standard':
                    $responses += $this->sendToStandardEndpoints($notifications);
                    break;
            }
        }

        // if multi curl, flush
        if ($this->browser->getClient() instanceof MultiCurl) {
            /** @var MultiCurl $multiCurl */
            $multiCurl = $this->browser->getClient();
            $multiCurl->flush();
        }

        /** @var Response|null $response */
        $return = array();
        $completeSuccess = true;
        foreach ($responses as $response) {
            if (!isset($response)) {
                $return[] = array(
                    'success' => false,
                );

                $completeSuccess = false;
            } elseif (!$response->isSuccessful()) {
                $return[] = array(
                    'success' => false,
                    'statusCode' => $response->getStatusCode(),
                    'headers' => $response->getHeaders(),
                );

                $completeSuccess = false;
            } else {
                $return[] = array(
                    'success' => true,
                );
            }
        }

        // reset queue
        $this->notificationsByServerType = null;

        return $completeSuccess ? true : $return;
    }

    private function sendToStandardEndpoints(array $notifications)
    {
        $responses = array();
        /** @var Notification $notification */
        foreach ($notifications as $notification) {
            $payload = $notification->getPayload();
            $userPublicKey = $notification->getUserPublicKey();

            if (isset($payload) && isset($userPublicKey) && ($this->payloadEncryptionSupport || $this->nativePayloadEncryptionSupport)) {
                $encrypted = Encryption::encrypt($payload, $userPublicKey, $notification->getUserAuthToken(), $this->nativePayloadEncryptionSupport);

                $headers = array(
                    'Content-Length' => strlen($encrypted['cipherText']),
                    'Content-Type' => 'application/octet-stream',
                    'Content-Encoding' => 'aesgcm128',
                    'Encryption' => 'keyid="p256dh";salt="'.$encrypted['salt'].'"',
                    'Crypto-Key' => 'keyid="p256dh";dh="'.$encrypted['localPublicKey'].'"',
                    'TTL' => $this->TTL,
                );

                $content = $encrypted['cipherText'];
            } else {
                $headers = array(
                    'Content-Length' => 0,
                    'TTL' => $this->TTL,
                );

                $content = '';
            }

            $responses[] = $this->sendRequest($notification->getEndpoint(), $headers, $content);
        }

        return $responses;
    }

    private function sendToGCMEndpoints(array $notifications)
    {
        $maxBatchSubscriptionIds = 1000;
        $url = $this->urlByServerType['GCM'];

        $headers = array(
            'Authorization' => 'key='.$this->apiKeys['GCM'],
            'Content-Type' => 'application/json',
            'TTL' => $this->TTL,
        );

        $subscriptionIds = array();
        /** @var Notification $notification */
        foreach ($notifications as $notification) {
            // get all subscriptions ids
            $endpointsSections = explode('/', $notification->getEndpoint());
            $subscriptionIds[] = $endpointsSections[count($endpointsSections) - 1];
        }

        // chunk by max number
        $batch = array_chunk($subscriptionIds, $maxBatchSubscriptionIds);

        $responses = array();
        foreach ($batch as $subscriptionIds) {
            $content = json_encode(array(
                'registration_ids' => $subscriptionIds,
            ));

            $headers['Content-Length'] = strlen($content);

            $responses[] = $this->sendRequest($url, $headers, $content);
        }

        return $responses;
    }

    /**
     * @param string $url
     * @param array  $headers
     * @param string $content
     *
     * @return \Buzz\Message\MessageInterface|null
     */
    private function sendRequest($url, array $headers, $content)
    {
        try {
            $response = $this->browser->post($url, $headers, $content);
        } catch (RequestException $e) {
            $response = null;
        }

        return $response;
    }

    /**
     * @param string $endpoint
     *
     * @return string
     */
    private function sortEndpoint($endpoint)
    {
        foreach ($this->urlByServerType as $type => $url) {
            if (substr($endpoint, 0, strlen($url)) === $url) {
                return $type;
            }
        }

        return 'standard';
    }

    /**
     * @return Browser
     */
    public function getBrowser()
    {
        return $this->browser;
    }

    /**
     * @param Browser $browser
     *
     * @return WebPush
     */
    public function setBrowser($browser)
    {
        $this->browser = $browser;

        return $this;
    }

    /**
     * @return int
     */
    public function getTTL()
    {
        return $this->TTL;
    }

    /**
     * @param int $TTL
     *
     * @return WebPush
     */
    public function setTTL($TTL)
    {
        $this->TTL = $TTL;

        return $this;
    }

    /**
     * @return boolean
     */
    public function isAutomaticPadding()
    {
        return $this->automaticPadding;
    }

    /**
     * @param boolean $automaticPadding
     *
     * @return WebPush
     */
    public function setAutomaticPadding($automaticPadding)
    {
        $this->automaticPadding = $automaticPadding;

        return $this;
    }
}
