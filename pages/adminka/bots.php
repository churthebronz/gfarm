<?php if(!defined('FastCore')){exit('Opss!');}
$limits = 100;

?>

<h3> </h3>
<table class="table table-sm table-bordered table-hover text-center bg-white">
<thead class="bg-light">
	<th>ID</th>
	<th>Username</th>
	<th>IP</th>
	<th>Login</th>
	<th></th>
</thead>
<?
$ssurfview = $db->query('SELECT * FROM db_logviews WHERE id ORDER BY id DESC LIMIT '.$limits.'')->fetchAll();
	foreach ($ssurfview as $sv) {
$svl = $db->query('SELECT login,auth FROM db_users ORDER BY id = '.$sv['uid'].' DESC LIMIT 1')->fetchArray();
?>
<tr>
	<td><?=$sv['id'];?></td>
	<td><a href="/<?=$adm;?>/users/info/<?=$sv['uid'];?>"><?=$svl['login'];?></a></td>
	<td><?=$sv['ip'];?></td>
	<td><?=date("H:i:s",$svl['auth']);?></td>
	<td><?=date("H:i:s",$sv['time_add']);?></td>
</tr>
<? } ?>
</table>