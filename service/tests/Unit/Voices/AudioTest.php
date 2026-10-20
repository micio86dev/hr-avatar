<?php

use App\Voices\Audio;

// Review sf11 R3-audio-isaudio-utf8-truncation: the check reads the first 512 bytes, which can end inside a multibyte
// character. `mb_strcut` cuts at a character boundary, so text that straddles the limit is still text; this pins it.
describe('Audio::isAudio', function () {
    it('reads text as text wherever a multibyte character falls across the 512th byte', function (string $character) {
        $width = strlen($character);

        foreach (range(512 - $width, 511) as $padding) {
            $body = str_repeat('a', $padding).$character.str_repeat('b', 40);

            expect(Audio::isAudio($body))->toBeFalse("{$character} after {$padding} bytes");
        }
    })->with(['two bytes' => ['é'], 'three bytes' => ['€'], 'four bytes' => ['😀']]);

    it('reads a binary header as audio, however much text follows it', function () {
        expect(Audio::isAudio("ID3\x03\x00\x00".str_repeat('a', 600)))->toBeTrue()
            ->and(Audio::isAudio("\xFF\xFB\x90\x00".str_repeat('a', 600)))->toBeTrue();
    });

    it('reads a body that is invalid UTF-8 inside the first 512 bytes as audio', function () {
        expect(Audio::isAudio(str_repeat('a', 100)."\xC3\x28".str_repeat('b', 600)))->toBeTrue();
    });
});
