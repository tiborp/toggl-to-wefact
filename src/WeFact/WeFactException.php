<?php
declare(strict_types=1);
namespace App\WeFact;

/** Carries safe API failure context; an unknown write outcome must not trigger a blind retry. */
final class WeFactException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $kind, public readonly ?bool $connected = null, public readonly ?bool $authenticated = null, public readonly string $outcome = 'unknown', public readonly array $diagnostics = [])
    {
        parent::__construct($message);
    }
}
