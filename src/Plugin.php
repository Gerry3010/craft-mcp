<?php

namespace gerry3010\mcp;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use gerry3010\mcp\models\Settings;
use yii\base\Event;

/**
 * Craft MCP — exposes an in-process MCP (Model Context Protocol) server at
 * POST /mcp so AI agents can control Craft, bounded by a token-bound Craft
 * user's permissions.
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        // Register the front-end route for the MCP endpoint.
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules['mcp'] = 'mcp/mcp/index';
                $event->rules['POST mcp'] = 'mcp/mcp/index';
            }
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('mcp/_settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }
}
