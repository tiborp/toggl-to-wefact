<?php
declare(strict_types=1);
namespace App\Invoice;

/** Converts integer time and money to exact API decimals without floating-point truth. */
final class ApiDecimal
{
    public static function hours(int $seconds, bool $up = false): string
    {
        if ($seconds < 0) { throw new InvoiceException('Ongeldige geregistreerde duration.'); }
        $value = bcdiv((string)$seconds, '3600', 12);
        if ($up && bccomp(bcmul($value,'3600',12),(string)$seconds,12) < 0) { $value=bcadd($value,'0.000000000001',12); }
        return $value;
    }
    public static function quarterHours(int $seconds): string
    {
        if($seconds<0||$seconds%900!==0){throw new InvoiceException('Factureerbare regeltijd moet uit hele kwartieren bestaan.');}
        return (string)intdiv($seconds,3600).(['','.25','.5','.75'][intdiv($seconds%3600,900)]);
    }
    public static function euros(int $cents): string { return bcdiv((string)$cents,'100',2); }
    public static function roundedLine(string $number, int $rate): int
    {
        return self::safeInt(bcdiv(bcadd(bcmul($number,(string)$rate,12),'0.5',12),'1',0));
    }
    public static function cents(mixed $amount): int
    {
        if (!is_string($amount) || !preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D',$amount,$match)) { throw new InvoiceException('WeFact-bedrag heeft een onverwacht decimaal formaat.'); }
        $fraction=$match[2]??'';
        if (strlen($fraction)>2 && trim(substr($fraction,2),'0')!=='') { throw new InvoiceException('WeFact-bedrag bevat fracties van centen.'); }
        return self::safeInt(bcmul($amount,'100',0));
    }
    private static function safeInt(string $value): int
    {
        if(bccomp($value,(string)PHP_INT_MAX,0)>0 || bccomp($value,'0',0)<0){throw new InvoiceException('Bedrag buiten veilige integergrenzen.');}
        return (int)$value;
    }
}
