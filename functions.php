<?php
declare(strict_types=1);

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('asset_url')) {
    function asset_url(string $path): string
    {
        return '/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('current_year')) {
    function current_year(): string
    {
        return date('Y');
    }
}

if (!function_exists('money')) {
    function money(float $amount, string $currency = 'USD'): string
    {
        return $currency . ' ' . number_format($amount, 2);
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = ''): string
    {
        return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('is_post')) {
    function is_post(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}

if (!function_exists('listing_status_options')) {
    function listing_status_options(): array
    {
        return ['draft', 'pending', 'active', 'sold', 'hidden'];
    }
}

if (!function_exists('old')) {
    function old(array $source, string $key, string $default = ''): string
    {
        return isset($source[$key]) ? trim((string)$source[$key]) : $default;
    }
}

if (!function_exists('to_int')) {
    function to_int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int)$value : $default;
    }
}