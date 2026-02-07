<?php if(!defined('FastCore')){exit('Opss!');}
?>

<h3 class="">Users IP</h3>

<form action="" method="POST" style="width: 250px;">
 <div class="input-group">
	<input type="text" name="ip2" class="form-control" placeholder=" ip" />
 <div class="input-group-append">
 <input type="submit" class="btn btn-success" value="Search" />
 </div>
	</div>
</form>

<?php
# Page 
$page = $pg->params[1] ?? 1;
$rows = 100; // - 
$start = ($page * $rows) - $rows; // $rows

# username 
if(isset($_POST["ip2"])){
$ip2 = $_POST["ip2"];
$db->query("SELECT * FROM db_uips WHERE ip2 = '$ip2'");
$rows = 1;
$navs= 0;
}

?>

<table class="table table-sm table-bordered table-hover text-center bg-white">
<thead class="bg-light">
	<th>ID</th>
	<th>Username</th>
	<th>Balance</th>
	<th>IN/OUT</th>
	<th>Referer</th>
	<th></th>
	<th>IP</th>
	<th>Login</th>
</thead>

<?php

if (isset($_POST["ip2"])) {
	$ip2 = $_POST["ip2"];
	$uu_data = $db->query("SELECT * FROM db_uips WHERE ip2 = '$ip2'")->fetchArray();
	$login2 = $uu_data['id'];
	$u_data = $db->query("SELECT * FROM db_users WHERE id = '$login2'")->fetchAll();
} else {
	$n_rows = $db->query("SELECT id FROM db_users ORDER BY id DESC LIMIT 1")->fetchArray();
	$total = $n_rows['id'];
	$navs = ceil($total / $rows);
	
	$u_data = $db->query('SELECT * FROM `db_users` ORDER BY auth DESC LIMIT '.$start.','.$rows.'')->fetchAll();
}

foreach ($u_data as $u) {
 $iddd = $u['id'];
	$uips = $db->query("SELECT * FROM db_uips WHERE id = '$iddd'")->fetchArray();
?>
<tr>
	<td><?=$u['id'];?></td>
	<td><a href="/<?=$adm;?>/users/info/<?=$u['id'];?>"><?=$u['login'];?></a></td>
	<td><?=$u['money_b']+$u['money_p'];?> </td>
	<td><span class="text-success"><?=$u['sum_in'];?> </span> / <span class="text-danger"><?=$u['sum_out'];?> </span></td>
	<td><?=$u['referer'];?></td>
	<td><?=$u['refsite'];?></td>
	<td><?=$uips['ip2'];?></td>
	<td><?=date("H:i:s",$u['auth']);?></td>
</tr>
<?php
}
?>
</table>
<?php
	# Withdrawal 
	echo '<ul class="pagination"><li class="page-item disabled"><a class="page-link" href="#" tabindex="-1">Page</a></li>';
	for ($i = 1; $i <= $navs; $i++){ echo '<li class="page-item"><a class="page-link" href="/'.$adm.'/uips/p/'.$i.'">'.$i.'</a></li>'; }
	echo '</ul>';
 

?>