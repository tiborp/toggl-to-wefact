<?php use App\View\View; ?>
<?php $diagnostics=json_decode($action['diagnostics_json'] ?? 'null',true); ?>
<?php if(is_array($diagnostics)): ?>
<details><summary>Veilige technische diagnostiek</summary><dl>
<?php foreach(['phase'=>'Fase','phase_result'=>'Afhandeling','http_status'=>'HTTP-status','curl_errno'=>'cURL-code','curl_error'=>'cURL-melding','request_attempted'=>'Transport aangeroepen','request_size_bytes'=>'cURL requestbytes','response_received'=>'Response ontvangen','response_format'=>'Responseformaat','api_status'=>'WeFact status','api_controller'=>'WeFact controller','api_action'=>'WeFact actie','api_code'=>'WeFact code','api_error_code'=>'WeFact foutcode','api_errorcode'=>'WeFact foutcode','exception_class'=>'Exceptiontype']as$key=>$label): ?>
<?php if(array_key_exists($key,$diagnostics)): ?><dt><?= View::escape($label) ?></dt><dd><?= View::escape(is_bool($diagnostics[$key]) ? ($diagnostics[$key] ? 'ja' : 'nee') : (string)$diagnostics[$key]) ?></dd><?php endif; ?>
<?php endforeach; ?>
</dl>
<?php foreach($diagnostics['api_errors']??[]as$error): ?><p><?= View::escape(is_array($error)?implode(' · ',$error):$error) ?></p><?php endforeach; ?>
</details>
<?php else: ?><p>Voor deze oudere actie zijn geen HTTP-status, transportfout of responsegegevens opgeslagen.</p><?php endif; ?>
