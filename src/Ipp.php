<?php

/**
 * Asset Label - QR code inventory labels for GLPI on Brother QL printers.
 *
 * @author  Krzysztof Andrzej Błachut vel Bakus
 * @license 0BSD
 * @link    https://github.com/Bakus/glpi-assetlabel
 */

declare(strict_types=1);

namespace GlpiPlugin\Assetlabel;

/**
 * Minimal IPP/2.0 message encoding (RFC 8010): enough to query a printer and send a print job.
 */
final class Ipp
{
    public const OPERATION_PRINT_JOB              = 0x0002;
    public const OPERATION_GET_PRINTER_ATTRIBUTES = 0x000B;

    // Value tags
    public const INTEGER            = 0x21;
    public const BOOLEAN            = 0x22;
    public const ENUM               = 0x23;
    public const RANGE              = 0x33;
    public const BEGIN_COLLECTION   = 0x34;
    public const TEXT_WITH_LANGUAGE = 0x35;
    public const NAME_WITH_LANGUAGE = 0x36;
    public const END_COLLECTION     = 0x37;
    public const TEXT               = 0x41;
    public const NAME               = 0x42;
    public const KEYWORD            = 0x44;
    public const URI                = 0x45;
    public const CHARSET            = 0x47;
    public const NATURAL_LANGUAGE   = 0x48;
    public const MIME_MEDIA_TYPE    = 0x49;
    public const MEMBER_NAME        = 0x4A;

    // Delimiter tags
    private const OPERATION_ATTRIBUTES = 0x01;
    private const JOB_ATTRIBUTES       = 0x02;
    private const END_OF_ATTRIBUTES    = 0x03;

    /**
     * Encodes a request. The charset and language attributes are added first, as IPP requires.
     *
     * @param int                                            $operation            operation code (OPERATION_*)
     * @param int                                            $request_id           request number (1 or more)
     * @param array<string, array{int, scalar|list<scalar>}> $operation_attributes name => [value tag, value(s)]
     * @param array<string, array{int, scalar|list<scalar>}> $job_attributes       name => [value tag, value(s)]
     *
     * @return string request without the document data
     */
    public static function encode(
        int $operation,
        int $request_id,
        array $operation_attributes,
        array $job_attributes = [],
    ): string {
        $operation_attributes = [
            'attributes-charset'          => [self::CHARSET, 'utf-8'],
            'attributes-natural-language' => [self::NATURAL_LANGUAGE, 'en'],
        ] + $operation_attributes;

        $data = pack('CCnN', 2, 0, $operation, $request_id);
        $data .= chr(self::OPERATION_ATTRIBUTES) . self::encodeAttributes($operation_attributes);
        if ($job_attributes !== []) {
            $data .= chr(self::JOB_ATTRIBUTES) . self::encodeAttributes($job_attributes);
        }
        return $data . chr(self::END_OF_ATTRIBUTES);
    }

    /**
     * Decodes a response. Attributes of all groups are merged; every attribute is a list of values.
     * Integers and enums become int, booleans bool, ranges [lower, upper], collections arrays of
     * member name => list of values, out-of-band values null, anything else a string.
     *
     * @param string $data response body
     *
     * @return array{status: int, attributes: array<string, list<mixed>>}
     *
     * @throws LabelException when the response is not a valid IPP message
     */
    public static function decode(string $data): array
    {
        $pos = 8;
        self::take($data, $pos, 0);
        $status = (int) unpack('n', $data, 2)[1];

        $attributes = [];
        $name = null;
        while (true) {
            $tag = ord(self::take($data, $pos, 1));
            if ($tag === self::END_OF_ATTRIBUTES) {
                break;
            }
            if ($tag < 0x10) {
                $name = null; // start of the next group
                continue;
            }
            [$attribute, $value] = self::readAttribute($data, $pos, $tag);
            // An empty name adds a value to the previous attribute
            $name = $attribute !== '' ? $attribute : $name;
            if ($name === null) {
                throw self::invalidResponse();
            }
            $attributes[$name][] = $value;
        }

        return ['status' => $status, 'attributes' => $attributes];
    }

    /**
     * Encodes the attributes of one group.
     *
     * @param array<string, array{int, scalar|list<scalar>}> $attributes name => [value tag, value(s)]
     *
     * @return string
     */
    private static function encodeAttributes(array $attributes): string
    {
        $data = '';
        foreach ($attributes as $name => [$tag, $values]) {
            foreach (array_values((array) $values) as $i => $value) {
                $bytes = match ($tag) {
                    self::INTEGER, self::ENUM => pack('N', $value),
                    self::BOOLEAN             => chr((int) $value),
                    default                   => (string) $value,
                };
                // Additional values of the same attribute have an empty name
                $attribute = $i === 0 ? $name : '';
                $data .= chr($tag) . pack('n', strlen($attribute)) . $attribute . pack('n', strlen($bytes)) . $bytes;
            }
        }
        return $data;
    }

    /**
     * Reads one attribute (or additional value) whose value tag has already been read.
     *
     * @param string $data response body
     * @param int    $pos  read position, moved past the attribute
     * @param int    $tag  value tag
     *
     * @return array{string, mixed} [name (empty for additional values), decoded value]
     *
     * @throws LabelException when the data ends too early
     */
    private static function readAttribute(string $data, int &$pos, int $tag): array
    {
        $name  = self::take($data, $pos, (int) unpack('n', self::take($data, $pos, 2))[1]);
        $value = self::take($data, $pos, (int) unpack('n', self::take($data, $pos, 2))[1]);

        if ($tag === self::BEGIN_COLLECTION) {
            return [$name, self::readCollection($data, $pos)];
        }
        return [$name, self::decodeValue($tag, $value)];
    }

    /**
     * Reads the members of a collection, up to its end tag.
     *
     * @param string $data response body
     * @param int    $pos  read position, just after the collection start, moved past its end
     *
     * @return array<string, list<mixed>> member name => values
     *
     * @throws LabelException when the collection is malformed or the data ends too early
     */
    private static function readCollection(string $data, int &$pos): array
    {
        $members = [];
        $member  = null;
        while (true) {
            $tag = ord(self::take($data, $pos, 1));
            [, $value] = self::readAttribute($data, $pos, $tag);
            if ($tag === self::END_COLLECTION) {
                return $members;
            }
            if ($tag === self::MEMBER_NAME) {
                $member = (string) $value;
            } elseif ($member === null) {
                throw self::invalidResponse();
            } else {
                $members[$member][] = $value;
            }
        }
    }

    /**
     * Converts a raw value to a PHP value according to its tag.
     *
     * @param int    $tag   value tag
     * @param string $value raw bytes
     *
     * @return mixed int, bool, list<int>, string or null (see decode())
     */
    private static function decodeValue(int $tag, string $value): mixed
    {
        // Signed 32-bit big-endian integer
        $int = static fn(int $offset): int => (int) unpack('l', pack('l', unpack('N', $value, $offset)[1]))[1];

        if ($tag < 0x20) {
            return null; // out-of-band: unsupported, unknown or no value
        }
        return match ($tag) {
            self::INTEGER, self::ENUM                          => strlen($value) === 4 ? $int(0) : $value,
            self::BOOLEAN                                      => $value === "\x01",
            self::RANGE                                        => strlen($value) === 8 ? [$int(0), $int(4)] : $value,
            self::TEXT_WITH_LANGUAGE, self::NAME_WITH_LANGUAGE => self::withoutLanguage($value),
            default                                            => $value,
        };
    }

    /**
     * Text of a textWithLanguage or nameWithLanguage value (the language is dropped).
     *
     * @param string $value raw bytes: language length, language, text length, text
     *
     * @return string
     */
    private static function withoutLanguage(string $value): string
    {
        $language_length = strlen($value) >= 2 ? (int) unpack('n', $value)[1] : 0;
        return substr($value, 4 + $language_length);
    }

    /**
     * Returns the next bytes and moves the read position.
     *
     * @param string $data   response body
     * @param int    $pos    read position
     * @param int    $length number of bytes
     *
     * @return string
     *
     * @throws LabelException when the data ends too early
     */
    private static function take(string $data, int &$pos, int $length): string
    {
        if ($pos + $length > strlen($data)) {
            throw self::invalidResponse();
        }
        $bytes = substr($data, $pos, $length);
        $pos += $length;
        return $bytes;
    }

    /**
     * Error for a response that cannot be decoded.
     *
     * @return LabelException
     */
    private static function invalidResponse(): LabelException
    {
        return new LabelException(__('The printer sent an invalid IPP response.', 'assetlabel'));
    }
}
