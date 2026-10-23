<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * `unique` ignoring letter case, for the user names and e-mail addresses accounts sign in with:
 * the sign-in matches them in any case (App\Support\Auth\LoginIdentifier), so a value that differs
 * from another account's in case only is taken. Like `unique:<table>,<column>` it reads the default
 * connection, and $ignoreId leaves out the row being updated.
 */
class UniqueIgnoringCase implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly string $column,
        private readonly int|string|null $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $taken = DB::table($this->table)
            ->whereRaw("lower({$this->column}) = lower(?)", [$value])
            ->when($this->ignoreId !== null, fn ($query) => $query->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($taken) {
            $fail('validation.unique')->translate();
        }
    }
}
