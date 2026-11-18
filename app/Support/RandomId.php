<?php

namespace App\Support;

/**
 * Primary keys of Akord's models: 16 random characters from [0-9a-z] (about 82 bits).
 *
 * Lower case only: MySQL's default collations compare case-insensitively (D12), so
 * mixed-case ids would match each other there but not on SQLite or PostgreSQL.
 */
final class RandomId
{
    public const LENGTH = 16;

    /** Route-parameter pattern for an id. */
    public const PATTERN = '[0-9a-z]{16}';

    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';

    public static function generate(): string
    {
        do {
            $id = '';

            for ($i = 0; $i < self::LENGTH; $i++) {
                $id .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (ctype_digit($id)); // PHP would turn an all-digit array key into an int.

        return $id;
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A'.self::PATTERN.'\z/', $value) === 1;
    }
}
