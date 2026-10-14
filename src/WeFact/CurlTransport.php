<?php
declare(strict_types=1);
namespace App\WeFact;

/** Sends WeFact JSON over HTTPS/IPv4 and reports transport uncertainty without automatic retries. */
final class CurlTransport implements InvoiceTransport
{
    public function listDebtors(#[\SensitiveParameter] string $key, int $offset, int $limit): HttpResponse
    {
        return $this->request($key, ['controller'=>'debtor','action'=>'list','offset'=>$offset,'limit'=>$limit,'sort'=>'Identifier','order'=>'ASC']);
    }

    /**
     * Send one invoice/add; transport errors cannot establish whether WeFact created the concept.
     */
    public function addInvoice(#[\SensitiveParameter] string $key, array $payload): HttpResponse
    {
        if (($payload['Status'] ?? null) !== 0 || array_key_exists('InvoiceCode',$payload) || array_key_exists('Sent',$payload) || array_key_exists('SentDate',$payload)) { throw new WeFactException('Alleen conceptfacturen zijn toegestaan.', 'payload'); }
        return $this->request($key, ['controller'=>'invoice','action'=>'add'] + $payload);
    }

    private function request(#[\SensitiveParameter] string $key, array $payload): HttpResponse
    {
        $curl=null;
        $phase='serialize';
        try {
        $json=JsonPayload::encode(['api_key'=>$key]+$payload);
        $phase='configure';
        $curl=curl_init('https://api.mijnwefact.nl/v2/');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_POSTFIELDS=>$json]);
        } catch (\Throwable $e) {
            unset($curl);
            throw new WeFactException('De WeFact-request kon lokaal niet worden voorbereid; er is niets verstuurd.','pre_request',false,null,'rejected',
                ['phase'=>$phase,'request_attempted'=>false,'request_size_bytes'=>0,'response_received'=>false,'exception_class'=>get_class($e)]);
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($curl);
        $diagnostics = [
            'phase'=>'transport','request_attempted'=>true,
            'http_status'=>$status,'curl_errno'=>$errno,
            'curl_error'=>Diagnostic::text(curl_error($curl),$key),
            'request_size_bytes'=>(int)curl_getinfo($curl,CURLINFO_REQUEST_SIZE),
            'response_received'=>$body!==false&&$status>0,
            'elapsed_ms'=>(int)(curl_getinfo($curl,CURLINFO_TOTAL_TIME)*1000),
        ];
        unset($curl);
        // No raw cURL errors, credentials, request payloads or response bodies in exceptions/logs.
        if ($body === false || $status === 0) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new WeFactException('WeFact antwoordde niet op tijd. Probeer later opnieuw.', 'timeout', false, null, 'unknown', $diagnostics);
            }
            throw new WeFactException('WeFact is niet bereikbaar. Controleer de internetverbinding en probeer later opnieuw.', 'network', false, null, 'unknown', $diagnostics);
        }
        return new HttpResponse($status, $body, $diagnostics);
    }
}
