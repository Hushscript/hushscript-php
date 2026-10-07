<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Format\Srt;
use Hushscript\Format\Text;
use Hushscript\Format\Time;
use Hushscript\Format\Vtt;
use Hushscript\Http\Body;

/**
 * A finished transcript with its formatters (spec 6.2). `toJson()` is the API's body.
 */
final class Transcript
{
    /** The reserved translation key that holds the AI-cleaned body. */
    public const CLEANED = 'cleaned';

    /**
     * @param  list<string>  $tags
     * @param  array<string, string>  $speakers  label to display name, for the speakers that were renamed
     * @param  list<Utterance>  $utterances  the original language
     * @param  list<string>  $languages  the translation locales, without the reserved `cleaned`, in the API's order
     * @param  list<Utterance>|null  $cleaned  the AI-cleaned body, when there is one
     * @param  array<mixed>  $raw
     * @param  array<string, list<Utterance>>  $translations
     */
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $language,
        public readonly int $durationSeconds,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly ?\DateTimeImmutable $autoDeleteAt,
        public readonly array $tags,
        public readonly bool $edited,
        public readonly array $speakers,
        public readonly array $utterances,
        public readonly array $languages,
        public readonly ?array $cleaned,
        #[\SensitiveParameter] private readonly array $raw,
        private readonly array $translations,
    ) {
    }

    /**
     * Builds a transcript from a `GET /v1/transcripts/{id}` body.
     *
     * @throws \Hushscript\Http\BadBody when the body does not fit; the client reports `bad_response`
     *
     * @internal
     */
    public static function fromApi(#[\SensitiveParameter] Body $body): self
    {
        $names = $body->stringRecord('speaker_names');
        $envelope = $body->child('body');
        $translations = [];
        foreach ($envelope->records('translations') as $locale => $language) {
            $translations[$locale] = self::utterances($language, $names);
        }

        return new self(
            $body->string('id'),
            $body->string('title'),
            $body->nullableString('language_detected'),
            $body->count('duration_seconds'),
            $body->timestamp('created_at'),
            $body->timestamp('updated_at'),
            $body->nullableTimestamp('auto_delete_at'),
            $body->strings('tags'),
            $body->bool('edited'),
            $names,
            self::utterances($envelope->child('original'), $names),
            array_values(array_filter(array_keys($translations), static fn (string $locale): bool => $locale !== self::CLEANED)),
            $translations[self::CLEANED] ?? null,
            $body->raw(),
            $translations,
        );
    }

    /** The plain text, the same as `toText()`. */
    public function text(): string
    {
        return $this->toText();
    }

    /**
     * The translation (or `cleaned` body) for `$language`; the original when `$language` is empty or
     * the transcript has no such translation.
     *
     * @return list<Utterance>
     */
    public function utterancesFor(?string $language = null): array
    {
        if ($language === null || $language === '') {
            return $this->utterances;
        }

        return $this->translations[$language] ?? $this->utterances;
    }

    public function toSrt(?string $language = null, bool $captions = true): string
    {
        return Srt::render($this->utterancesFor($language), $captions);
    }

    public function toVtt(?string $language = null, bool $captions = true): string
    {
        return Vtt::render($this->utterancesFor($language), $captions);
    }

    public function toText(?string $language = null, bool $speakers = true, bool $timestamps = false, bool $merge = true): string
    {
        return Text::render($this->utterancesFor($language), $speakers, $timestamps, $merge);
    }

    /**
     * The API body this transcript was built from, unknown fields included.
     *
     * @return array<mixed>
     */
    public function toJson(): array
    {
        return $this->raw;
    }

    /**
     * The name shown for a speaker label.
     *
     * @param  array<string, string>  $names
     */
    public static function displayName(string $label, array $names): string
    {
        $saved = Time::strip($names[$label] ?? '');
        if ($saved !== '') {
            return $saved;
        }

        return Time::strip($label) === '' ? '' : 'Speaker '.$label;
    }

    /**
     * @param  array<string, string>  $names
     * @return list<Utterance>
     */
    private static function utterances(Body $language, array $names): array
    {
        $out = [];
        foreach ($language->list('utterances') as $item) {
            $speaker = $item->string('speaker');
            $out[] = new Utterance($speaker, self::displayName($speaker, $names), $item->floored('start_ms'), $item->floored('end_ms'), $item->string('text'));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'durationSeconds' => $this->durationSeconds, 'language' => $this->language];
    }
}
