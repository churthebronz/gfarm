<?php
if(!defined('FastCore')){echo (' !');exit();}
?>
<h3>Youtube </h3>
<?PHP
# 
if(isset($_POST['cancel'])){
$cancel_id = intval($_POST['cancel']);
$uid_cancel = intval($_POST['uid_cancel']);
$sum_cancel = filter_var($_POST['sum_cancel'], FILTER_VALIDATE_FLOAT);
$admtext = $_POST['text'];
$db->query("UPDATE db_reviews SET `hide` = '1', `reward` = '0', `adm_text` = '$admtext' WHERE id = '$cancel_id'");
	echo "<div class='alert alert-success text-center'> !</div>";
}
?>

<?PHP
# 
if(isset($_POST['success'])){
 
$success_id = intval($_POST['success']);
$uid_success = intval($_POST['uid_success']);
$admtext = $_POST['text'];

# 
$bountySum = filter_var($_POST['sum_success'], FILTER_VALIDATE_FLOAT);

$db->query("UPDATE db_users SET money_p = money_p + '$bountySum' WHERE id = '$uid_success '");
$db->query("UPDATE db_reviews SET `hide` = '1', `reward` = '$bountySum', `adm_text` = '$admtext' WHERE id = '$success_id'");

echo "<div class='alert alert-success text-center'> !</div>";
}
?>
<table class="table table-bordered table-striped text-center bg-white">
<thead>
<tr>
	<th>#</th>
	<th>Username</th>
	<th>Video</th>
	<th>Date </th>
	<th colspan="2"></th>
</tr>
</thead>
<tbody>


<?php

$rows = $db->query("SELECT * FROM `db_reviews` WHERE `id` > 0 AND `hide` = 0")->numRows();
if($rows == 0) { 
	echo '<tr><td colspan="6"><div class="alert alert-danger"> </div></td></tr>';
}
else {
$list = $db->query("SELECT * FROM `db_reviews` WHERE `hide` = 0 ORDER BY `id` DESC LIMIT 50")->fetchAll();
foreach ($list as $list) { 
?>
<tr>
	<td><?=$list['id']; ?></td>
	
	<td><a href="/<?=$adm;?>/users/info/<?=$list['uid'];?>"><?=$list['login'];?></a></td>
	<td><?=$list['text']; ?></td>
	<td><?=date("d/m/Y H:i",$list['date']);?></td>
	<td>
	<form action="" method="post" class="m-0 p-0">
 <input type="hidden" name="success" value="<?=$list['id'];?>" />
 <input type="hidden" name="uid_success" value="<?=$list['uid'];?>" />
 <small>REWARD:</small>
 <input name="sum_success" value="" class="form-control" />
 <small>TEXT:</small>
 <input name="text" value="" class="form-control" />
 <button class="btn btn-success btn-sm" type="submit"></button>
	</form>
	</td>
	<td>
	<form action="" method="post" class="m-0 p-0">
 <input type="hidden" name="cancel" value="<?=$list['id'];?>" />
 <input type="hidden" name="uid_cancel" value="<?=$list['uid'];?>" />
 <small>TEXT:</small>
 <input name="text" value="" class="form-control" />
 <button class="btn btn-danger btn-sm" type="submit"></button>
	</form>

	</td>
</tr>
<?php
	}
}
?> 
 </tbody>
</table>