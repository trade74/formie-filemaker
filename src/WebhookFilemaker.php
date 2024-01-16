<?php
namespace craftyfm\craftformiefilemaker;

use Craft;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use GuzzleHttp\Client;
use Throwable;
use verbb\formie\Formie;
use verbb\formie\base\Integration;
use verbb\formie\base\Webhook;
use verbb\formie\elements\Submission;
use verbb\formie\models\IntegrationFormSettings;

class WebhookFilemaker extends Webhook
{
    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('formie', 'Filemaker Webhook');
    }

    // Properties
    // =========================================================================

    public ?string $webhook = null;
    public ?string $postUrl = null;
    public ?string $authUrl = null;
    public ?string $username = null;
    public ?string $password = null;
    public ?string $token = null;
    public ?int $length = null;
    public ?string $host = null;



    // Public Methods
    // =========================================================================

    public function getDescription(): string
    {
        return Craft::t('formie', 'Send your form content to any URL you provide.');
    }

    public function getHost(): string
    {
        return preg_replace('#^https?://#', '', UrlHelper::hostInfo($this->webhook));
    }




    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['webhook'], 'required', 'on' => [Integration::SCENARIO_FORM]];

        return $rules;
    }

    public function getIconUrl(): string
    {
        return '';
    }

    public function getSettingsHtml(): string
    {

        // Craft::dd((new \craft\web\View)->getCpTemplateRoots());
        return Craft::$app->getView()->renderTemplate("formie-filemaker/_formie-filemaker-plugin-settings", [
            'integration' => $this,
        ]);
    }

    public function getFormSettingsHtml($form): string
    {
        return Craft::$app->getView()->renderTemplate("formie-filemaker/_formie-filemaker-form-settings", [
            'integration' => $this,
            'form' => $form,
        ]);
    }

    public function fetchFormSettings(): IntegrationFormSettings
    {
        $settings = [];
        $payload = [];

        try {
            $formId = Craft::$app->getRequest()->getParam('formId');
            $form = Formie::$plugin->getForms()->getFormById($formId);

            // Generate and send a test payload to the webhook endpoint
            $submission = new Submission();
            $submission->setForm($form);

            Formie::$plugin->getSubmissions()->populateFakeSubmission($submission);

            // Ensure we're fetching the webhook from the form settings, or global integration settings
            $webhook = $form->settings->integrations[$this->handle]['webhook'] ?? $this->webhook;

            $payload = $this->generatePayloadValues($submission);
            $response = $this->getClient()->request('POST', $this->getWebhookUrl($webhook, $submission), $payload);

            $rawResponse = (string)$response->getBody();
            $json = Json::decode($rawResponse);

            $settings = [
                'response' => $response,
                'json' => $json,
            ];
        } catch (Throwable $e) {
            // Save a different payload to logs
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}. Payload: “{payload}”. Response: “{response}”', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'payload' => Json::encode($payload),
                'response' => $rawResponse ?? '',
            ]));

            Integration::apiError($this, $e);
        }

        return new IntegrationFormSettings($settings);
    }

    public function sendPayload(Submission $submission): bool
    {
        $payload = [];
        $response = [];

        try {
            // Either construct the payload yourself manually or get Formie to do it
            $payload = $this->generatePayloadValues($submission);

            $body =  [
                 "fieldData" => [
                     "webhook_payload" => json_encode($payload)
                 ]
             ];

            $this->length = strlen(json_encode($body)) ;

            $client = new Client();

            $headers = [ 'headers' => [
                    'Host' => $this->getHost(),
                    'Content-Type' => 'application/json',
                    'Content-Length' => $this->length,
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->getAuthToken()
                ],
                'body' => json_encode($body)
            ];

            $request = $client->post($this->webhook, $headers);

        } catch (Throwable $e) {
            // Save a different payload to logs
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}. Payload: “{payload}”. Response: “{response}”', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'payload' => Json::encode($payload),
                'response' => $response,
            ]));

            Integration::apiError($this, $e);

            return false;
        }

        return true;
    }



    public function getClient(): Client
    {
        // We memoize the client for performance, in case we make multiple requests.
        if ($this->_client) {
            return $this->_client;
        }

        //get or set refreshed token
        $headers = [
            'headers' => [
               // 'Connection' => 'keep-alive',
                'Host' => $this->getHost(),
                'Content-Type' => 'application/json',
                'Content-Length' => $this->length,
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->getAuthToken()
            ]
        ];

        // Create a Guzzle client to send the payload.
        return $this->_client = Craft::createGuzzleClient($headers);
    }

    public function getAuthToken()
    {
        try {

            $token = Craft::$app->getCache()->getOrSet('api-token', function() {
                // Create Guzzle client
                // file to store cookie data

                $client = new Client([
                    'base_uri' => $this->authUrl,
                    'verify' => false

                ]);

                //create Basic Auth string
                $basicAuthString = 'Basic ' . base64_encode($this->username . ':' . $this->password);

                // Request token
                $response = $client->request('POST', '', [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => $basicAuthString
                    ],
                    ['body' => ''],
                    'debug' => true,
                ]);

                $json = $response->getBody()->getContents();
                $data = json_decode($json);

                $status = $response->getStatusCode();

                $authtoken = $data->response->token;

                if ($status === 200) {
                    return $authtoken;
                } else {
                    return false;
                }
            }, 100);

            return $token;
        } catch (Throwable $e) {
            // Auth errors to log
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}. AuthURL: “{authurl}”. Token: “{token}”', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'authurl' => $this->authUrl,
                'token' => $this->token,
            ]));

            Integration::apiError($this, $e);

            return false;
        }
    }

    public function fetchConnection(): bool
    {
        try {
            // Create a simple API call to `/account` to test the connection (in the integration settings)
            // any errors will be safely caught, logged and shown in the UI.
            //$response = $this->request('GET', $this->webhook);

            $client = new Client();

            $headers = [ 'headers' => [
                'Host' => $this->getHost(),
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->getAuthToken()
            ]
            ];

            $response = $client->get($this->webhook, $headers);

            $json = $response->getBody()->getContents();
            $data = json_decode($json);

            $status = $data->messages[0]->message;

            $webhook_payload = $data->response->data[0]->fieldData->webhook_payload;

            if($status == "OK" && $webhook_payload != null){
                return true;
            }


        } catch (Throwable $e) {
            Integration::apiError($this, $e);

            return false;
        }

        return true;
    }
}
