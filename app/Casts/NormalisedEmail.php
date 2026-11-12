<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Stores email addresses trimmed and lowercased, so lookups behave the same on
 * case-insensitive (MySQL) and case-sensitive (SQLite, PostgreSQL) collations.
 * Queries must normalise their input with normalise() too.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class NormalisedEmail implements CastsAttributes
{
    public static function normalise(string $email): string
    {
        return Str::lower(trim($email));
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : self::normalise((string) $value);
    }
}
