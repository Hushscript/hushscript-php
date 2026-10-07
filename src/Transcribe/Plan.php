<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Exception\UploadError;
use Hushscript\Secret;

/**
 * The part plan of a create or `part-urls` response (spec 8.3): how the file is cut and the signed
 * URLs fetched so far. The URLs are kept off every dump, trace and string form of the plan.
 */
final class Plan
{
    public const MAX_PART_BYTES = 32 * 1024 * 1024;

    /** @var \WeakMap<self, array<int, Secret>>|null */
    private static ?\WeakMap $urls = null;

    /**
     * @param  array<int, Secret>  $urls  part number to signed URL
     */
    public function __construct(
        public readonly int $partSize,
        public readonly int $totalParts,
        #[\SensitiveParameter] array $urls,
        public readonly ?int $nextPart,
    ) {
        self::$urls ??= new \WeakMap();
        self::$urls[$this] = $urls;
    }

    /** @return array<int, Secret> */
    public function urls(): array
    {
        return self::$urls[$this] ?? [];
    }

    /** The 32 MiB cut a proxied upload uses when the API gave no plan. */
    public static function proxied(int $size): self
    {
        return new self(self::MAX_PART_BYTES, max(1, intdiv($size + self::MAX_PART_BYTES - 1, self::MAX_PART_BYTES)), [], null);
    }

    /**
     * @param  array<mixed>  $fields
     *
     * @throws \Hushscript\Exception\ApiError bad_response when the plan cannot be used
     */
    public static function parse(#[\SensitiveParameter] array $fields): self
    {
        $partSize = Shape::positiveInt($fields['part_size_bytes'] ?? null);
        $total = Shape::positiveInt($fields['total_parts'] ?? null);
        $next = $fields['next_part_number'] ?? null;
        if ($partSize === null || $total === null || ($next !== null && Shape::positiveInt($next) === null)) {
            throw Shape::bad('an upload plan');
        }
        $urls = [];
        $listed = $fields['part_urls'] ?? [];
        if (! is_array($listed)) {
            throw Shape::bad('an upload plan');
        }
        foreach ($listed as $item) {
            $n = is_array($item) ? Shape::positiveInt($item['n'] ?? null) : null;
            $url = is_array($item) ? ($item['url'] ?? null) : null;
            if ($n === null || ! is_string($url) || $url === '') {
                throw Shape::bad('a part URL list');
            }
            $urls[$n] = new Secret($url);
        }

        return new self($partSize, $total, $urls, is_int($next) ? $next : null);
    }

    /** The plan checked against the file: one part per `part_size` bytes, no more and no fewer. */
    public function checkedFor(int $size): self
    {
        if ($this->totalParts !== intdiv($size + $this->partSize - 1, $this->partSize)) {
            throw new UploadError("The API's upload plan does not describe the file's parts.", 'bad_part_plan');
        }

        return $this;
    }

    /** @return array{int, int}  offset and length of part `$n` */
    public function range(int $size, int $n): array
    {
        $offset = ($n - 1) * $this->partSize;

        return [$offset, max(0, min($this->partSize, $size - $offset))];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['partSize' => (string) $this->partSize, 'totalParts' => (string) $this->totalParts, 'urls' => '…[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Plan cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Plan cannot be unserialized.');
    }
}
