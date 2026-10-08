<?php

declare(strict_types=1);

namespace Hushscript;

/**
 * For a model built from one API object: `toJson()` returns that object as received, unknown fields
 * included. Dumps (`print_r`, `var_dump`) show the named fields only, as a transcript's do.
 *
 * @internal
 */
trait RawBody
{
    /**
     * The API object this was built from, unknown fields included (snake_case keys, as received).
     *
     * @return array<mixed>
     */
    public function toJson(): array
    {
        return $this->raw;
    }

    /** @return array<mixed> */
    public function __debugInfo(): array
    {
        $fields = get_object_vars($this);
        unset($fields['raw']);

        return $fields;
    }
}
