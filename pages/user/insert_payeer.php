<?php if(!defined('FastCore')){exit('Oops!');}

$opt['title'] = 'Make a deposit';

$username = $user['login'];
?>
<h3 class="text-center">MAKE A DEPOSIT METHOD - <span class="text-success">PAYEER</span></h3>
<?php

# Способ платежа
$py = $pg->segment[2] ?? NULL;

# Выбран способ оплаты
if ($py) {

$selectPS = mb_strtoupper($py);
$systemPay = $db->query('SELECT * FROM db_paysystem WHERE name = ? LIMIT 1',$selectPS)->fetchArray();
$minDep = $systemPay['mindep'];
$currencyPS = $systemPay['currency'];
$psTitle = $systemPay['title'];

$opt['title'] = 'Deposit method '.$psTitle.'';

# Оплата через Payeer
$csrfCheck = $func->csrfVerify();
if (isset($_POST['sum']) && $py == 'payeer' && $csrfCheck == TRUE) {

$sum = round(floatval($_POST["sum"]),2);
$sys = 'Payeer';
$sum_x = '0';

if ($sum > $minDep-0.01) {
# Заносим в БД
$db->query("INSERT INTO db_insert (uid, login, sum, sum_x, sys, `add`, status) VALUES ('$uid','$username','$sum','$sum_x','$sys','".time()."','0')");

$desc = base64_encode($_SERVER["HTTP_HOST"]." - USER ".$login);
$m_shop = $config->py_shop;
$m_orderid = $db->LastInsert();
$m_amount = number_format($sum, 2, ".", "");
$m_curr = "USD";
$m_desc = $desc;
$m_key = $config->py_secret;

$arHash = array(
 $m_shop,
 $m_orderid,
 $m_amount,
 $m_curr,
 $m_desc,
 $m_key
);
$sign = strtoupper(hash('sha256', implode(":", $arHash)));
?>
<center>
<div class="col-lg-6">
<div class="card mt-3">
<center class="card-header alert-primary"><div class="col-6 p-2"> <img loading="lazy" decoding="async" src="/img/pay/icon/usd.png"> </div></center>
<div class="p-2 pt-4 pb-4">
<div class="card-title mb-0">Now you will be taken to the page for further payment.</div>
<p class="mb-3">After payment, funds will be credited to the game balance.</p>
<form method="GET" action="//payeer.com/api/merchant/m.php">
	<input type="hidden" name="m_shop" value="<?=$config->py_shop; ?>">
	<input type="hidden" name="m_orderid" value="<?=$m_orderid; ?>">
	<input type="hidden" name="m_amount" value="<?=number_format($sum, 2, ".", "")?>">
	<input type="hidden" name="m_curr" value="USD">
	<input type="hidden" name="m_desc" value="<?=$desc; ?>">
	<input type="hidden" name="m_sign" value="<?=$sign; ?>">
	<input type="submit" name="m_process" value="Pay method Payeer" class="btn btn-lg btn-success text-uppercase">
</form>
</div>
</div>
</div><br/><br/><br/>
</center>

<?php
}
 else {
	echo '<div class="alert alert-danger h5 p-3 text-center"><b>ERROR AMOUNT</b><br/>Pay method <span class="text-uppercase">'.$py.'</span> <br/> For this system, the minimum deposit amount is <b>'.$minDep.' usd.</b></div>';
}
	return;
}

if ($py == 'payeer') {

?>

<div class="row text-center">
<div class="col-lg-3"></div>
<div class="col-lg-6">
<div class="card mt-3">

<div class="card-body">

<form action="" method="post">
<?php $func->csrf(); ?>
    <div class="text-left text-uppercase"><b>Deposit amount:</b></div>
<div class="input-group input-group-lg mb-2">
	<span class="input-group-text"><i class="fa fa-dollar-sign"></i></span>
	<input type="text" class="form-control" value="1" min="<?=$minDep;?>" max="1000" name="sum" onkeyup="generateThis();" id="getsum" />
</div>

<p> </p>

	<input type="submit" value="Go to payment" class="btn btn-lg btn-success text-uppercase"/>
</form>
</div>
</div><br>
</div>


</div>
<?php
return;
} else {
	echo '<div class="alert alert-warning"><b>ERROR:</b> the payment system is not defined!</div>';
}
return;
}
?>
