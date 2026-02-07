<?php if(!defined('FastCore')){exit('Opss!');}
# Заголовки
$opt = array(
'title' => 'Online stats',
'keywords' => 'Online stats, users, top, referal, members, earning',
'description' => 'Statistics of our project, you can see the leaders and the activity of the players.'
);

# Статистика
$stats = $db->query("SELECT * FROM db_stats WHERE id = '1'")->fetchArray();

function num2word($num = 0, $words = array())
{
    $num     = (int) $num;
    $cases   = array(2, 0, 1, 1, 1, 2);
    return $num . ' <small>' . $words[($num % 100 > 4 && $num % 100 < 20) ? 2 : $cases[min($num % 10, 5)]];
}

$prworks = intval(((time() - $config->start_time) / 86400 ) +1);

?>
<div class="container mt-5">
    <h2 class="text-center mb-4">CashFarm 💸🌾: Investment Statistics 💵📊</h2>
    <div class="row text-center">
    </div>
</div>
<style>
@media only screen and (max-width: 721px) {
.stats {
  padding: 5px 2px 0;
}
.table td {font-size: 11px;
}
.table-sm > :not(caption) > * > * {
  padding: .25rem 1px;
}
.hidetime f2023630{
  display:none !important;
}
}
</style>
<div class="row p-lg-3">
<div class="col-lg-6 p-lg-2">
<div class="stats pb-1">
<div class="stats-title text-uppercase">Last 10 deposits</div>
<div class="table-responsive">
<table class="stats2 table table-sm table-striped mb-0">
	<thead>
		<th class="text-center">#</th>
		<th>User</th>
		<th class="text-center">Sum</th>
		<th class="text-end pe-lg-2">Time</th>
	</thead>
<?php
$inserts = $db->query('SELECT * FROM db_insert WHERE status = 1 ORDER BY end DESC LIMIT 10')->fetchAll();
foreach ($inserts as $inserts) {
		?>
		<tr class="notranslate">
			<td class="text-center"><img loading="lazy" decoding="async" src="/img/s1.png" height="22" /> </td>
			<td><?=$inserts['login']; ?></td>
			<td class="text-center"><span class="text-sum">$<?= sprintf("%.2f",$inserts['sum']); ?> <small>{!VAL!}</small></span></td>
			<td class="text-end pe-lg-2 hidetime"><?=date('d M <font>Y -</font> H:i',$inserts['end']); ?></td>
  		</tr>
		<?php
	}
  ?>
</table></div>
</div>
</div>

<div class="col-lg-6 p-lg-2">
<div class="stats pb-1">
<div class="stats-title text-uppercase">Last 10 payouts</div>
<div class="table-responsive">
<table class="stats2 table table-sm table-striped mb-0">
	<thead>
		<th class="text-center">#</th>
		<th>User</th>
		<th class="text-center">Sum</th>
		<th class="text-end pe-lg-2">Time</th>
	</thead>
<?php
$payout = $db->query("SELECT * FROM db_payout WHERE uid > 0 AND login <> '' ORDER BY `add` DESC LIMIT 10")->fetchAll();
foreach ($payout as $payout) {
		?>
		<tr class="notranslate">
			<td class="text-center"><img loading="lazy" decoding="async" src="/img/s1.png" height="22" /></td>
			<td><?=$payout['login']; ?></td>
			<td class="text-center"><span class="text-sum">$<?= sprintf("%.2f",$payout['sum']); ?> <small>{!VAL!}</small></span> </td>
			<td class="text-end pe-lg-2 hidetime"><?=date("d M <font>Y -</font> H:i",$payout['add']); ?> </td>
  		</tr>
		<?php
	}
  ?>
</table></div>
</div></div>

<div class="col-lg-4 p-lg-2">
<div class="stats pb-1">
<div class="stats-title text-uppercase">Top 5 deposits</div>
<div class="table-responsive">
<table class="stats2 table table-sm table-striped mb-0">
	<thead>
		<th class="text-center">#</th>
		<th>User</th>
		<th class="text-end">Deposits</th>
	</thead>
<?php
$inserts = $db->query('SELECT * FROM db_users WHERE sum_in ORDER BY sum_in DESC LIMIT 5')->fetchAll();
foreach ($inserts as $inserts) {
		?>
		<tr class="notranslate">
			<td class="text-center"><img loading="lazy" decoding="async" src="/img/s1.png" height="22" /> </td>
			<td><?=$inserts['login']; ?></td>
			<td class="text-end"><span class="text-sum">$<?= sprintf("%.2f",$inserts['sum_in']); ?> <small>{!VAL!}</small></span></td>
  		</tr>
		<?php
	}
  ?>
</table></div>
</div>
</div>

<div class="col-lg-4 p-lg-2">
<div class="stats pb-1">
<div class="stats-title text-uppercase">Top 5 earners</div>
<div class="table-responsive">
<table class="stats2 table table-sm table-striped mb-0">
	<thead>
		<th class="text-center">#</th>
		<th>User</th>
		<th class="text-end">Payouts</th>
	</thead>
<?php
$inserts = $db->query('SELECT * FROM db_users WHERE sum_out ORDER BY sum_out DESC LIMIT 5')->fetchAll();
foreach ($inserts as $inserts) {
		?>
		<tr class="notranslate">
			<td class="text-center"><img loading="lazy" decoding="async" src="/img/s1.png" height="22" /> </td>
			<td><?=$inserts['login']; ?></td>
			<td class="text-end"><span class="text-sum">$<?= sprintf("%.2f",$inserts['sum_out']); ?> <small>{!VAL!}</small></span></td>
  		</tr>
		<?php
	}
  ?>
</table></div>
</div>
</div>

<div class="col-lg-4 p-lg-2">
<div class="stats pb-1">
<div class="stats-title text-uppercase">Top 5 partners</div>
<div class="table-responsive">
<table class="stats2 table table-sm table-striped mb-0">
	<thead>
		<th class="text-center">#</th>
		<th>User</th>
		<th class="text-end">Referrals</th>
	</thead>
<?php
$inserts = $db->query('SELECT * FROM db_users WHERE refs > 0 AND id != 1 ORDER BY refs DESC LIMIT 5')->fetchAll();
foreach ($inserts as $inserts) {
		?>
		<tr class="notranslate">
			<td class="text-center"><img loading="lazy" decoding="async" src="/img/s1.png" height="22" /> </td>
			<td><?=$inserts['login']; ?></td>
			<td class="text-end"><span class="text-sum"><?=  $inserts['refs']; ?></span></td>
  		</tr>
		<?php
	}
  ?>
</table></div>
</div>
</div>

</div>