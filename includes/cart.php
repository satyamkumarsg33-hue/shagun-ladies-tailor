<?php

declare(strict_types=1);

const DEMO_CART_TIMEOUT = 2700;
const DEMO_CART_WARNING_AT = 2400;

function demo_cart_bootstrap(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['demo_cart'])) {
        $_SESSION['demo_cart'] = [];
    }

    $isLoggedIn = function_exists('is_user_logged_in') ? is_user_logged_in() : (!empty($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['email']));
    $_SESSION['demo_logged_in'] = $isLoggedIn;

    $lastActivity = (int) ($_SESSION['demo_cart_last_activity'] ?? time());
    $isGuest = !$isLoggedIn;

    if ($isGuest && $_SESSION['demo_cart'] !== [] && time() - $lastActivity >= DEMO_CART_TIMEOUT) {
        $_SESSION['demo_cart'] = [];
        $_SESSION['demo_cart_expired'] = true;
    }

    if (!isset($_SESSION['demo_cart_last_activity'])) {
        $_SESSION['demo_cart_last_activity'] = time();
    }
}

function demo_cart_touch(): void
{
    demo_cart_bootstrap();
    $_SESSION['demo_cart_last_activity'] = time();
    unset($_SESSION['demo_cart_expired']);
}

function demo_cart_items(): array
{
    demo_cart_bootstrap();
    return $_SESSION['demo_cart'];
}

function demo_cart_add(array $item): void
{
    demo_cart_touch();
    $_SESSION['demo_cart'][] = $item;
}

function demo_cart_update(string $id, array $item): bool
{
    demo_cart_touch();

    foreach ($_SESSION['demo_cart'] as $index => $existing) {
        if (($existing['id'] ?? '') === $id) {
            $_SESSION['demo_cart'][$index] = $item;
            return true;
        }
    }

    return false;
}

function demo_cart_item(string $id): ?array
{
    foreach (demo_cart_items() as $item) {
        if (($item['id'] ?? '') === $id) {
            return $item;
        }
    }

    return null;
}

function demo_cart_remove(string $id): void
{
    demo_cart_touch();
    $_SESSION['demo_cart'] = array_values(array_filter($_SESSION['demo_cart'], static fn (array $item): bool => ($item['id'] ?? '') !== $id));
}

function demo_cart_clear(): void
{
    demo_cart_touch();
    $_SESSION['demo_cart'] = [];
}

function demo_cart_total(): int
{
    return array_sum(array_map(static fn (array $item): int => (int) ($item['total'] ?? 0), demo_cart_items()));
}

function demo_cart_count(): int
{
    return count(demo_cart_items());
}

function demo_cart_expires_in(): int
{
    demo_cart_bootstrap();

    if ($_SESSION['demo_logged_in'] || $_SESSION['demo_cart'] === []) {
        return 0;
    }

    return max(0, DEMO_CART_TIMEOUT - (time() - (int) $_SESSION['demo_cart_last_activity']));
}

function demo_cart_is_expired(): bool
{
    demo_cart_bootstrap();
    return !empty($_SESSION['demo_cart_expired']);
}
