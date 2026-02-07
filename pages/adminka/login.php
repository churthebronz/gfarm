<?php if(!defined('FastCore')){exit('Opss!');}
?>
<div class="col-md-4"></div>
<div class="col-md-4 alert-light p-0"><h3><b><center class="bg-dark text-light p-1">Fast<span class="text-warning">Core</span></center> </b></h3>
<div class="m-3">
<?php
if(isset($_SESSION["admin"])){ header("Location: /".$adm."/"); return; }

if(isset($_POST["admlogin"])){

	$log = $_POST["admpass"];

	if($log == $config->adm_pass) {

	if(strtolower($_POST["admlogin"]) == strtolower($config->adm_name) ){ 
	
 $_SESSION["admin"] = true;
 header("Location: /".$adm."");
return;
 } else echo '<div class="alert alert-danger">Invalid username</div>';
	} else echo '<div class="alert alert-danger">Invalid password</div>';
}

?>
<h4 class="card-title">Login Admin-panel</h4>
<form action="" method="post">
	<input type="text" placeholder="Username" class="form-control mb-2" name="admlogin" value="" />
	<input type="password" placeholder="Password" class="form-control mb-2" name="admpass" value="" />
	<input type="submit" value="Sign in" class="btn btn-success"/>
</form>
</div>
</div>
</div></div>