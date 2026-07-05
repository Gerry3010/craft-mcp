<?php

namespace gerry3010\mcp\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use gerry3010\mcp\mcp\Auth;
use yii\console\ExitCode;

/**
 * Manage MCP bearer tokens.
 *
 *   craft mcp/tokens/create --user=agent@example.com --label="Claude" --ttl-days=90
 *   craft mcp/tokens/list
 *   craft mcp/tokens/revoke <id>
 */
class TokensController extends Controller
{
    /** @var string|null User email or username to bind the token to. */
    public ?string $user = null;

    /** @var string|null Optional label for the token. */
    public ?string $label = null;

    /** @var int Token lifetime in days (0 = never expires). */
    public int $ttlDays = 90;

    public function options($actionID): array
    {
        $opts = parent::options($actionID);
        if ($actionID === 'create') {
            $opts[] = 'user';
            $opts[] = 'label';
            $opts[] = 'ttlDays';
        }
        return $opts;
    }

    public function optionAliases(): array
    {
        return ['u' => 'user', 'l' => 'label'];
    }

    public function actionCreate(): int
    {
        if (!$this->user) {
            $this->stderr("Provide --user=<email|username>\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($this->user);
        if (!$user) {
            $this->stderr("User not found: {$this->user}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $result = Auth::createToken($user->id, $this->label, $this->ttlDays);

        $this->stdout("\nMCP token for {$user->email} (id {$user->id}):\n\n", Console::FG_GREEN);
        $this->stdout("  {$result['token']}\n\n", Console::FG_YELLOW);
        $this->stdout('Expires: ' . ($result['expiresAt'] ?? 'never') . "\n");
        $this->stdout("Store it now — it is not shown again.\n\n");
        $this->stdout("Claude Code / Warp connect via:\n");
        $this->stdout("  npx -y mcp-remote@latest <site-url>/mcp --header \"Authorization: Bearer {$result['token']}\"\n\n");

        return ExitCode::OK;
    }

    public function actionList(): int
    {
        $rows = Auth::listTokens();
        if (!$rows) {
            $this->stdout("No tokens.\n");
            return ExitCode::OK;
        }
        foreach ($rows as $r) {
            $this->stdout(sprintf(
                "#%d  %s  label=%s  expires=%s  lastUsed=%s\n",
                $r['id'],
                $r['email'] ?? "user {$r['userId']}",
                $r['label'] ?? '-',
                $r['expiresAt'] ?? 'never',
                $r['lastUsedAt'] ?? 'never'
            ));
        }
        return ExitCode::OK;
    }

    public function actionRevoke(int $id): int
    {
        if (Auth::revokeToken($id)) {
            $this->stdout("Revoked token #{$id}.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }
        $this->stderr("Token #{$id} not found.\n", Console::FG_RED);
        return ExitCode::UNSPECIFIED_ERROR;
    }
}
