<?php

namespace gerry3010\mcp\mcp;

use craft\helpers\Json;
use gerry3010\mcp\Plugin;
use gerry3010\mcp\tools\ValidationException;

/**
 * A minimal MCP server speaking JSON-RPC 2.0 over a single HTTP endpoint
 * (Streamable HTTP, single-response mode).
 *
 * handle() takes the decoded request (a single JSON-RPC object or a batch
 * array) and returns the response value to encode as JSON — or null when the
 * request was a notification (no response expected).
 */
class Server
{
    private const PROTOCOL_VERSION = '2024-11-05';
    private const SERVER_NAME = 'craft-mcp';

    public function handle(mixed $request): mixed
    {
        // JSON-RPC batch.
        if (is_array($request) && array_is_list($request) && $request !== []) {
            $responses = [];
            foreach ($request as $one) {
                $res = $this->handleOne(is_array($one) ? $one : []);
                if ($res !== null) {
                    $responses[] = $res;
                }
            }
            return $responses === [] ? null : $responses;
        }

        return $this->handleOne(is_array($request) ? $request : []);
    }

    private function handleOne(array $req): mixed
    {
        $id = $req['id'] ?? null;
        $method = $req['method'] ?? null;
        $params = $req['params'] ?? [];
        $isNotification = !array_key_exists('id', $req);

        if (!is_string($method)) {
            return $this->error($id, -32600, 'Invalid Request: missing "method"');
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'ping' => (object)[],
                'tools/list' => $this->toolsList(),
                'tools/call' => $this->toolsCall($params),
                default => null,
            };
        } catch (JsonRpcException $e) {
            return $isNotification ? null : $this->error($id, $e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            return $isNotification ? null : $this->error($id, -32603, 'Internal error: ' . $e->getMessage());
        }

        // Notifications (e.g. notifications/initialized) get no response.
        if ($isNotification) {
            return null;
        }

        if ($result === null && !in_array($method, ['initialize', 'ping', 'tools/list', 'tools/call'], true)) {
            return $this->error($id, -32601, "Method not found: {$method}");
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    private function initialize(array $params): array
    {
        $protocol = $params['protocolVersion'] ?? self::PROTOCOL_VERSION;
        return [
            'protocolVersion' => $protocol,
            'capabilities' => ['tools' => (object)[]],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => Plugin::getInstance()?->version ?? '1.0.0',
            ],
        ];
    }

    private function toolsList(): array
    {
        $tools = [];
        foreach (ToolRegistry::all() as $name => $def) {
            $tools[] = [
                'name' => $name,
                'description' => '[' . $def['risk'] . '] ' . $def['description'],
                'inputSchema' => $def['inputSchema'],
            ];
        }
        return ['tools' => $tools];
    }

    private function toolsCall(array $params): array
    {
        $name = $params['name'] ?? null;
        $args = $params['arguments'] ?? [];
        if (!is_string($name)) {
            throw new JsonRpcException(-32602, 'tools/call requires a string "name"');
        }
        if (!is_array($args)) {
            $args = [];
        }

        $registry = ToolRegistry::all();
        $def = $registry[$name] ?? null;
        if ($def === null) {
            throw new JsonRpcException(-32602, "Unknown tool: {$name}");
        }

        // -- Gating by risk tier -------------------------------------------
        $settings = Plugin::getInstance()->getSettings();
        $risk = $def['risk'];

        if ($risk === ToolRegistry::RISK_SCHEMA_WRITE && !$settings->allowSchemaWrite) {
            return $this->toolError('Schema-write tools are disabled in the Craft MCP plugin settings.');
        }
        if ($risk === ToolRegistry::RISK_SYSTEM_WRITE && !$settings->allowSystemWrite) {
            return $this->toolError('System-write tools are disabled in the Craft MCP plugin settings.');
        }
        if (in_array($risk, [ToolRegistry::RISK_SCHEMA_WRITE, ToolRegistry::RISK_SYSTEM_WRITE], true)) {
            if (($args['confirm'] ?? false) !== true) {
                return $this->toolError(sprintf(
                    "This is a %s operation and was NOT executed. Review the change, then re-call \"%s\" with \"confirm\": true. "
                    . "Tip: for structural changes, read the current state first (e.g. describe_content_model, get_field, or project_config_get) as a dry-run.",
                    $risk,
                    $name
                ));
            }
        }

        // -- Execute -------------------------------------------------------
        try {
            $data = call_user_func($def['handler'], $args);
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => Json::encode($data),
                ]],
                'isError' => false,
            ];
        } catch (ValidationException $e) {
            return $this->toolError($e->getMessage() . "\nValidation errors: " . Json::encode($e->errors));
        } catch (\Throwable $e) {
            return $this->toolError($e->getMessage());
        }
    }

    private function toolError(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
