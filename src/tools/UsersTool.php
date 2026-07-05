<?php

namespace gerry3010\mcp\tools;

use Craft;
use craft\elements\User;

/**
 * User management (system-write for writes). Reads require the "editUsers"
 * permission; writes require admin. Creating/altering admin users is refused
 * unless the acting user is an admin (Craft enforces this too).
 */
class UsersTool
{
    public static function listUsers(array $args): array
    {
        Support::requirePermission('editUsers');
        $query = User::find()->status(null);
        if (!empty($args['group'])) {
            $query->group($args['group']);
        }
        if (!empty($args['search'])) {
            $query->search($args['search']);
        }
        $query->limit((int)($args['limit'] ?? 50))->offset((int)($args['offset'] ?? 0));

        $users = array_map(static fn(User $u) => self::brief($u), $query->all());
        return ['count' => count($users), 'users' => $users];
    }

    public static function getUser(array $args): array
    {
        Support::requirePermission('editUsers');
        $u = self::find($args);
        $out = self::brief($u);
        $out['groups'] = array_map(static fn($g) => $g->handle, $u->getGroups());
        $out['fields'] = Support::serialize($u, false)['fields'] ?? [];
        return $out;
    }

    public static function createUser(array $args): array
    {
        Support::requireAdmin();
        $user = new User();
        $user->email = $args['email'] ?? null;
        $user->username = $args['username'] ?? $args['email'] ?? null;
        $user->firstName = $args['firstName'] ?? null;
        $user->lastName = $args['lastName'] ?? null;
        if (!empty($args['fields']) && is_array($args['fields'])) {
            foreach ($args['fields'] as $handle => $value) {
                $user->setFieldValue($handle, $value);
            }
        }
        Support::save($user, 'user');

        if (!empty($args['groups']) && is_array($args['groups'])) {
            self::assignGroups($user, $args['groups']);
        }
        if (!empty($args['activate'])) {
            Craft::$app->getUsers()->activateUser($user);
        }
        return self::brief($user);
    }

    public static function updateUser(array $args): array
    {
        Support::requireAdmin();
        $user = self::find($args);
        foreach (['email', 'username', 'firstName', 'lastName'] as $prop) {
            if (array_key_exists($prop, $args) && $args[$prop] !== null) {
                $user->$prop = $args[$prop];
            }
        }
        if (!empty($args['fields']) && is_array($args['fields'])) {
            foreach ($args['fields'] as $handle => $value) {
                $user->setFieldValue($handle, $value);
            }
        }
        Support::save($user, 'user');
        if (array_key_exists('groups', $args) && is_array($args['groups'])) {
            self::assignGroups($user, $args['groups']);
        }
        return self::brief($user);
    }

    public static function deleteUser(array $args): array
    {
        Support::requireAdmin();
        $user = self::find($args);
        if (!Craft::$app->getElements()->deleteElement($user)) {
            throw new ValidationException("Failed to delete user {$user->id}", $user->getErrors());
        }
        return ['id' => $user->id, 'deleted' => true];
    }

    public static function listUserGroups(array $args): array
    {
        return array_map(static fn($g) => [
            'handle' => $g->handle,
            'name' => $g->name,
            'uid' => $g->uid,
        ], Craft::$app->getUserGroups()->getAllGroups());
    }

    // -- internals ----------------------------------------------------------

    private static function assignGroups(User $user, array $groupHandles): void
    {
        $ids = [];
        foreach ($groupHandles as $handle) {
            $group = Craft::$app->getUserGroups()->getGroupByHandle($handle);
            if (!$group) {
                throw new \RuntimeException("User group not found: {$handle}");
            }
            $ids[] = $group->id;
        }
        Craft::$app->getUsers()->assignUserToGroups($user->id, $ids);
    }

    private static function find(array $args): User
    {
        $user = null;
        if (!empty($args['id'])) {
            $user = User::find()->id((int)$args['id'])->status(null)->one();
        } elseif (!empty($args['email'])) {
            $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($args['email']);
        }
        if (!$user) {
            throw new \RuntimeException('User not found (provide "id" or "email")');
        }
        return $user;
    }

    private static function brief(User $u): array
    {
        return [
            'id' => $u->id,
            'email' => $u->email,
            'username' => $u->username,
            'fullName' => $u->getFullName(),
            'status' => $u->getStatus(),
            'admin' => (bool)$u->admin,
        ];
    }
}
