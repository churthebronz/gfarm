<?php if(!defined('FastCore')){exit('Opss!');}
# Заголовок
$opt['title'] = 'Bonuses';

$username = $user['login'];
# Бонус выдача за депозит
$dep = round($user['sum_in']);
if ($dep > 4.99) {
	$sumDep = round($dep/20,2);
} else {
	$sumDep = '0.1';
}

# Бонус выдача за доход рефералов
$refin = round($user['income']);
if ($refin > 0.99) {
	$sumRef = round($refin/20,2);
} else {
	$sumRef = '0';
}

$ddel = time() + 60*60*24;
$dadd = time();
?>
<div class="center-title mt-3">
<h2>Daily bonus</h2>
</div>

<div class="row row-cols-1 mx-0">
<div class="col col-lg-6 p-2">
<div class="card h-100 shadow-sm mb-2" style="overflow: hidden;">
<div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/a12.png" style="max-width: 64px;"></div>
<h4 class="p-2 text-center mb-0 pb-0 text-success">FREE BONUS 5%</h4>
<h6 class="p-1 text-center mb-0 text-uppercase">Daily for deposit bonus</h6>
<div class="p-1 text-center h-100"><small>Get a bonus of <span class="text-danger"><b>5%</b></span> of your account deposits every 24 hours. <br>
The more funds you have deposited or promoted, the higher the daily bonus. 
</small></div>


<?PHP

$hide = false;
$true = $db->query("SELECT * FROM `db_bonus` WHERE `uid` = '$uid' AND `del` > '$dadd'")->numRows();
if($true == 0){

# Выдача бонуса
if(isset($_POST["bonus"])){

	# Зачилсяем бонус
	$db->query("UPDATE db_users SET `money_b` = `money_b` + '$sumDep', `earn_bonus` = `earn_bonus` + '$sumDep' WHERE `id` = '$uid'");
	# Вносим запись в список бонусов
	$db->query('INSERT INTO db_bonus (`login`, `uid`, `sum`, `add`, `del`) VALUES (?,?,?,?,?)', array($username, $uid, $sumDep, $dadd, $ddel));
	# Случайная очистка устаревших записей
	$db->query("DELETE FROM db_bonus WHERE `del` < '$dadd'");
	echo '<div class="alert alert-success">You have received a bonus balance: <b>'.$sumDep.' {!VAL!}</b>.</div>';
	$hide = true;
}

# Скрыть кнопку
if(!$hide){
?>

<div class="text-center p-2">
<form action="" method="post">
<input type="submit" name="bonus" value="CLAIM BONUS" class="btn btn-success w-75 mb-2">
</form>
</div>
		
<?PHP 
	}
}
else {
	$udata = $db->query("SELECT * FROM db_bonus WHERE uid = '$uid'")->fetchArray();

	# Таймер
	$dt=$udata['del']-time();
	$dd=(int)($dt/86400);
	$hh=(int)(($dt-$dd*86400)/3600);
	$mm=(int)(($dt-$dd*86400-$hh*3600)/60);
	$ss=(int)($dt-$dd*86400-$hh*3600-$mm*60);
?>
<div class="text-uppercase bg-light p-3 text-center">Until the next bonus is left: <br/>
<h4 class="text-success"><i class="far fa-clock"></i> <b><?=sprintf("%02d <small>hour</small> %02d <small>min</small> %02d <small>sec</small>", $hh, $mm, $ss);?></b></h4>
</div>
<?php
	}
?>

</div>
</div>


<div class="col col-lg-6 p-2">
<div class="card h-100 shadow-sm mb-2" style="overflow: hidden;">
<div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/a12.png" style="max-width: 64px;"></div>

<h4 class="p-2 text-center mb-0 pb-0 text-success">REFFERAL BONUS 5%</h4>
<h6 class="p-1 text-center mb-0 text-uppercase">Daily referral bonus</h6>
<div class="p-1 text-center h-100"><small>Get a bonus of <span class="text-danger"><b>5%</b></span> of your referral income every 24 hours. <br>
The more funds your referrals deposited, the higher the daily bonus.</small></div>
<?PHP

$hide2 = false;
$true2 = $db->query("SELECT * FROM `db_bonus2` WHERE `uid` = '$uid' AND `del` > '$dadd'")->numRows();
if($true2 == 0){
# Выдача бонуса
if(isset($_POST["bonus2"])){

if($refin > 9.99) {
	# Зачилсяем бонус
	$db->query("UPDATE db_users SET `money_p` = `money_p` + '$sumRef', `earn_bonus` = `earn_bonus` + '$sumRef' WHERE `id` = '$uid'");
	# Вносим запись в список бонусов
	$db->query('INSERT INTO db_bonus2 (`login`, `uid`, `sum`, `add`, `del`) VALUES (?,?,?,?,?)', array($username, $uid, $sumRef, $dadd, $ddel));
	# Случайная очистка устаревших записей
	$db->query("DELETE FROM db_bonus2 WHERE `del` < '$dadd'");
	echo '<div class="alert alert-success">You have received a bonus balance: <b>'.$sumRef.' {!VAL!}</b>.</div>';
	$hide2 = true;
} else {
	echo '<div class="alert alert-danger">No income from referrals</div>';
}
}
# Скрыть кнопку
if(!$hide2){
?>

<div class="text-center p-2">
<form action="" method="post">
<input type="submit" name="bonus2" value="CLAIM BONUS" class="btn btn-success w-75 mb-2">
</form>
</div>

		
<?PHP 
	}
}
else {
	$udata2 = $db->query("SELECT * FROM db_bonus2 WHERE uid = '$uid'")->fetchArray();

	# Таймер
	$dt=$udata2['del']-time();
	$dd=(int)($dt/86400);
	$hh=(int)(($dt-$dd*86400)/3600);
	$mm=(int)(($dt-$dd*86400-$hh*3600)/60);
	$ss=(int)($dt-$dd*86400-$hh*3600-$mm*60);
?>

<div class="text-uppercase bg-light p-3 text-center">Until the next bonus is left: <br/>
<h4 class="text-success"><i class="far fa-clock"></i> <b><?=sprintf("%02d <small>hour</small> %02d <small>min</small> %02d <small>sec</small>", $hh, $mm, $ss);?></b></h4>
</div>
<?php
	}
?>

</div>
</div>



</div>

<div class="center-title mt-3">
<h2>Activity Bonuses</h2>
<p style="line-height: 1.1;">We reward you with an additional bonus for reaching a certain level and completing a task.
</p>
</div>

<div class="row row-cols-1 mx-0">
<div class="col col-lg-6 col-xl-4 p-2">
<div class="card h-100 mb-2" style="overflow: hidden;">
<div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/bb1.png" style="max-width: 64px;"></div>
<h4 class="p-2 text-center mb-0 pb-0 text-success">BONUS 5 USD</h4>
<h6 class="p-1 text-center mb-0 text-uppercase">Deposit Bonus #1</h6>
<div class="p-1 text-center h-100"><small>
You will receive an additional bonus for your deposit  <br> in the amount of 50 USD or more <span class="text-success"><b>+10%</b></span> on your balance.
</small></div>
<div class="text-center p-2">
	<a class="btn btn-danger w-50 mb-2" href="/user/insert" >CLAIM BONUS</a>
</div>
</div>
</div>

<div class="col col-lg-6 col-xl-4 p-2">
<div class="card h-100 mb-2" style="overflow: hidden;">
<div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/bb1.png" style="max-width: 64px;"></div>
<h4 class="p-2 text-center mb-0 pb-0 text-success">BONUS 15 USD</h4>
<h6 class="p-1 text-center mb-0 text-uppercase">Deposit Bonus #2</h6>
<div class="p-1 text-center h-100"><small>
You will receive an additional bonus for your deposit <br> in the amount of 100 USD or more <span class="text-success"><b>+15%</b></span> on your balance.
</small></div>
<div class="text-center p-2">
	<a class="btn btn-danger w-50 mb-2" href="/user/insert" >CLAIM BONUS</a>
</div>
</div>
</div>

<div class="col col-lg-6 col-xl-4 p-2">
<div class="card h-100 mb-2" style="overflow: hidden;">
<div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/bb1.png" style="max-width: 64px;"></div>
<h4 class="p-2 text-center mb-0 pb-0 text-success">BONUS 50 USD</h4>
<h6 class="p-1 text-center mb-0 text-uppercase">Deposit Bonus #3</h6>
<div class="p-1 text-center h-100"><small>
You will receive an additional bonus for your deposit<br> in the amount of 500 USD or more <span class="text-success"><b>+25%</b></span> on your balance.
</small></div>
<div class="text-center p-2">
	<a class="btn btn-danger w-50 mb-2" href="/user/insert" >CLAIM BONUS</a>
</div>
</div>
</div>

<div class="col col-lg-6 col-xl-6 p-2">
<div class="card h-100 mb-2" style="overflow: hidden;">
<div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/bb2.png" style="max-width: 64px;"></div>
<h4 class="p-2 text-center mb-0 pb-0 text-success">BONUS 500 USD</h4>
<h6 class="p-1 text-center mb-0 text-uppercase">Bounty System</h6>
<div class="p-1 text-center h-100"><small>
We are ready to actively encourage our users <br>
we provide a bonus for creating Video Reviews or posts of our Project. 
</small></div>
<div class="text-center p-2">
	<a class="btn btn-danger w-50 mb-2" href="/bounty" >CLAIM BONUS</a>
</div>
</div>
</div>

<div class="col col-lg-6 col-xl-6 p-2">
<div class="card h-100 mb-2" style="overflow: hidden;">
<div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/bb3.png" style="max-width: 64px;"></div>
<h4 class="p-2 text-center mb-0 pb-0 text-success">BONUS 10 USD</h4>
<h6 class="p-1 text-center mb-0 text-uppercase">Affiliate Bonus #1</h6>
<div class="p-1 text-center h-100"><small>
We reward you with an additional bonus for inviting active referrals. Your income on referrals should be from 99 USD
</small></div>
<div class="text-center p-2">
	<a class="btn btn-danger w-50 mb-2" href="/user/refs" >CLAIM BONUS</a>
</div>
</div>
</div>

<div class="col col-lg-6 col-xl-6 p-2">
<div class="card h-100 mb-2" style="overflow: hidden;">
<div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/bb3.png" style="max-width: 64px;"></div>
<h4 class="p-2 text-center mb-0 pb-0 text-success">BONUS 55 USD</h4>
<h6 class="p-1 text-center mb-0 text-uppercase">Affiliate Bonus #2</h6>
<div class="p-1 text-center h-100"><small>
We reward you with an additional bonus for inviting active referrals. Your income on referrals should be from 499 USD
</small></div>
<div class="text-center p-2">
	<a class="btn btn-danger w-50 mb-2" href="/user/refs" >CLAIM BONUS</a>
</div>
</div>
</div>

<div class="col col-lg-6 col-xl-6 p-2">
<div class="card h-100 mb-2" style="overflow: hidden;">
<div class="text-center pt-2"><img loading="lazy" decoding="async" src="/img/bb3.png" style="max-width: 64px;"></div>
<h4 class="p-2 text-center mb-0 pb-0 text-success">BONUS 150 USD</h4>
<h6 class="p-1 text-center mb-0 text-uppercase">Affiliate Bonus #3</h6>
<div class="p-1 text-center h-100"><small>
We reward you with an additional bonus for inviting active referrals. Your income on referrals should be from 999 USD
</small></div>
<div class="text-center p-2">
	<a class="btn btn-danger w-50 mb-2" href="/user/refs" >CLAIM BONUS</a>
</div>
</div>
</div>




		

</div>
</div>



</div>

<br><br><br><br>