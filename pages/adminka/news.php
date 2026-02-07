<?php if(!defined('FastCore')){exit('Opss!');} ?>
<h3>News</h3>
<div class="btn-group mb-2 text-uppercase">
<a class="btn btn-outline-dark" href="/<?=$adm;?>/news"> </a>
<a class="btn btn-success" href="/<?=$adm;?>/news/add">Add </a>
</div>
<?PHP
if(isset($_POST["del"])){
$del_news = intval($_POST["del"]);
$db->Query("DELETE FROM db_news WHERE id = '$del_news'");
	echo "<center><b> </b></center><BR />";
}

# 
if(!empty($pg->segment[2] ==='add')) {
	if(isset($_POST["title"], $_SESSION["add_news"]) AND $_SESSION["add_news"] == $_POST["add_news"]) {
	unset($_SESSION["add_news"]);

$title = $_POST["title"];
$text = $_POST["ntext"];

if(strlen($title) >= 3){
 $db->Query("INSERT INTO db_news (title, text, `add`) VALUES ('$title','$text','".time()."')");
 echo '<div class="alert alert-success"> </div>';	
	} else echo '<div class="alert alert-danger"> 3 </div>';
}
?>

<div class="card mb-3">
<div class="card-header"> news</div>
<div class="p-2">
<form action="" method="post">
<b>:</b>
<input class="form-control mb-2" type="text" name="title" value="<?=(isset($_POST['title'])) ? $_POST['title'] : false; ?>" />
<b>:</b>
<textarea class="form-control mb-2" name="ntext" cols="70" rows="5"><?=(isset($_POST['ntext'])) ? $_POST['ntext'] : false; ?></textarea><BR />
<input type="submit" value="Save" class="btn btn-success"/>
<span style="display: none;"><?=$_SESSION["add_news"] = rand(1,1000);?><span>
<input type="hidden" name="add_news" value="<?=$_SESSION["add_news"]; ?>" />
</form>
</div></div>
<?PHP
return;
}


# 
if($pg->segment[2] === 'edit'){

$id_edit = intval($pg->params[1]);
$db->Query("SELECT * FROM db_news WHERE id = '$id_edit' LIMIT 1");

if($db->NumRows() != 1){ echo '<div class="alert alert-danger"> ID </div>'; return;}

	if(isset($_POST["title"])){
	
	$title = $_POST["title"];
	$title = (strlen($title) > 0) ? $title : " ";
	$text = $_POST["ntext"];
	$db->Query("UPDATE db_news SET title = '$title', text = '$text' WHERE id = '$id_edit'");
	$db->Query("SELECT * FROM db_news WHERE id = '$id_edit' LIMIT 1");
	
 echo '<div class="alert alert-success"> </div>';
	}

$news = $db->FetchArray();


?>

<div class="card mb-3">
<div class="card-header">: <?=$news['id']; ?></div>
<div class="p-2">
<form action="" method="post">
<b>:</b>
<input class="form-control mb-2" type="text" name="title" size="45" value="<?=$news['title']; ?>" />
<b>:</b>
<textarea class="form-control mb-2" name="ntext" cols="70" rows="5"><?=$news['text']; ?></textarea>
<input type="submit" value="Save" class="btn btn-success"/>
</form>
</div>
</div>
<?PHP

return;
}

?>
<style>
table tr td {padding: 5px !important; vertical-align: middle !important;}
</style>

<table class="table table-hover text-center table-bordered bg-white">
<thead class="bg-light">
	<th width="50">ID</td>
	<th></td>
	<th width="70"></td>
</thead>
<tbody>
<?php

$rows = $db->query("SELECT * FROM `db_news` WHERE `id` > 0")->numRows();
if($rows == 0) { 
	echo '<tr><td colspan="3"><div class="alert alert-danger"> </div></td></tr>';
}
else {
$data = $db->query("SELECT * FROM `db_news` ORDER BY `id` DESC LIMIT 50")->fetchAll();
foreach ($data as $data) { 
?>
<tr>
	<td width="50"><?=$data["id"]; ?></td>
	<td><b><a href="/<?=$adm;?>/news/edit/<?=$data['id']; ?>"><?=$data['title']; ?></a></b></td>
	<td width="70">
	<form action="" method="post" class="m-0 p-0">
	<input type="hidden" name="del" value="<?=$data['id']; ?>" />
	<input type="submit" value="Delete" class="btn btn-danger btn-sm"/>
	</form>
	</td>
</tr>
<?php
	}
} 
?>
</tbody>
</table>