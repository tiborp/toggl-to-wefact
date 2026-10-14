<?php
declare(strict_types=1);
namespace App\Toggl;

/** Redacts credentials before retaining bounded tag-response diagnostics and allowlisted headers. */
final class TagDiagnostic
{
    public const BODY_LIMIT=8192;
    public static function redact(string $text,#[\SensitiveParameter] string $token): string
    {
        $json=json_decode($text,true);
        if(is_array($json)){
            $walk=static function($value)use(&$walk,$token){
                if(!is_array($value)){return is_string($value)?self::plain($value,$token):$value;}
                $safe=[];foreach($value as$key=>$item){
                    $credential=is_string($key)&&preg_match('/^(?:api[_ -]?(?:key|token)|access[_ -]?token|refresh[_ -]?token|token|authorization|password|client[_ -]?secret|secret)$/i',$key);
                    $safe[$key]=$credential?'[afgeschermd]':$walk($item);
                }return $safe;
            };
            return json_encode($walk($json),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return self::plain($text,$token);
    }
    private static function plain(string $text,#[\SensitiveParameter] string $token): string
    {
        foreach(array_filter([$token,base64_encode($token.':api_token'),base64_encode($token),rawurlencode($token),urlencode($token)])as$secret){$text=str_replace($secret,'[afgeschermd]',$text);}
        $text=preg_replace('~(?:Basic|Bearer)\s+[A-Za-z0-9+/=_\-.]+~i','[authorization afgeschermd]',$text);
        $text=preg_replace('~["\']?(?:api[_ -]?key|api[_ -]?token|access[_ -]?token|refresh[_ -]?token|token|authorization|password|client[_ -]?secret|secret)["\']?\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;}]+)~i','[credential afgeschermd]',$text);
        // Redact URL userinfo too; endpoint itself is always generated from numeric IDs.
        return preg_replace('~(https?://)[^\s/@]+:[^\s/@]+@~i','$1[credentials afgeschermd]@',$text);
    }
    public static function response(HttpResponse $response,#[\SensitiveParameter] string $token): array
    {
        $safe=self::redact($response->body,$token);$headers=[];
        foreach(array_change_key_case($response->headers,CASE_LOWER)as$name=>$value){
            if(in_array($name,['x-toggl-quota-remaining','x-toggl-quota-resets-in','retry-after','content-type','x-request-id'],true)&&is_scalar($value)){$headers[$name]=mb_substr(self::redact((string)$value,$token),0,256);}
        }
        return ['http_status'=>$response->status,'response_body'=>mb_substr($safe,0,self::BODY_LIMIT),'body_truncated'=>mb_strlen($safe)>self::BODY_LIMIT,'response_bytes'=>strlen($response->body),'response_headers'=>$headers,'response_received'=>$response->status>0,'curl_errno'=>isset($response->diagnostics['curl_errno'])?(int)$response->diagnostics['curl_errno']:null];
    }
}
