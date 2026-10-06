<?php
declare(strict_types=1);
$root=dirname(__DIR__, 2).'/packages';
if (!is_dir($root)) { throw new RuntimeException('Run inside a fully patched INTL Platform root.'); }
$names=[];
foreach (glob($root.'/*/composer.json') as $json) {
 $data=json_decode(file_get_contents($json),true,flags:JSON_THROW_ON_ERROR);
 foreach (($data['autoload']['psr-4']??[]) as $prefix=>$dir) $names[$prefix]=dirname($json).'/'.$dir;
}
spl_autoload_register(static function(string $name) use ($names):void {
 foreach ($names as $prefix=>$dir) if (str_starts_with($name,$prefix)) {
  $file=rtrim($dir,'/').'/'.str_replace('\\','/',substr($name,strlen($prefix))).'.php';
  if(is_file($file))require_once $file;
  return;
 }
});
$checks=[
 ['Intl\\BillingLaravel\\Services\\PaymentService','dispatch',['paymentId','confirmedAmount','confirmedCurrency','expectedRevision']],
 ['Intl\\BillingLaravel\\Services\\PaymentService','retryFailed',['paymentId','retryKey']],
 ['Intl\\BillingLaravel\\Services\\PaymentService','attemptHistory',['paymentId']],
 ['Intl\\ServiceRequestsLaravel\\Services\\ServiceRequests','createPaymentIntent',['id','paymentIdempotencyKey']],
 ['Intl\\ServiceRequestsLaravel\\Services\\ServiceRequests','retryFailedPayment',['requestId','paymentId','retryKey']],
 ['Intl\\ServiceRequestsLaravel\\Services\\RequestLifecycle','requireCorrection',['id','reason','fields','slots']],
 ['Intl\\ServiceRequestsLaravel\\Services\\RequestLifecycle','resubmit',['id']],
 ['Intl\\ServiceRequestsLaravel\\Services\\RequestLifecycle','requestCancellation',['id','reason']],
 ['Intl\\ServiceRequestsLaravel\\Services\\RequestLifecycle','decideCancellation',['id','approve','reason']],
 ['Intl\\ServiceRequestsLaravel\\Services\\RequestLifecycle','withdrawCancellation',['id']],
 ['Intl\\ServiceCatalogLaravel\\Services\\ServiceCatalog','replace',['code','expectedRevision','draft']],
 ['Intl\\BillingLaravel\\Contracts\\TrustedRefundPolicyProvider','forPayment',['serviceCode','resourceType','resourceId','agencyId']],
 ['Intl\\ServiceRequestsLaravel\\Contracts\\RequestAudit','record',['event','requestId','safeMetadata']],
 ['Intl\\ServiceRequestsLaravel\\Contracts\\RequestSubmissionVerifier','assertReady',['serviceCode','requestId']],
];
foreach($checks as [$class,$method,$expected]) {
 $reflection=new ReflectionMethod($class,$method);
 $actual=array_map(static fn($p)=>$p->name, $reflection->getParameters());
 if($actual!==$expected) throw new RuntimeException("API drift in $class::$method expected=".json_encode($expected)." actual=".json_encode($actual));
}
$policy=\Intl\ServiceRequests\Policies\RequestPolicy::fromArray([
 'draft_state'=>'draft', 'submit_actions'=>['submit','resubmit'],
 'submitted_states'=>['submitted','correction_required'],'terminal_states'=>['in_issuance','cancelled'],
 'cancelled_state'=>'cancelled','cancellable_states'=>['submitted','correction_required'],
 'correction_states'=>['correction_required'],'correction_mode'=>'limited',
 'correction_action'=>'require_correction','resubmit_action'=>'resubmit',
 'cancel_draft_action'=>'cancel_draft','cancel_actions_by_state'=>[
  'submitted'=>'cancel_submitted','correction_required'=>'cancel_correction'],
 'recipient_field_map'=>['full_name'=>'name'],
]);
if($policy->correctionMode!=='limited')throw new RuntimeException('Request policy rejected fixture');
$refund=\Intl\Billing\Refund\RefundPolicy::fromArray(['cancellation_approved'=>'admin_approval']);
if($refund->modeFor('cancellation_approved')!==\Intl\Billing\Refund\RefundInitiation::AdminApproval)throw new RuntimeException('Refund policy rejected fixture');
echo "PASS: ".count($checks)." API signatures and 2 current policy fixtures\n";
