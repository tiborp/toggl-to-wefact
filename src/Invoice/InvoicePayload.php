<?php
declare(strict_types=1);
namespace App\Invoice;

/** Builds draft-only WeFact fields and rejects line amounts the API cannot reproduce. */
final class InvoicePayload
{
    /**
     * Build API fields from the validated local snapshot, not from posted financial values.
     * Largest-remainder cents can differ from separate line rounding at fractional-cent rates.
     * Reject that mismatch before any write rather than silently changing the confirmed amount.
     *
     * @throws InvoiceException If quantities cannot reproduce local line cents or totals.
     */
    public function build(array $model, string $date): array
    {
        $lines=[];
        $total=0;
        foreach($model['lines'] as $line){
            if(!is_int($line['amount_cents'])||$line['amount_cents']<0||$total>PHP_INT_MAX-$line['amount_cents']){throw new InvoiceException('Ongeldig lokaal regelbedrag.');}
            $total+=$line['amount_cents'];
            $number=ApiDecimal::quarterHours($line['billed_duration_seconds']);
            if(ApiDecimal::roundedLine($number,$model['hourly_rate_cents'])!==$line['amount_cents']){throw new InvoiceException('De verdeelde regelcenten zijn niet exact als kwartieruren × uurtarief te factureren. Er is niets aangemaakt. Kies Samengevat.');}
            $description=$model['mode']==='summary'?$line['description']:$model['project_name'].' — '.$line['description'];
            $lines[]=[
                'Number'=>$number,'NumberSuffix'=>' uur','PriceExcl'=>ApiDecimal::euros($model['hourly_rate_cents']),
                'Description'=>$description,
                'PeriodicType'=>'once','StartDate'=>$model['from'],'EndDate'=>$model['to'],
            ];
        }
        if($total!==$model['total_cents']){throw new InvoiceException('Lokale regelbedragen wijken af van het projecttotaal.');}
        if($lines===[]){throw new InvoiceException('Geen factuurregels geselecteerd.');}
        return ['Status'=>0,'Debtor'=>(int)$model['wefact_debtor_id'],'Date'=>$date,'VatCalcMethod'=>'excl','Description'=>$model['project_name'],'InvoiceLines'=>$lines];
    }
}
