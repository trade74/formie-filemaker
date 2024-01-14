<?php

namespace craftyfm\craftformiefilemaker;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\web\View;
use craftyfm\craftformiefilemaker\models\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use verbb\formie\events\ModifyWebhookPayloadEvent;
use verbb\formie\events\RegisterIntegrationsEvent;
use verbb\formie\events\SendNotificationEvent;
use verbb\formie\events\SubmissionEvent;
use verbb\formie\integrations\webhooks\Webhook;
use verbb\formie\integrations\webhooks\Zapier;
use verbb\formie\services\Integrations;
use verbb\formie\services\Submissions;
use yii\base\Event;


/**
 * formie-filemaker plugin
 *
 * @method static FormieFilemaker getInstance()
 * @method Settings getSettings()
 * @author Craftyfm <stuart@x2network.net>
 * @copyright Craftyfm
 * @license https://craftcms.github.io/license/ Craft License
 */
class FormieFilemaker extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                // Define component configs here...
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function() {
            $this->attachEventHandlers();
            // .

            });



        // Register our custom integration
        Event::on(Integrations::class, Integrations::EVENT_REGISTER_INTEGRATIONS, function(RegisterIntegrationsEvent $event) {
            $event->webhooks[] = WebhookFilemaker::class;
        });












    }

    protected function getToken(){
        $token = Craft::$app->getCache()->getOrSet('api-token', function () {
    // Create Guzzle client
    // file to store cookie data

    $client = new Client([
        'base_uri' => (string)$this->getSettings()->authURL ,
        'verify' => false,


    ]);

    //create Basic Auth string
    $basicAuthString = 'Basic ' . base64_encode($this->getSettings()->user .':'.$this->getSettings()->pass);

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

}

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('formie-filemaker/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        // Register event handlers here ...
        // (see https://craftcms.com/docs/4.x/extend/events.html to get started)
    }


}
