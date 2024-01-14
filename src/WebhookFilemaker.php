<?php
namespace craftyfm\craftformiefilemaker;

use Craft;
use craft\base\Event;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\web\View;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use Throwable;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use verbb\formie\events\ModifyWebhookPayloadEvent;
use verbb\formie\Formie;
use verbb\formie\base\Integration;
use verbb\formie\base\Webhook;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\integrations\webhooks\Zapier;
use verbb\formie\models\IntegrationCollection;
use verbb\formie\models\IntegrationField;
use verbb\formie\models\IntegrationFormSettings;
use yii\base\Exception;

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


    // Public Methods
    // =========================================================================

    public function getDescription(): string
    {
        return Craft::t('formie', 'Send your form content to any URL you provide.');
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

            /* $payload =  [
                 "fieldData" => [
                     "webhook_payload" => $payload
                 ]
             ];*/

            //
            // OR
            //

            /* $payload = [
                 'id' => $submission->id,
                 'title' => $submission->title,

                 // Handle custom fields
                 'email' => $submission->getFieldValue('emailAddress'),
                 // ...
             ];*/

            $response = $this->getClient()->request('POST', $this->getWebhookUrl($this->webhook, $submission), $payload);
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
                'Content-Type' => 'application/json',
                'Authorization' => $this->getAuthToken()
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
            }, 900);

            return $token;
        } catch (Throwable $e) {
            // Save a different payload to logs
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
}
