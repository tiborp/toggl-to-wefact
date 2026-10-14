<?php
declare(strict_types=1);
namespace App\WeFact;

use JsonException;

/** Validates debtor pagination and classifies draft creation as confirmed, rejected or uncertain. */
final class WeFactClient
{
    public const PAGE_SIZE = 1000;
    private const MAX_PAGES = 100;
    private int $requests = 0;
    private ?bool $connected = null;
    private ?bool $authenticated = null;

    public function __construct(#[\SensitiveParameter] private readonly string $key, private readonly HttpTransport $http) {}

    public function status(): array
    {
        return ['connected' => $this->connected, 'authenticated' => $this->authenticated, 'requests' => $this->requests];
    }

    /**
     * Fetch all pages before returning a cache-replacement candidate; never return a partial list.
     * @throws WeFactException On access, response or pagination errors.
     */
    public function debtors(): array
    {
        if (trim($this->key) === '') {
            throw new WeFactException('WEFACT_API_KEY ontbreekt. Vul deze uitsluitend in je lokale .env in.', 'missing_key');
        }
        $offset = 0;
        $total = null;
        $all = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $this->requests++;
            try { $response = $this->http->listDebtors($this->key, $offset, self::PAGE_SIZE); }
            catch (WeFactException $error) {
                $this->connected = $error->connected;
                throw $error;
            }
            $this->connected = true;
            try { $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR); }
            catch (JsonException) {
                if (in_array($response->status, [401, 403], true)) { $this->denied([]); }
                throw new WeFactException('WeFact gaf geen geldige JSON-response. De bestaande cache blijft behouden.', 'json', true, $this->authenticated);
            }
            if (!is_array($data)) { $this->unexpected(); }
            if (($data['status'] ?? null) === 'error' || in_array($response->status, [401, 403], true)) { $this->denied($data); }
            if ($response->status !== 200) {
                throw new WeFactException('WeFact gaf een HTTP-fout. Probeer later opnieuw; de bestaande cache blijft behouden.', 'http', true, $this->authenticated);
            }
            if (($data['status'] ?? null) !== 'success' || ($data['controller'] ?? null) !== 'debtor' || ($data['action'] ?? null) !== 'list') { $this->unexpected(); }
            $this->authenticated = true;
            $pageTotal = $this->number($data['totalresults'] ?? null);
            $count = $this->number($data['currentresults'] ?? null);
            $actualOffset = $this->number($data['offset'] ?? null);
            $rows = $data['debtors'] ?? null;
            if (!is_array($rows) || !array_is_list($rows) || $count !== count($rows) || $actualOffset !== $offset || $count > self::PAGE_SIZE || $offset + $count > $pageTotal) { $this->unexpected(); }
            if ($total !== null && $total !== $pageTotal) {
                throw new WeFactException('De debiteurenlijst veranderde tijdens pagination. Vernieuw later opnieuw; de bestaande cache blijft behouden.', 'pagination', true, true);
            }
            $total = $pageTotal;
            foreach ($rows as $row) {
                $debtor = $this->debtor($row);
                $id = $debtor['wefact_debtor_id'];
                if (isset($all[$id])) {
                    throw new WeFactException('WeFact leverde dubbele debiteur-IDs tijdens pagination. De bestaande cache blijft behouden.', 'pagination', true, true);
                }
                $all[$id] = $debtor;
            }
            $offset += $count;
            if ($offset === $total) { return array_values($all); }
            if ($count === 0) {
                throw new WeFactException('WeFact leverde een onvolledige pagina. De bestaande cache blijft behouden.', 'pagination', true, true);
            }
        }
        throw new WeFactException('De veilige paginalimiet is bereikt. De refresh is onvolledig; de bestaande cache blijft behouden.', 'pagination', true, true);
    }

    private array $invoiceDiagnostics = [];
    public function invoiceDiagnostics(): array { return $this->invoiceDiagnostics; }

    /**
     * Attempt one draft creation and validate its response envelope without retrying.
     * Only a matching explicit error without invoice evidence is a definite rejection.
     * A timeout, malformed success or ambiguous envelope remains unknown to preserve duplicate guards.
     *
     * @param array $payload Draft fields without api_key; credentials are injected by the transport.
     * @return array{id: string, code: string, status: int, debtor: string, amount_cents: int, sent: int, currency: mixed}
     * @throws WeFactException With outcome rejected or unknown; callers must not blindly retry.
     */
    public function createDraft(array $payload): array
    {
        $this->invoiceDiagnostics=['phase'=>'pre_request','request_attempted'=>false,'response_received'=>false];
        if(trim($this->key)===''){throw new WeFactException('WEFACT_API_KEY ontbreekt.','missing_key',null,null,'rejected',$this->invoiceDiagnostics);}
        if(!$this->http instanceof InvoiceTransport || ($payload['Status']??null)!==0 || array_key_exists('InvoiceCode',$payload) || array_key_exists('SentDate',$payload) || array_key_exists('Sent',$payload)){
            throw new WeFactException('Ongeldige conceptfactuuractie; request niet verstuurd.','payload',null,null,'rejected',$this->invoiceDiagnostics);
        }
        $this->requests++;
        $this->invoiceDiagnostics=['phase'=>'dispatch','request_attempted'=>true,'response_received'=>false];
        try{$response=$this->http->addInvoice($this->key,$payload);}
        catch(WeFactException $e){
            $this->invoiceDiagnostics=$e->diagnostics+$this->invoiceDiagnostics;
            $message=$e->outcome==='unknown'?'WeFact-write heeft geen betrouwbaar bevestigd resultaat. Handmatige controle vereist; niet opnieuw aanmaken.':$e->getMessage();
            throw new WeFactException($message,$e->kind,$e->connected,$e->authenticated,$e->outcome,$this->invoiceDiagnostics);
        }
        catch(\Throwable $e){
            $this->invoiceDiagnostics['exception_class']=get_class($e);
            throw new WeFactException('Transport gaf geen betrouwbaar resultaat; onbekend of een factuur bestaat.','transport',null,null,'unknown',$this->invoiceDiagnostics);
        }
        // Preserve the API decimal text before JSON decoding can turn it into a PHP float.
        $body=preg_replace('/"AmountExcl"\s*:\s*(-?[0-9]+(?:\.[0-9]+)?)(?=\s*[,}])/', '"AmountExcl":"$1"', $response->body);
        try{$data=json_decode($body,true,512,JSON_THROW_ON_ERROR);}
        catch(JsonException){
            $this->invoiceDiagnostics=Diagnostic::response($response,null,$this->key);
            $this->invoiceDiagnostics['phase']='parse';
            throw new WeFactException('WeFact-response ontvangen maar JSON onleesbaar. Handmatige controle vereist.','json',true,null,'unknown',$this->invoiceDiagnostics);
        }
        $this->invoiceDiagnostics=Diagnostic::response($response,$data,$this->key);
        $this->invoiceDiagnostics['phase']='validate_response';
        // Only a complete, context-matching error envelope without created/partial invoice evidence is a definite rejection.
        $expectedContext=is_array($data)&&($data['controller']??null)==='invoice'&&($data['action']??null)==='add';
        $authContext=is_array($data)&&($data['controller']??null)==='invalid'&&($data['action']??null)==='invalid'&&in_array($response->status,[401,403],true);
        $invoiceEvidence=is_array($data)&&(!empty($data['invoice'])||!empty($data['success'])||isset($data['Identifier'])||isset($data['InvoiceCode']));
        if(is_array($data)&&($data['status']??null)==='error'&&($expectedContext||$authContext)&&!$invoiceEvidence&&in_array($response->status,[200,400,401,403,422],true)){
            throw new WeFactException(Diagnostic::rejectionMessage($this->invoiceDiagnostics),'rejected',true,false,'rejected',$this->invoiceDiagnostics);
        }
        if(!is_array($data)||$response->status!==200||($data['status']??null)!=='success'||!$expectedContext){
            throw new WeFactException('WeFact-response ontvangen maar geen eenduidig invoice/add-resultaat. Handmatige controle vereist.','invoice',true,null,'unknown',$this->invoiceDiagnostics);
        }
        try{
            $invoice=$data['invoice']??null;
            if(!is_array($invoice)){throw new \UnexpectedValueException();}
            $id=$this->number($invoice['Identifier']??null);
            $status=$this->number($invoice['Status']??null);
            $debtor=$this->number($invoice['Debtor']??null);
            $code=$this->text($invoice,'InvoiceCode');
            if($id<=0||$debtor<=0||$code===''||str_contains($code,'[afgeschermd]')){throw new \UnexpectedValueException();}
            return ['id'=>(string)$id,'code'=>$code,'status'=>$status,'debtor'=>(string)$debtor,
                'amount_cents'=>\App\Invoice\ApiDecimal::cents($invoice['AmountExcl']??null),'sent'=>$this->number($invoice['Sent']??0),'currency'=>$invoice['Currency']??'EUR'];
        }catch(\Throwable $e){
            $this->invoiceDiagnostics['exception_class']=get_class($e);
            throw new WeFactException('WeFact meldde succes, maar factuurvelden konden niet veilig worden gevalideerd. Handmatige controle vereist.','response_validation',true,true,'unknown',$this->invoiceDiagnostics);
        }
    }

    private function debtor(mixed $row): array
    {
        if (!is_array($row)) { $this->unexpected(); }
        $id = $this->number($row['Identifier'] ?? null);
        if ($id <= 0 || !isset($row['DebtorCode']) || !is_string($row['DebtorCode']) || $row['DebtorCode'] === '') { $this->unexpected(); }
        $company = $this->text($row, 'CompanyName');
        $first = $this->text($row, 'Initials');
        $last = $this->text($row, 'SurName');
        $number = $this->text($row, 'DebtorCode');
        return [
            'wefact_debtor_id' => (string) $id, 'debtor_number' => $number,
            'display_name' => trim($company) ?: (trim($first . ' ' . $last) ?: $number),
            'company_name' => $company, 'first_name' => $first, 'last_name' => $last,
            'city' => $this->text($row, 'City'), 'email' => $this->text($row, 'EmailAddress'),
        ];
    }

    private function text(array $row, string $field): string
    {
        $value = $row[$field] ?? '';
        if (!is_string($value)) { $this->unexpected(); }
        return str_replace([$this->key, base64_encode($this->key)], '[afgeschermd]', $value);
    }

    private function number(mixed $value): int
    {
        if (is_int($value) && $value >= 0) { return $value; }
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,17})$/D', $value)) { return (int) $value; }
        $this->unexpected();
    }

    private function denied(array $data): never
    {
        // Inspect messages for classification only; never display/store their raw contents.
        $errors = $data['errors'] ?? [];
        $text = is_array($errors) ? implode(' ', array_filter($errors, 'is_string')) : (is_string($errors) ? $errors : '');
        $text = strtolower(str_replace($this->key, '', $text));
        if (preg_match('/\bip\b|whitelist|white.?list|firewall|ip.adres/', $text)) {
            throw new WeFactException('WeFact heeft het verzoek geweigerd. Controleer of het publieke IPv4-adres van deze computer/internetverbinding is toegestaan in de WeFact API-instellingen. Bij een firewallblokkade: neem contact op met WeFact.', 'ip', true);
        }
        if (preg_match('/api.?key|api.?sleutel|beveiligingscode|authentication|authenticatie|security.?code/', $text)) {
            $this->authenticated = false;
            throw new WeFactException('WeFact-authenticatie mislukt. Controleer WEFACT_API_KEY in je lokale .env.', 'authentication', true, false);
        }
        if (($data['status'] ?? null) === 'error' && ($data['controller'] ?? '') !== 'invalid') {
            throw new WeFactException('WeFact meldde een API-fout. De bestaande cache blijft behouden. Controleer de API-instellingen en probeer later opnieuw.', 'api', true, $this->authenticated);
        }
        throw new WeFactException('WeFact heeft toegang geweigerd. Controleer de API-key en of je publieke IPv4-adres is toegestaan in de WeFact API-instellingen.', 'access', true);
    }

    private function unexpected(): never
    {
        throw new WeFactException('WeFact gaf een onverwachte response of ongeldige pagination. De bestaande cache blijft behouden.', 'response', $this->connected, $this->authenticated);
    }
}
