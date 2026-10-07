<?php

declare(strict_types=1);

namespace Hushscript;

/**
 * One string that must not reach a log, a dump or a stack trace: a signed part URL, a resume token.
 *
 * Like Credentials, the value lives in a static WeakMap keyed by the instance, so the object has no
 * property that var_export, print_r, var_dump, json_encode, serialize or a trace argument could
 * reach. It is passed around in place of the string and unwrapped only where the string is used.
 */
final class Secret
{
    /** @var \WeakMap<self, string>|null */
    private static ?\WeakMap $values = null;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        self::$values ??= new \WeakMap();
        self::$values[$this] = $value;
    }

    public function reveal(): string
    {
        return self::$values[$this] ?? '';
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->reveal(), $other->reveal());
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => Redact::MARKER];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Secret cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Secret cannot be unserialized.');
    }

    public function __clone()
    {
        throw new \LogicException('Secret cannot be cloned.');
    }
}
