<?php

namespace gerry3010\mcp\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use gerry3010\mcp\mcp\Auth;
use gerry3010\mcp\mcp\Server;
use gerry3010\mcp\Plugin;
use yii\web\Response;

/**
 * The MCP HTTP endpoint (POST /mcp). Speaks JSON-RPC 2.0 (Streamable HTTP,
 * single-response mode). Authentication is a Bearer token bound to a Craft user;
 * CSRF is disabled because this is a token-authenticated API, not a browser form.
 */
class McpController extends Controller
{
    protected array|bool|int $allowAnonymous = true;
    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->enabled) {
            return $this->jsonRpcError(503, 'The Craft MCP endpoint is disabled in plugin settings.');
        }

        // Only POST carries JSON-RPC. A GET is answered with a small hint so the
        // endpoint is discoverable without leaking anything.
        if (!Craft::$app->getRequest()->getIsPost()) {
            return $this->asJson(['server' => 'craft-mcp', 'transport' => 'jsonrpc-http', 'method' => 'POST']);
        }

        // -- Auth ----------------------------------------------------------
        $user = Auth::authenticate();
        if (!$user) {
            $this->response->setStatusCode(401);
            $this->response->getHeaders()->set('WWW-Authenticate', 'Bearer');
            return $this->asJson([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32001, 'message' => 'Unauthorized: missing or invalid Bearer token.'],
            ]);
        }
        Auth::setIdentity($user);

        // -- Parse body ----------------------------------------------------
        $body = Craft::$app->getRequest()->getRawBody();
        $decoded = Json::decodeIfJson($body);
        if (!is_array($decoded)) {
            return $this->asJson([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Parse error: request body is not valid JSON.'],
            ]);
        }

        // -- Dispatch ------------------------------------------------------
        $response = (new Server())->handle($decoded);

        if ($response === null) {
            // Notification(s) only — nothing to return.
            $this->response->setStatusCode(202);
            $this->response->format = Response::FORMAT_RAW;
            $this->response->content = '';
            return $this->response;
        }

        return $this->asJson($response);
    }

    private function jsonRpcError(int $status, string $message): Response
    {
        $this->response->setStatusCode($status);
        return $this->asJson([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32000, 'message' => $message],
        ]);
    }
}
