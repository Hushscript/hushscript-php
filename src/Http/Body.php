<?php

declare(strict_types=1);

namespace Hushscript\Http;

/**
 * The one place a 2xx body is checked before any field is used (R52, R58): a cursor over a decoded
 * JSON value whose accessors either return a value of the type asked for or raise `BadBody`. Keys
 * the caller does not ask for pass untouched, so a newer API still parses. Whole numbers are PHP
 * ints (so inside int64 by construction: a bigger JSON number decodes as a float and is refused);
 * floats must be finite.
 *
 * @internal
 */
final class Body
{
    /** The last instant a `DateTimeImmutable` holds in the SDK's range: 9999-12-31T23:59:59Z. */
    public const MAX_UNIX_SECONDS = 253_402_300_799;

    private const INT_LIMIT = 9.2e18;

    /** @param array<mixed> $fields */
    private function __construct(#[\SensitiveParameter] private readonly array $fields, #[\SensitiveParameter] private readonly string $path)
    {
    }

    public static function of(#[\SensitiveParameter] mixed $value, string $what): self
    {
        if (! is_array($value)) {
            throw new BadBody($what.': expected an object');
        }

        return new self($value, $what);
    }

    /** @return array<mixed> */
    public function raw(): array
    {
        return $this->fields;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->fields);
    }

    public function child(string $key): self
    {
        return new self($this->arrayAt($key), $this->at($key));
    }

    /** @return list<self> */
    public function list(string $key): array
    {
        $items = $this->arrayAt($key);
        $out = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new BadBody($this->at($key).'['.$index.']: expected an object');
            }
            $out[] = new self($item, $this->at($key).'['.$index.']');
        }
        if (! array_is_list($items)) {
            throw new BadBody($this->at($key).': expected an array');
        }

        return $out;
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        $items = $this->arrayAt($key);
        if (! array_is_list($items)) {
            throw new BadBody($this->at($key).': expected an array');
        }
        foreach ($items as $index => $item) {
            if (! is_string($item)) {
                throw new BadBody($this->at($key).'['.$index.']: expected a string');
            }
        }

        return $items;
    }

    /**
     * An object of string values (a JSON object whose keys look like integers decodes with integer keys).
     *
     * @return array<string, string>
     */
    public function stringRecord(string $key): array
    {
        $out = [];
        foreach ($this->arrayAt($key) as $name => $value) {
            if (! is_string($value)) {
                throw new BadBody($this->at($key).'.'.$name.': expected a string');
            }
            $out[(string) $name] = $value;
        }

        return $out;
    }

    /**
     * @return array<string, self>
     */
    public function records(string $key): array
    {
        $out = [];
        foreach ($this->arrayAt($key) as $name => $value) {
            if (! is_array($value)) {
                throw new BadBody($this->at($key).'.'.$name.': expected an object');
            }
            $out[(string) $name] = new self($value, $this->at($key).'.'.$name);
        }

        return $out;
    }

    /** @return array<mixed>|null an opaque object, kept as the API sent it */
    public function opaque(string $key): ?array
    {
        $value = $this->fields[$key] ?? null;
        if ($value !== null && ! is_array($value)) {
            throw new BadBody($this->at($key).': expected an object or null');
        }

        return $value;
    }

    public function string(string $key): string
    {
        $value = $this->fields[$key] ?? null;
        if (! is_string($value)) {
            throw new BadBody($this->at($key).': expected a string');
        }

        return $value;
    }

    public function nullableString(string $key): ?string
    {
        return ($this->fields[$key] ?? null) === null ? null : $this->string($key);
    }

    public function bool(string $key): bool
    {
        $value = $this->fields[$key] ?? null;
        if (! is_bool($value)) {
            throw new BadBody($this->at($key).': expected a boolean');
        }

        return $value;
    }

    public function nullableBool(string $key): ?bool
    {
        return ($this->fields[$key] ?? null) === null ? null : $this->bool($key);
    }

    /** A whole number. */
    public function integer(string $key): int
    {
        $value = $this->fields[$key] ?? null;
        if (! is_int($value)) {
            throw new BadBody($this->at($key).': expected a whole number');
        }

        return $value;
    }

    /** A quantity that cannot be negative: seconds, bytes, jobs. */
    public function count(string $key): int
    {
        $value = $this->integer($key);
        if ($value < 0) {
            throw new BadBody($this->at($key).': expected a whole number of 0 or more');
        }

        return $value;
    }

    public function nullableCount(string $key): ?int
    {
        return ($this->fields[$key] ?? null) === null ? null : $this->count($key);
    }

    /** A finite number, floored to whole units (milliseconds). */
    public function floored(string $key): int
    {
        $value = $this->fields[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (! is_float($value) || ! is_finite($value) || abs($value) >= self::INT_LIMIT) {
            throw new BadBody($this->at($key).': expected a number');
        }

        return (int) floor($value);
    }

    public function timestamp(string $key): \DateTimeImmutable
    {
        $value = $this->fields[$key] ?? null;
        if (! is_int($value) || $value < 0 || $value > self::MAX_UNIX_SECONDS) {
            throw new BadBody($this->at($key).': expected a unix timestamp in seconds');
        }

        return (new \DateTimeImmutable('@'.$value))->setTimezone(new \DateTimeZone('UTC'));
    }

    public function nullableTimestamp(string $key): ?\DateTimeImmutable
    {
        return ($this->fields[$key] ?? null) === null ? null : $this->timestamp($key);
    }

    /** @return array<mixed> */
    private function arrayAt(string $key): array
    {
        $value = $this->fields[$key] ?? null;
        if (! is_array($value)) {
            throw new BadBody($this->at($key).': expected '.($this->has($key) ? 'an array or object' : 'a value'));
        }

        return $value;
    }

    private function at(string $key): string
    {
        return $this->path.'.'.$key;
    }
}
