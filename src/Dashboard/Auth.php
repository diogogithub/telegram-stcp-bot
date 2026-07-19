<?php

declare(strict_types=1);

namespace Diogo\StcpChatbot\Dashboard;

use Diogo\StcpChatbot\Config\Config;

final readonly class Auth
{
    public function __construct(private Config $config)
    {
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name($this->config->string('SESSION_COOKIE_NAME', 'stcp_admin'));
        session_set_cookie_params([
            'httponly' => true,
            'secure' => $this->config->bool('SESSION_COOKIE_SECURE', true),
            'samesite' => 'Strict',
            'path' => $this->config->string('SESSION_COOKIE_PATH', '/'),
        ]);
        session_start();
    }

    public function loggedIn(): bool
    {
        return isset($_SESSION['admin']) && is_string($_SESSION['admin']);
    }

    public function actor(): string
    {
        return is_string($_SESSION['admin'] ?? null) ? $_SESSION['admin'] : 'unknown';
    }

    public function login(string $username, string $password): bool
    {
        $expectedUser = $this->config->string('ADMIN_USERNAME', 'admin');
        $hash = $this->config->string('ADMIN_PASSWORD_HASH');
        if (!hash_equals($expectedUser, trim($username)) || !password_verify($password, $hash)) {
            usleep(350000);
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = $expectedUser;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    public function csrf(): string
    {
        if (!is_string($_SESSION['csrf'] ?? null)) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public function validCsrf(?string $token): bool
    {
        return is_string($token) && hash_equals($this->csrf(), $token);
    }
}
