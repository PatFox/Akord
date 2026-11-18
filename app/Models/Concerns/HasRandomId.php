<?php

namespace App\Models\Concerns;

use App\Support\RandomId;
use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;

/**
 * A random string primary key (App\Support\RandomId), set when the model is created.
 * Route binding answers 404 for a value that isn't a well-formed id, without querying.
 */
trait HasRandomId
{
    use HasUniqueStringIds;

    public function newUniqueId(): string
    {
        return RandomId::generate();
    }

    protected function isValidUniqueId(mixed $value): bool
    {
        return RandomId::isValid($value);
    }
}
