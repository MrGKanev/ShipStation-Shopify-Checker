<?php
declare(strict_types=1);

final class UserManagementActions
{
    public static function add(): never
    {
        $username = trim($_POST['new_username'] ?? '');
        $password = $_POST['new_password'] ?? '';
        $role = $_POST['new_role'] ?? 'viewer';
        $users = Auth::loadUsers();

        if ($error = self::validateNewUser($users, $username, $password, $role)) {
            header('Location: ?page=settings&user_error=' . urlencode($error));
            exit;
        }

        $users[] = ['name' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role];
        Auth::saveUsers($users);
        UserActionLog::append('add_user', ['username' => $username, 'role' => $role]);
        header('Location: ?page=settings&user_added=1');
        exit;
    }

    public static function delete(): never
    {
        $username = trim($_POST['username'] ?? '');
        if ($username === '') {
            header('Location: ?page=settings');
            exit;
        }

        $users = array_values(array_filter(Auth::loadUsers(), fn($user) => ($user['name'] ?? '') !== $username));
        Auth::saveUsers($users);
        UserActionLog::append('delete_user', ['username' => $username]);
        header('Location: ?page=settings&user_deleted=1');
        exit;
    }

    /** @param array<int, array{name: string, password_hash: string, role: string}> $existingUsers */
    public static function validateNewUser(array $existingUsers, string $username, string $password, string $role): ?string
    {
        if (!in_array($role, ['viewer', 'operator', 'admin'], true)) return 'Invalid role.';
        if ($username === '' || $password === '') return 'Username and password are required.';
        foreach ($existingUsers as $user) {
            if (($user['name'] ?? '') === $username) return 'A user with that username already exists.';
        }
        return null;
    }
}
