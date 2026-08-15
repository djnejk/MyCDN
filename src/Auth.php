<?php

declare(strict_types=1);

namespace MyCDN;

final class Auth
{
    public function __construct(private readonly array $config)
    {
    }

    public function login(string $username, string $password): bool
    {
        $admin = $this->config['admin'];
        if (!hash_equals((string) $admin['username'], $username)) {
            return false;
        }

        if (!password_verify($password, (string) $admin['password_hash'])) {
            return false;
        }

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        session_regenerate_id(true);

        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function check(): bool
    {
        return ($_SESSION['admin_logged_in'] ?? false) === true;
    }

    public function requireAdmin(): void
    {
        if (!$this->check()) {
            header('Location: index.php');
            exit;
        }
    }

    public function checkApiToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        foreach (($this->config['api_tokens'] ?? []) as $validToken) {
            if (hash_equals((string) $validToken, $token)) {
                return true;
            }
        }

        return false;
    }
}
