<?php
declare(strict_types=1);
namespace App\WeFact;

/** Extracts bounded, credential-redacted invoice diagnostics for local troubleshooting. */
final class Diagnostic
{
    public static function text(string $value, #[\SensitiveParameter] string $key): string
    {
        foreach (array_filter([$key,base64_encode($key),rawurlencode($key),urlencode($key)]) as $secret) {
            $value=str_replace($secret,'[afgeschermd]',$value);
        }
        $value=preg_replace('/(?:Basic|Bearer)\s+[A-Za-z0-9+\/=_\-.]+/i','[authorization afgeschermd]',$value);
        $value=preg_replace('~["\']?(?:api[_ -]?key|access[_ -]?token|refresh[_ -]?token|token|authorization|password|client[_ -]?secret|secret)["\']?\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)~i','[credential afgeschermd]',$value);
        return mb_substr($value,0,1200);
    }
    public static function response(HttpResponse $response, mixed $data, #[\SensitiveParameter] string $key): array
    {
        $diag=[];
        foreach(['phase','request_attempted','response_received','http_status','curl_errno','curl_error','request_size_bytes','elapsed_ms']as$field){
            if(isset($response->diagnostics[$field])&&is_scalar($response->diagnostics[$field])){
                $value=$response->diagnostics[$field];$diag[$field]=is_string($value)?self::text($value,$key):$value;
            }
        }
        $diag+=['request_attempted'=>true,'response_received'=>true,'http_status'=>$response->status];
        $diag['response_bytes']=strlen($response->body);
        $diag['response_sha256']=hash('sha256',$response->body);
        $diag['response_format']=is_array($data)?'json':'non_json';
        if(is_array($data)){
            foreach(['controller','action','status','code','errorcode','error_code']as$field){
                if(isset($data[$field])&&is_scalar($data[$field])){$diag['api_'.$field]=self::text((string)$data[$field],$key);}
            }
            $errors=$data['errors']??[];
            if(is_string($errors)){$errors=[$errors];}
            $diag['api_errors']=[];
            if(is_array($errors)){
                foreach(array_slice($errors,0,10)as$error){
                    if(is_string($error)){$diag['api_errors'][]=self::text($error,$key);}
                    elseif(is_array($error)){
                        $safe=[];
                        foreach(['code','message','field']as$f){if(isset($error[$f])&&is_scalar($error[$f])){$safe[$f]=self::text((string)$error[$f],$key);}}
                        if($safe!==[]){$diag['api_errors'][]=$safe;}
                    }
                }
            }
        }
        return $diag;
    }
    public static function rejectionMessage(array $diag): string
    {
        $parts=[];
        foreach($diag['api_errors']??[]as$error){
            $parts[]=is_string($error)?$error:implode(' · ',$error);
        }
        return 'WeFact heeft invoice/add expliciet afgewezen; er is geen factuur bevestigd/aangemaakt. '.($parts!==[]?implode(' | ',$parts):'Geen fouttekst meegestuurd.');
    }
}
