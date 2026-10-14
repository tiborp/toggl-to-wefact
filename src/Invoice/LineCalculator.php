<?php
declare(strict_types=1);
namespace App\Invoice;

/** Calculates one project invoice from exact seconds, whole quarters and integer cents. */
final class LineCalculator
{
    /**
     * Calculate after selection: rounding each entry or description independently would overbill.
     * Only the project sum is rounded up; original entry durations remain audit data.
     *
     * @param list<array{id: int, duration: int, description: string}> $entries Already selected, eligible entries.
     * @param int $rate Hourly rate in eurocents.
     * @return array{billing_policy: string, duration_seconds: int, billed_duration_seconds: int, entries: array, total_cents: int, lines: array}
     * @throws PreviewException If the rate/mode is invalid or integer arithmetic would overflow.
     */
    public function calculate(array $entries, int $rate, string $mode, string $projectName): array
    {
        if ($rate <= 0 || !in_array($mode, ['summary', 'description'], true)) { throw new PreviewException('Ongeldig tarief of factuurregelmodus.'); }
        $groups = [];
        $totalSeconds = 0;
        $durationEntries = [];
        foreach ($entries as $entry) {
            $durationEntries[] = $entry;
            $description = trim($entry['description']);
            $key = $mode === 'summary' ? 'summary' : mb_strtolower($description, 'UTF-8');
            $groups[$key] ??= ['description' => $mode === 'summary' ? 'Werkzaamheden ' . $projectName : ($description !== '' ? $description : 'Werkzaamheden'), 'duration_seconds' => 0, 'entry_ids' => [], 'key' => $key];
            $groups[$key]['duration_seconds'] = $this->add($groups[$key]['duration_seconds'], $entry['duration']);
            $totalSeconds = $this->add($totalSeconds, $entry['duration']);
            $groups[$key]['entry_ids'][] = $entry['id'];
        }
        // One ceiling per project keeps the charge independent of entry count or description grouping.
        $billedSeconds=$this->add($totalSeconds, (900-$totalSeconds%900)%900);
        [$floor, $remainder] = $this->exactAmount($billedSeconds, $rate);
        $totalCents = $this->add($floor, $remainder >= 1800 ? 1 : 0); // Half-up, once per project.
        $lines = array_values($groups);
        // Allocate project quarters closest to actual group durations: floor each exact
        // quota, then award remaining quarters by largest seconds remainder (stable ties).
        $quarters=intdiv($billedSeconds,900);$allocated=0;
        foreach($lines as &$line){
            $whole=intdiv($line['duration_seconds'],900);
            $line['billed_duration_seconds']=$whole*900;
            $line['time_remainder']=$line['duration_seconds']%900;
            $allocated+=$whole;
        }unset($line);
        $timeOrder=array_keys($lines);
        usort($timeOrder,static fn($a,$b)=>$lines[$b]['time_remainder']<=>$lines[$a]['time_remainder'] ?: strcmp($lines[$a]['key'],$lines[$b]['key']));
        $extraQuarters=$quarters-$allocated;
        foreach($timeOrder as$index){if($extraQuarters--<=0){break;}$lines[$index]['billed_duration_seconds']+=900;}
        $floors = 0;
        foreach ($lines as &$line) {
            [$line['amount_cents'], $line['remainder']] = $this->exactAmount($line['billed_duration_seconds'], $rate);
            $line['hourly_rate_cents'] = $rate;
            $floors = $this->add($floors, $line['amount_cents']);
        }
        unset($line);
        // Share fractional cents without changing the project total. InvoicePayload separately
        // checks whether WeFact can reproduce these cents with hours × the unchanged rate.
        $order = array_keys($lines);
        usort($order, static fn ($a, $b) => $lines[$b]['remainder'] <=> $lines[$a]['remainder'] ?: strcmp($lines[$a]['key'], $lines[$b]['key']));
        $extra = $totalCents - $floors;
        foreach ($order as $index) { if ($extra-- <= 0) { break; } $lines[$index]['amount_cents']++; }
        foreach ($lines as &$line) { unset($line['remainder'], $line['time_remainder'], $line['key']); }
        unset($line);
        return ['billing_policy'=>'project_quarter_hours_v2', 'duration_seconds' => $totalSeconds, 'billed_duration_seconds'=>$billedSeconds, 'entries' => $durationEntries, 'total_cents' => $totalCents, 'lines' => $lines];
    }

    /**
     * Return whole cents and a remainder over 3600; postpone rounding until allocation.
     * @return array{0: int, 1: int}
     */
    private function exactAmount(int $seconds, int $rate): array
    {
        if ($seconds < 0) { throw new PreviewException('Ongeldige duration voor preview.'); }
        $hours = intdiv($seconds, 3600);
        $fraction = $seconds % 3600;
        if ($hours > intdiv(PHP_INT_MAX, $rate) || ($fraction > 0 && $rate > intdiv(PHP_INT_MAX, $fraction))) {
            throw new PreviewException('Bedrag te groot om veilig als integer eurocenten te berekenen.');
        }
        $product = $fraction * $rate;
        return [$this->add($hours * $rate, intdiv($product, 3600)), $product % 3600];
    }

    private function add(int $a, int $b): int
    {
        if ($b < 0 || $a > PHP_INT_MAX - $b) { throw new PreviewException('Totaal te groot voor een veilige berekening.'); }
        return $a + $b;
    }
}
