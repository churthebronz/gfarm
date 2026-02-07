<?php if(!defined('FastCore')){exit('Opss!');}
$opt = array('title' => 'Referrals contest');
?>

<div class="center-title mt-2">
<h2>Referrals contest</h2>
<p class="text-center">
Take part in the referral contest and get the grand prize!<br>
<small>It is necessary to invite as many active referrals as possible, who <br/>
will replenish the balance and you will receive bonus points: <b>1 USD = 100 points.</b><br/>
The more points you have, the greater the chance to win the contest.
</small>
</p>
</div>

<div class="row text-center">

<div class="col-lg-12 p-2">
<?php
$refs = $db->query("SELECT * FROM db_contest_ref WHERE status = 0 LIMIT 1")->numRows();
if($refs == 1) {
$ref = $db->fetchArray();
?>

<div class="row tarif">

<div class="col-lg-2 col-md-0">
</div>

<div class="col-lg-8 col-md-12">

<div class="about blockprof text-center p-2">
 <b style="font-size: 34px;">PRIZE FUND: <font style="color:#fcd357;font-weight: bold;"><?=$ref["1m"]+$ref["2m"]+$ref["3m"]+$ref["4m"]+$ref["5m"]; ?><span class="notranslate"> USD</span></font></b><hr class="my-1">

 <b style="text-transform: uppercase;" class="text-light">Start: <?=date("d/m/Y - H:i", $ref["date_add"]); ?></b><br/>
<b style="text-transform: uppercase;" class="text-success">Finish: <?=date("d/m/Y - H:i", $ref["date_end"]); ?></b>
</div>

</div>
</div>
<br/>

<?php
$noref = $db->query("SELECT * FROM db_contest_ref_u WHERE points > 0 LIMIT 1")->numRows();
if($noref == 1) {
?>
<div class="stats pb-1">
<div class="stats-title text-uppercase">10 Leaderboard</div>
<div class="table-responsive">
<table class="stats2 table table-sm table-striped mb-0">
<thead>
	<th width="55">Place</th>
	<th>User</th>
	<th>Points</th>
	<th>Prize</th>
</thead>
<?php 
$position = 1;
$refl = $db->query("SELECT * FROM db_contest_ref_u WHERE points > 0 ORDER BY points DESC LIMIT 10")->fetchAll();
foreach ($refl as $li) {
?>
	<tr>
		<td width="50"><?=$position; ?></td>
		<td class="notranslate"><?=$li['login']; ?></td>
		<td><?=sprintf("%.0f",$li["points"]); ?></td>
		<td class="notranslate"><?=(!empty($ref["{$position}m"]) > 0) ? "<span class='text-sum'>".$ref["{$position}m"]." <small>{!VAL!}</small></span>" : "1 USD" ?></td>
	</tr>
<?php
$position++; 
	}
?>
</table>
</div>
</div>
<?php	
		} else echo '<div class="alert alert-warning text-danger text-center m-2"><b>There are no participants, start first.</b></div>'; 
	} else { echo '<div class="alert alert-warning text-danger text-center m-2"><b>Currently, the competition is not held</b></div>'; 
}
?>
</div>
</div>