<?php

declare(strict_types=1);

namespace Hushscript;

/**
 * The API token and every earlier one, kept out of every representation.
 *
 * The values live in a static WeakMap keyed by the instance, so the object has no property that
 * var_export, print_r, var_dump, json_encode or a reflection dump could reach.
 */
final class Credentials
{
    // A short, ordinary substring must not be redacted everywhere.
    private const MIN_SECRET_TAIL = 16;

    /** @var \WeakMap<self, list<string>>|null */
    private static ?\WeakMap $tokens = null;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        self::$tokens ??= new \WeakMap();
        self::$tokens[$this] = [$token];
    }

    public function token(): string
    {
        $tokens = self::$tokens[$this] ?? [];

        return $tokens === [] ? '' : $tokens[array_key_last($tokens)];
    }

    public function replace(#[\SensitiveParameter] string $token): void
    {
        self::$tokens ??= new \WeakMap();
        self::$tokens[$this] = [...(self::$tokens[$this] ?? []), $token];
    }

    /** Removes the current token, the replaced ones and anything shaped like a token from the text. */
    public function scrub(#[\SensitiveParameter] string $text): string
    {
        foreach (self::$tokens[$this] ?? [] as $token) {
            if ($token === '') {
                continue;
            }
            // The whole token, as it is and percent-encoded, in any letter case.
            $text = str_ireplace([$token, rawurlencode($token)], Redact::MARKER, $text);
            // An API that echoes only the secret half is just as much a leak.
            $position = strpos($token, 'pat.');
            if ($position !== false) {
                $afterPrefix = substr($token, $position + 4);
                $dot = strrpos($afterPrefix, '.');
                foreach ($dot === false ? [$afterPrefix] : [$afterPrefix, substr($afterPrefix, $dot + 1)] as $tail) {
                    if (strlen($tail) >= self::MIN_SECRET_TAIL) {
                        $text = str_ireplace([$tail, rawurlencode($tail)], Redact::MARKER, $text);
                    }
                }
            }
        }

        return Redact::text($text);
    }

    public function scrubValue(#[\SensitiveParameter] mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->scrub($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[is_string($key) ? $this->scrub($key) : $key] = $this->scrubValue($item);
            }

            return $out;
        }

        return $value;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['token' => Redact::MARKER];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Credentials cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Credentials cannot be unserialized.');
    }

    public function __clone()
    {
        throw new \LogicException('Credentials cannot be cloned.');
    }
}
