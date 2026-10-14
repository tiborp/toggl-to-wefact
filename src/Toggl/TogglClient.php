<?php
declare(strict_types=1);
namespace App\Toggl;

use JsonException;

/** Validates Toggl API responses and shares quota state across reads and additive tag writes. */
final class TogglClient
{
    private array $responses = [];
    private ?array $tagDiagnostic=null;
    public function tagDiagnostic(): ?array { return $this->tagDiagnostic; }

    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
        private readonly HttpTransport $http,
        private readonly Quota $quota,
    ) {}

    /** Single-entry additive update: no existing tags are sent. */
    /**
     * Perform at most one additive PUT; do not resend existing tags or create tags separately.
     * An unconfirmed response is unknown rather than proof the entry was not changed.
     * The caller persists the per-entry result and diagnostics; this method does not touch SQLite.
     *
     * @return array{state: 'pending'|'synced'|'failed'|'unknown', stop: bool}
     */
    public function addInvoiced(int $workspace,int $entryId): array
    {
        $this->tagDiagnostic=null;
        $endpoint=CurlTransport::invoicedEndpoint($workspace,$entryId);
        if(trim($this->token)===''||!$this->http instanceof TagTransport){throw new TogglException('Toggl-tagsynchronisatie is niet beschikbaar.');}
        $scope='workspace:'.$workspace;
        try{$this->quota->assertAvailable('user');$this->quota->assertAvailable($scope);}
        catch(TogglException){return ['state'=>'pending','stop'=>true];}
        $this->tagDiagnostic=['method'=>'PUT','endpoint'=>'/api/v9'.$endpoint,
            'workspace_id'=>$workspace,'entry_id'=>$entryId,'entry_count'=>1,'entry_ids'=>[$entryId],'payload'=>CurlTransport::INVOICED_UPDATE,
            'attempted_at'=>gmdate('c'),'http_status'=>null,'response_received'=>false,'response_body'=>'','response_headers'=>[]];
        try{$response=$this->http->addInvoiced($workspace,$entryId,$this->token);}
        catch(\Throwable){
            $this->tagDiagnostic['transport_error']='Transport gaf geen response terug.';
            $this->tagDiagnostic['result']='unknown';
            return ['state'=>'unknown','stop'=>true];
        }
        $this->tagDiagnostic=array_replace($this->tagDiagnostic,TagDiagnostic::response($response,$this->token));
        $this->quota->record($scope,$response);
        try{$data=json_decode($response->body,true,512,JSON_THROW_ON_ERROR);}catch(JsonException){$data=null;}
        $tags=is_array($data)?($data['tags']??null):null;
        $validTags=is_array($tags)&&array_is_list($tags)&&count(array_filter($tags,'is_string'))===count($tags);
        $confirmed=$response->status===200&&is_array($data)&&($data['id']??null)===$entryId&&($data['workspace_id']??$data['wid']??null)===$workspace&&$validTags&&TimeEntryStatus::invoiceSources(['tags'=>$tags])!==[];
        $state=$confirmed?'synced':(in_array($response->status,[400,401,402,403,404,409,422,429],true)?'failed':'unknown');
        $stop=in_array($response->status,[0,401,402,403,429],true);
        try{$this->quota->assertAvailable($scope);}catch(TogglException){$stop=true;}
        $this->tagDiagnostic['result']=$state;
        return ['state'=>$state,'stop'=>$stop];
    }

    public function user(): array
    {
        $data = $this->request('/me', 'user');
        if (!is_array($data) || !isset($data['id']) || !is_int($data['id'])) { $this->unexpected(); }
        return ['id' => $data['id'], 'name' => $this->optionalText($data, 'fullname'), 'email' => $this->optionalText($data, 'email')];
    }

    public function workspaces(): array
    {
        return $this->namedList($this->request('/me/workspaces', 'user'), true);
    }

    public function projects(int $workspaceId, ?int $organizationId = null): array
    {
        if ($workspaceId <= 0) { throw new TogglException('Selecteer een geldige workspace.'); }
        $scope = $organizationId !== null ? 'organization:' . $organizationId : 'workspace:' . $workspaceId;
        return $this->namedList($this->request('/workspaces/' . $workspaceId . '/projects', $scope));
    }

    public function userProjects(): array
    {
        return $this->namedList($this->request('/me/projects?include_archived=true', 'user'));
    }

    public function projectCatalog(): array
    {
        $data = $this->request('/me/projects?include_archived=true', 'user');
        $named = $this->namedList($data);
        $result = [];
        $seen = [];
        foreach ($data as $index => $item) {
            $workspace = $item['workspace_id'] ?? $item['wid'] ?? null;
            if (!is_int($workspace) || $workspace <= 0 || !isset($item['active']) || !is_bool($item['active']) || isset($seen[$item['id']])) { $this->unexpected(); }
            if (isset($item['total_count']) && (!is_int($item['total_count']) || ($item['total_count'] > 0 && $item['total_count'] !== count($data)))) {
                throw new TogglException('De Toggl-projectcatalogus is mogelijk onvolledig. De lokale projecten en mappings blijven behouden.');
            }
            $seen[$item['id']] = true;
            $result[] = $named[$index] + ['workspace_id' => $workspace, 'active' => $item['active']];
        }
        return $result;
    }

    /**
     * Read and normalize one UTC interval; splitting and completeness belong to TimeEntryReader.
     * The exclusive end is enforced by the reader against returned start timestamps.
     * @throws TogglException On quota, access, date-range or response-validation failure.
     */
    public function timeEntries(\DateTimeImmutable $start, \DateTimeImmutable $endExclusive): array
    {
        if ($start >= $endExclusive) { throw new TogglException('Ongeldige periode.'); }
        $utc = new \DateTimeZone('UTC');
        $query = http_build_query([
            'start_date' => $start->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            'end_date' => $endExclusive->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
        ], '', '&', PHP_QUERY_RFC3986);
        $data = $this->request('/me/time_entries?' . $query, 'user');
        $result = [];
        foreach ($data as $item) {
            if (!is_array($item) || !isset($item['id'], $item['duration'], $item['start']) || !is_int($item['id']) || $item['id'] <= 0 || !is_int($item['duration'])) { $this->unexpected(); }
            $projectId = $item['project_id'] ?? $item['pid'] ?? null;
            $workspaceId = $item['workspace_id'] ?? $item['wid'] ?? null;
            if (($projectId !== null && (!is_int($projectId) || $projectId <= 0)) || !is_int($workspaceId) || $workspaceId <= 0) { $this->unexpected(); }
            $startTime = $this->timestamp($item['start']);
            $stop = isset($item['stop']) ? $this->timestamp($item['stop']) : null;
            if ($stop !== null && new \DateTimeImmutable($stop) < new \DateTimeImmutable($startTime)) { $this->unexpected(); }
            $tags = $item['tags'] ?? [];
            if (!is_array($tags) || !array_is_list($tags)) { $this->unexpected(); }
            foreach ($tags as $tag) { if (!is_string($tag)) { $this->unexpected(); } }
            $result[] = [
                'id' => $item['id'], 'project_id' => $projectId, 'workspace_id' => $workspaceId,
                'description' => $this->optionalText($item, 'description'),
                'start' => $startTime, 'stop' => $stop, 'duration' => $item['duration'],
                'tags' => array_map(fn ($tag) => $this->safeText($tag), $tags),
            ];
        }
        return $result;
    }

    private function timestamp(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) { $this->unexpected(); }
        try { $date = new \DateTimeImmutable($value); }
        catch (\Exception) { $this->unexpected(); }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) { $this->unexpected(); }
        return $value;
    }

    private function request(string $path, string $scope): mixed
    {
        if (trim($this->token) === '') { throw new TogglException('TOGGL_API_TOKEN ontbreekt. Vul deze uitsluitend in je lokale .env in.'); }
        // Request-local memoization: same endpoint is never requested twice per action.
        if (array_key_exists($path, $this->responses)) { return $this->responses[$path]; }
        $this->quota->assertAvailable($scope);
        $response = $this->http->get($path, $this->token);
        $this->quota->record($scope, $response);
        if (in_array($response->status, [401, 403], true)) {
            throw new TogglException('Toggl-authenticatie of toegang geweigerd. Controleer je API-token en workspace-toegang.');
        }
        if (in_array($response->status, [402, 429], true)) {
            throw new TogglException('Toggl-quota of requestlimiet bereikt. Er worden geen automatische retries uitgevoerd.');
        }
        if ($response->status >= 500) { throw new TogglException('Toggl is tijdelijk niet beschikbaar. Probeer later opnieuw.'); }
        if ($response->status === 400 && str_starts_with($path, '/me/time_entries?')) {
            // Only expose this known, date-only validation error, never arbitrary API text.
            $message = json_decode($response->body, true);
            if (is_string($message) && preg_match('/^start_date must not be earlier than (\d{4}-\d{2}-\d{2})$/D', $message, $match)) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $match[1]);
                if ($date && $date->format('Y-m-d') === $match[1]) {
                    throw new TogglException('Toggl weigert deze periode: de begindatum mag voor dit account niet vóór ' . $date->format('d-m-Y') . ' liggen. Kies een beschikbare periode. Er is geen volledige dataset opgehaald.');
                }
            }
        }
        if ($response->status !== 200) { $this->unexpected(); }
        try {
            $shape = json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
            if ($path === '/me' ? !$shape instanceof \stdClass : !is_array($shape)) { $this->unexpected(); }
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        }
        catch (JsonException) { $this->unexpected(); }
        return $this->responses[$path] = $data;
    }

    private function namedList(mixed $data, bool $workspaces = false): array
    {
        // Toggl returns a JSON array; reject objects, including {}.
        if (!is_array($data) || !array_is_list($data)) { $this->unexpected(); }
        $result = [];
        foreach ($data as $item) {
            if (!is_array($item) || !isset($item['id'], $item['name']) || !is_int($item['id']) || $item['id'] <= 0 || !is_string($item['name'])) { $this->unexpected(); }
            $row = ['id' => $item['id'], 'name' => $this->safeText($item['name'])];
            if ($workspaces) {
                $row['organization_id'] = isset($item['organization_id']) && is_int($item['organization_id']) ? $item['organization_id'] : null;
            }
            $result[] = $row;
        }
        return $result;
    }

    private function optionalText(array $data, string $key): string
    {
        if (!isset($data[$key])) { return ''; }
        if (!is_string($data[$key])) { $this->unexpected(); }
        return $this->safeText($data[$key]);
    }

    private function safeText(string $value): string
    {
        return str_replace([$this->token, base64_encode($this->token . ':api_token')], '[afgeschermd]', $value);
    }

    private function unexpected(): never
    {
        throw new TogglException('Toggl gaf een onverwachte API-response. Probeer later opnieuw.');
    }
}
