<?php
declare(strict_types=1);
namespace App\WeFact;

/** Serializes validated financial decimal strings as JSON numbers without float conversion. */
final class JsonPayload
{
    public static function encode(#[\SensitiveParameter] array $payload): string
    {
        $json=json_encode($payload,JSON_THROW_ON_ERROR);
        // Emit validated decimal literals as numeric JSON values, without PHP float conversion.
        return preg_replace('/"(Number|PriceExcl)":"(-?[0-9]+(?:\.[0-9]+)?)"/', '"$1":$2', $json);
    }
}
