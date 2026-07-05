<?php

namespace gerry3010\mcp\mcp;

use Craft;
use craft\elements\User;
use craft\helpers\Db;
use DateTime;
use DateTimeZone;

/**
 * Bearer-token authentication bound to a Craft user.
 *
 * A token is a random string; only its SHA-256 hash is stored (table
 * {{%mcp_tokens}}). On each request the bearer is hashed, looked up, checked for
 * expiry, and the associated Craft user becomes the request identity — so every
 * tool call is bounded by that user's Craft permissions.
 */
class Auth
{
    public const TABLE = '{{%mcp_tokens}}';

    /**
     * Mint a new token for a user. Returns the raw token (shown once).
     */
    public static function createToken(int $userId, ?string $label = null, int $ttlDays = 90): array
    {
        $raw = Craft::$app->getSecurity()->generateRandomString(48);
        $hash = hash('sha256', $raw);
        $expiresAt = null;
        if ($ttlDays > 0) {
            $expiresAt = (new DateTime('now', new DateTimeZone('UTC')))
                ->modify("+{$ttlDays} days");
        }

        Db::insert(self::TABLE, [
            'userId' => $userId,
            'tokenHash' => $hash,
            'label' => $label,
            'expiresAt' => $expiresAt ? Db::prepareDateForDb($expiresAt) : null,
        ]);

        return [
            'id' => (int)Craft::$app->getDb()->getLastInsertID(),
            'token' => $raw,
            'expiresAt' => $expiresAt?->format(DATE_ATOM),
        ];
    }

    /**
     * Resolve the request's Bearer token to a Craft user, or null.
     */
    public static function authenticate(): ?User
    {
        $header = Craft::$app->getRequest()->getHeaders()->get('Authorization');
        if (!$header || !preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return null;
        }
        $hash = hash('sha256', trim($m[1]));

        $row = (new \craft\db\Query())
            ->select(['id', 'userId', 'expiresAt'])
            ->from(self::TABLE)
            ->where(['tokenHash' => $hash])
            ->one();

        if (!$row) {
            return null;
        }
        if (!empty($row['expiresAt']) && new DateTime($row['expiresAt'], new DateTimeZone('UTC')) < new DateTime('now', new DateTimeZone('UTC'))) {
            return null;
        }

        $user = User::find()->id((int)$row['userId'])->status(null)->one();
        if (!$user || $user->getStatus() !== User::STATUS_ACTIVE) {
            return null;
        }

        // Touch lastUsedAt (best-effort).
        try {
            Db::update(self::TABLE, ['lastUsedAt' => Db::prepareDateForDb(new DateTime('now', new DateTimeZone('UTC')))], ['id' => $row['id']]);
        } catch (\Throwable) {
            // ignore
        }

        return $user;
    }

    /**
     * Make the given user the identity for this request so permission checks and
     * authorship resolve correctly.
     */
    public static function setIdentity(User $user): void
    {
        Craft::$app->getUser()->setIdentity($user);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listTokens(): array
    {
        return (new \craft\db\Query())
            ->select(['t.id', 't.userId', 't.label', 't.expiresAt', 't.lastUsedAt', 't.dateCreated', 'u.email'])
            ->from(['t' => self::TABLE])
            ->leftJoin(['u' => '{{%users}}'], '[[u.id]] = [[t.userId]]')
            ->orderBy(['t.dateCreated' => SORT_DESC])
            ->all();
    }

    public static function revokeToken(int $id): bool
    {
        return (bool)Db::delete(self::TABLE, ['id' => $id]);
    }
}
