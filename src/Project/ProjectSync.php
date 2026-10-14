<?php
declare(strict_types=1);
namespace App\Project;

use App\Toggl\TogglClient;

/** Fetches and validates the full Toggl project catalog before updating local records. */
final class ProjectSync
{
    public function __construct(private readonly TogglClient $client, private readonly ProjectRepository $projects) {}

    public function refresh(string $fingerprint): int
    {
        return $this->projects->synchronize($this->client->projectCatalog(), $fingerprint);
    }
}
