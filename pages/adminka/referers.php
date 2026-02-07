<?php if(!defined('FastCore')){exit('Oops!');} ?>

<h3> </h3>
<div class="btn-group mb-2 text-uppercase">
<a class="btn btn-outline-dark" href="/<?=$adm;?>/referers"> 24 </a>
<a class="btn btn-outline-dark" href="/<?=$adm;?>/referers/all"> </a>
</div>
<table class="table table-bordered table-striped text-center bg-white">
<thead>
<tr>
	<th>#</th>
	<th></th>
	<th></th>
</tr>
</thead>
<tbody>
<?PHP

if (isset($pg->segment[2]) && $pg->segment[2] == 'all') {
	$refs = $db->query('SELECT refsite, COUNT(*) AS `id` FROM db_users WHERE `id` GROUP BY refsite ORDER BY `id` DESC LIMIT 500')->fetchAll();
}
else {
	$time=time()-86400;
	$refs = $db->query('SELECT refsite, COUNT(*) AS `id` FROM db_users WHERE reg > '.$time.' GROUP BY refsite ORDER BY `id` DESC LIMIT 100')->fetchAll();
}
$i=1;
foreach ($refs as $r) {
?>
<tr>
<td><b><?=$i; ?> </b></td>
<td><b><?=$r['refsite']; ?></b> </td>
<td><b><?=$r['id']; ?></b> </td>
</tr>
<?php
$i++;
}
?>
</table>