<?php
declare(strict_types=1);
namespace App\Toggl;

/** Loads user, workspace and project status in bulk, retaining partial connection feedback. */
final class StatusService
{
    public function __construct(private readonly TogglClient $client) {}

    public function load(?string $requestedWorkspace = null): array
    {
        $result = ['connected' => false, 'user' => null, 'workspaces' => [], 'selected' => null, 'projects' => null, 'error' => null, 'loaded_at' => date('c')];
        try {
            if ($requestedWorkspace !== null && $requestedWorkspace !== '' && !preg_match('/^[1-9][0-9]{0,18}$/D', $requestedWorkspace)) {
                throw new TogglException('Selecteer een geldige workspace.');
            }
            $result['user'] = $this->client->user();
            $result['connected'] = true;
            $result['workspaces'] = $this->client->workspaces();
            if ($result['workspaces'] === []) { return $result; }
            $workspace = $result['workspaces'][0];
            if ($requestedWorkspace !== null && $requestedWorkspace !== '') {
                $matches = array_filter($result['workspaces'], static fn ($row) => (string) $row['id'] === $requestedWorkspace);
                if ($matches === []) { throw new TogglException('Deze workspace is niet beschikbaar voor de ingelogde gebruiker.'); }
                $workspace = reset($matches);
            }
            $result['selected'] = $workspace['id'];
            $result['projects'] = $this->client->projects($workspace['id'], $workspace['organization_id']);
        } catch (TogglException $error) {
            $result['error'] = $error->getMessage();
        }
        return $result;
    }
}
