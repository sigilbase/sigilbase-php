<?php

declare(strict_types=1);

use Sigilbase\Canonical\CanonicalJson;
use Sigilbase\Exceptions\InvalidPayloadException;

it('encodes scalars', function () {
    expect(CanonicalJson::encode(null))->toBe('null')
        ->and(CanonicalJson::encode(true))->toBe('true')
        ->and(CanonicalJson::encode(false))->toBe('false')
        ->and(CanonicalJson::encode(0))->toBe('0')
        ->and(CanonicalJson::encode(-42))->toBe('-42')
        ->and(CanonicalJson::encode('plain'))->toBe('"plain"');
});

it('escapes strings the RFC 8785 way', function () {
    expect(CanonicalJson::encode("quote\" slash\\ b\x08 t\t n\n f\x0C r\r"))
        ->toBe('"quote\\" slash\\\\ b\\b t\\t n\\n f\\f r\\r"')
        ->and(CanonicalJson::encode("\x01\x1F"))->toBe('"\\u0001\\u001f"')
        ->and(CanonicalJson::encode('käse 𝄞'))->toBe('"käse 𝄞"');
});

it('distinguishes empty objects from empty arrays', function () {
    expect(CanonicalJson::encode(new stdClass))->toBe('{}')
        ->and(CanonicalJson::encode([]))->toBe('[]')
        ->and(CanonicalJson::encode((object) ['a' => new stdClass, 'b' => []]))->toBe('{"a":{},"b":[]}');
});

it('sorts object keys by UTF-16 code units', function () {
    // 𝄞 (U+1D11E) encodes as a surrogate pair starting 0xD834, which sorts
    // BEFORE ﬄ (U+FB04) in UTF-16 despite being a higher codepoint — the
    // classic JCS ordering trap that codepoint sorting gets wrong.
    $encoded = CanonicalJson::encode((object) ['𝄞' => 1, 'ﬄ' => 2, 'a' => 3, 'é' => 4]);

    expect($encoded)->toBe('{"a":3,"é":4,"𝄞":1,"ﬄ":2}');
});

it('rejects floats with a message pointing at the rationale', function () {
    expect(fn () => CanonicalJson::encode(['ratio' => 0.25]))
        ->toThrow(InvalidPayloadException::class, 'integers only');
});

it('rejects integers beyond 2^53 - 1', function () {
    expect(fn () => CanonicalJson::encode(9007199254740992))
        ->toThrow(InvalidPayloadException::class, 'IEEE-754');
});

it('rejects invalid UTF-8', function () {
    expect(fn () => CanonicalJson::encode("\xC3\x28"))
        ->toThrow(InvalidPayloadException::class, 'UTF-8');
});

it('matches the published verifier byte for byte across a corpus', function () {
    $corpus = [
        null,
        true,
        [1, 2, 3],
        [],
        new stdClass,
        (object) ['n' => 1, 'rôle' => 'admin', 'note' => 'käse 𝄞 clef #1'],
        (object) ['𝄞' => 1, 'ﬄ' => 2, 'a' => 3, 'é' => 4, 'A' => 5, 'zz' => 6],
        (object) ['nested' => (object) ['deep' => [1, 'two', null, (object) []]], 'empty' => []],
        (object) ['controls' => "line\nbreak\ttab \x1F unit", 'quote' => '"\\'],
        ['max' => 9007199254740991, 'min' => -9007199254740991, 'zero' => 0],
    ];

    $published = Sigilbase\Tests\Support\VendoredVerifier::canonicalEncodeAll($corpus);

    foreach ($corpus as $index => $value) {
        expect(CanonicalJson::encode($value))->toBe($published[$index]);
    }
});
