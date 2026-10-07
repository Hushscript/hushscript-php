<?php

declare(strict_types=1);

/*
 * The golden files of the conformance suite (fixtures/exports) and how each one is rendered. The golden
 * test and the runner's `format` op share this table, so the two cannot drift apart.
 */

namespace Hushscript\Conformance;

use Hushscript\Http\Body;
use Hushscript\Transcript;

final class Golden
{
    /**
     * @param  \Closure(Transcript, ?string): string  $render
     */
    public function __construct(
        public readonly string $file,
        public readonly string $transcript,
        public readonly ?string $language,
        private readonly \Closure $render,
    ) {
    }

    public function render(Transcript $transcript): string
    {
        return ($this->render)($transcript, $this->language);
    }
}

final class Goldens
{
    /** The transcript fixtures (`fixtures/transcripts`, without `.json`). */
    public const TRANSCRIPTS = ['escapes', 'long-utterances', 'multi-renamed', 'no-labels', 'one-speaker', 'over-hour', 'translated'];

    /** Only this transcript has translation and cleaned goldens: `<name>.<variant>.<language>.<ext>`. */
    public const LANGUAGE_TRANSCRIPT = 'translated';

    public const LANGUAGES = ['es', 'cleaned'];

    /** @return list<Golden> every golden of the naming scheme `<transcript>.<variant>[.<language>].<ext>` */
    public static function all(): array
    {
        $variants = [
            ['srt-captions', 'srt', static fn (Transcript $t, ?string $lang): string => $t->toSrt($lang)],
            ['srt-plain', 'srt', static fn (Transcript $t, ?string $lang): string => $t->toSrt($lang, captions: false)],
            ['vtt-captions', 'vtt', static fn (Transcript $t, ?string $lang): string => $t->toVtt($lang)],
            ['vtt-plain', 'vtt', static fn (Transcript $t, ?string $lang): string => $t->toVtt($lang, captions: false)],
            ['txt-default', 'txt', static fn (Transcript $t, ?string $lang): string => $t->toText($lang)],
            ['txt-nospeakers', 'txt', static fn (Transcript $t, ?string $lang): string => $t->toText($lang, speakers: false)],
            ['txt-timestamps', 'txt', static fn (Transcript $t, ?string $lang): string => $t->toText($lang, timestamps: true)],
            ['txt-nomerge', 'txt', static fn (Transcript $t, ?string $lang): string => $t->toText($lang, merge: false)],
        ];
        $out = [];
        foreach (self::TRANSCRIPTS as $transcript) {
            $languages = $transcript === self::LANGUAGE_TRANSCRIPT ? [null, ...self::LANGUAGES] : [null];
            foreach ($variants as [$name, $extension, $render]) {
                foreach ($languages as $language) {
                    $middle = $language === null ? '' : '.'.$language;
                    $out[] = new Golden($transcript.'.'.$name.$middle.'.'.$extension, $transcript, $language, $render);
                }
            }
        }

        return $out;
    }

    /** The transcript fixture as the API body it stands for. */
    public static function transcript(string $conformanceDir, string $name): Transcript
    {
        return self::fromJson((string) file_get_contents($conformanceDir.'/fixtures/transcripts/'.$name.'.json'));
    }

    public static function fromJson(string $json): Transcript
    {
        return Transcript::fromApi(Body::of(json_decode($json, true, 512, JSON_THROW_ON_ERROR), 'transcript fixture'));
    }

    /** The golden's expected bytes. */
    public static function expected(string $conformanceDir, Golden $golden): string
    {
        return (string) file_get_contents($conformanceDir.'/fixtures/exports/'.$golden->file);
    }

    /**
     * The files under fixtures/exports that are not in the table, and the table's files that are not
     * there: a renamed, missing or extra golden shows up on one side or the other.
     *
     * @return array{missing: list<string>, extra: list<string>}
     */
    public static function drift(string $conformanceDir): array
    {
        $expected = array_map(static fn (Golden $golden): string => $golden->file, self::all());
        $found = array_values(array_diff((array) scandir($conformanceDir.'/fixtures/exports'), ['.', '..']));

        return ['missing' => array_values(array_diff($expected, $found)), 'extra' => array_values(array_diff($found, $expected))];
    }
}
