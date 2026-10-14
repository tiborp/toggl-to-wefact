<?php
declare(strict_types=1);
namespace App\Toggl;

/** Performs allowlisted Toggl requests with bounded timeouts and additive tag updates. */
final class CurlTransport implements TagTransport
{
    public const INVOICED_UPDATE=['tags'=>['invoiced'],'tag_action'=>'add'];
    private float $lastRequest = 0;

    public function get(string $path, #[\SensitiveParameter] string $token): HttpResponse
    {
        // Fixed HTTPS host and allowlisted GET routes; additive tag PUT is separate.
        $route = parse_url($path, PHP_URL_PATH);
        $query = parse_url($path, PHP_URL_QUERY);
        $valid = preg_match('~^/(me|me/workspaces|workspaces/[1-9][0-9]*/projects)$~D', $route ?? '') && $query === null;
        if ($route === '/me/projects' && $query === 'include_archived=true') { $valid = true; }
        if ($route === '/me/time_entries' && $query !== null) {
            // Only the two client-generated UTC date parameters may leave the app.
            $valid = (bool) preg_match('~^start_date=[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}%3A[0-9]{2}%3A[0-9]{2}Z&end_date=[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}%3A[0-9]{2}%3A[0-9]{2}Z$~D', $query);
        }
        if (!$valid) {
            throw new TogglException('Ongeldig Toggl-endpoint.');
        }
        return $this->request($path,$token);
    }

    public static function invoicedEndpoint(int $workspace,int $entryId): string
    {
        if($workspace<=0||$entryId<=0){throw new TogglException('Ongeldige tag-entry.');}
        return '/workspaces/'.$workspace.'/time_entries/'.$entryId;
    }
    public function addInvoiced(int $workspace,int $entryId, #[\SensitiveParameter] string $token): HttpResponse
    {
        return $this->request(self::invoicedEndpoint($workspace,$entryId),$token,self::INVOICED_UPDATE);
    }

    private function request(string $path, #[\SensitiveParameter] string $token,?array $update=null): HttpResponse
    {
        $wait = 1.1 - (microtime(true) - $this->lastRequest);
        if ($wait > 0) { usleep((int) ($wait * 1000000)); }
        $headers = [];
        $curl = curl_init('https://api.track.toggl.com/api/v9' . $path);
        curl_setopt_array($curl, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $token . ':api_token',
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                if (str_starts_with($line, 'HTTP/')) { $headers = []; }
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $name = strtolower(trim($name));
                    if (in_array($name, ['x-toggl-quota-remaining', 'x-toggl-quota-resets-in', 'retry-after','content-type','x-request-id'], true)) {
                        $headers[$name] = trim($value);
                    }
                }
                return strlen($line);
            },
        ]);
        if($update!==null){curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_POSTFIELDS=>json_encode($update,JSON_THROW_ON_ERROR)]);}
        $this->lastRequest = microtime(true);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno=curl_errno($curl);
        unset($curl);
        if($update!==null){return new HttpResponse($status,$body===false?'':$body,$headers,['curl_errno'=>$errno]);}
        if ($body === false || $status === 0) {
            throw new TogglException('Toggl is niet bereikbaar. Controleer je internetverbinding en probeer later opnieuw.');
        }
        return new HttpResponse($status, $body, $headers);
    }
}
