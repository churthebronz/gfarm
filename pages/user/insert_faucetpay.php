<?php if(!defined('FastCore')){exit('Oops!');}

$opt['title'] = 'Make a deposit';

$username = $user['login'];
?>
<h3 class="text-center">MAKE A DEPOSIT METHOD - <span class="text-success">FAUCETPAY</span></h3>
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

# Оплата через FaucetPay.IO
$csrfCheck = $func->csrfVerify();
if (isset($_POST['sum']) && $py == 'faucetpay' && $csrfCheck == TRUE) {

$sum = round(floatval($_POST["sum"]),2);
$sys = 'Faucetpay';
$sum_x = '0';

if ($sum > $minDep-0.01) {
# Заносим в БД
$db->query("INSERT INTO db_insert (uid, login, sum, sum_x, sys, `add`, status) VALUES ('$uid','$username','$sum','$sum_x','$sys','".time()."','0')");
 
$m_orderid = $db->LastInsert();
$m_amount = number_format($sum, 2, ".", ""); 
 
$setURI = 'https://faucetpay.io/merchant/webscr';
$m_username = $config->fp_username;
$m_desc = urlencode('insert user: '.$uid.' - '.$login);

$m_callback_url = urlencode("https://".$_SERVER['HTTP_HOST']."/faucetpay.php");
$m_success_url = urlencode("https://".$_SERVER['HTTP_HOST']."/user/success");
$m_cancel_url = urlencode("https://".$_SERVER['HTTP_HOST']."/user/fail");

$location = $setURI."?merchant_username=$m_username&item_description=$m_desc&amount1=$m_amount&currency1=$currencyPS&custom=$m_orderid&callback_url=$m_callback_url&success_url=$m_success_url&cancel_url=$m_cancel_url&completed=0";

// Перенаправление на страницу FaucetPay
header("Location: $location");
 
}
 else {
	echo '<div class="alert alert-danger h5 p-3 text-center"><b>ERROR AMOUNT</b><br/>Pay method <span class="text-uppercase">'.$py.'</span> <br/> For this system, the minimum deposit amount is <b>'.$minDep.' usd.</b></div>';
}
	return;
}

if ($py == 'faucetpay') {

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
