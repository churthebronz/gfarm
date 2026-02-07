<?php if(!defined('FastCore')){exit('Opss!');}
# Заголовки
$opt = array(
    'title' => 'Bounty System',
    'description' => 'script game buy shop money game buy script php fastcore shop script hyip.'
    );
?>

<div class="center-title mt-2">
<h2>Bounty System</h2>
<p class="text-center">We are ready to actively encourage our users<br>
we provide a bonus for creating Video Reviews or posts of our Project. 
</p>
</div>

<div class="row row-grid align-items-center notranslate"> 

<div class="col-lg-4"> 
<div class="wrapper2 about mb-2 py-3">
<div class="text-center"><img loading="lazy" decoding="async" src="/img/b4.png" style="width: 128px;" class="mx-auto"></div>
<div class="text-center">
<h5><b>Youtube</b></h5>
<button class="btn btn-danger btn-lg" type="button" data-bs-toggle="collapse" data-bs-target="#youtube"><b><i class="fa fa-gift"></i> REWARD: 5 - 1000 {!VAL!}</b></button>
</div>
</div>
</div>

<div class="col-lg-4"> 
<div class="wrapper2 about mb-2 py-3">
<div class="text-center"><img loading="lazy" decoding="async" src="/img/b3.png" style="width: 128px;" class="mx-auto"></div>
<div class="text-center">
<h5><b>Monitors/Blogs:</b></h5>
<button class="btn btn-danger btn-lg" type="button" data-bs-toggle="collapse" data-bs-target="#blog"><b><i class="fa fa-gift"></i> REWARD: 5 - 250 {!VAL!}</b></button>
</div>
</div>
</div>

<div class="col-lg-4"> 
<div class="wrapper2 about mb-2 py-3">
<div class="text-center"><img loading="lazy" decoding="async" src="/img/b2.png" style="width: 128px;" class="mx-auto"></div>
<div class="text-center">
<h5><b>FB/ VK/ TG</b></h5>
<div class="btn btn-danger btn-lg" type="button" data-bs-toggle="collapse" data-bs-target="#soc"><b><i class="fa fa-gift"></i> REWARD: 2 - 100 {!VAL!}</b></div>
</div>
</div>
</div>
</div>


<div class="tab-content" id="pills-tabContent">

  <div id="youtube" class="p-2 collapse">
<h5><b>Review Videos - Youtube</b></h5>
<p>Create a review video and post it on your YouTube channel get paid for it<br>
* Your channel and videos must be public.<br>
* You must have at least 100 subscribers. <br>
* The minimum video length is 1 minute.<br>
* Only 1 video per week will be paid. Leave your referral link in the video description box.<br>
Don't delete the video. If you delete the video, this action will be considered fraud and you'll be disqualified from participation in the bounty program and admin reserves the right to block your account and payout. The company reserves the right to provide the bonus at its full discretion, depending on the quality of the video content.</p>
</div>

<div id="blog" class="p-2 collapse">
<h5><b>Monitors/Blogs:</b></h5>
<p>Create a listing for our game on your monitor or blog and get paid for it<br>
* Post a thread or comment on a social media forum or blog.<br>
* Blog post must have a minimum of 200 words.<br>
* Blog post must have your referral link on the very last part of the blog post.<br>
* Your Monitor listing must be available on Public and has your referral link<br>
** Don't delete the Votes or blog. If you delete it, this action will be considered fraud and you'll be disqualified from participation in the bounty program and admin reserves the right to block your account and payout. </p>
</div>

<div id="soc" class="p-2 collapse">
<h5><b>Facebook/VK/Telegram</b></h5>
<p>Create a post/tweet of our project explaining your experience with us and get paid for it <br>
* Send us the link of the post/tweet<br>
* Your profile must be Public<br>
* Only one submission per week is allowed </p>
</div>

</div><br>
<hr><br>
<?php

$limit = $db->query("SELECT * FROM db_reviews WHERE uid = '$uid' AND hide = '0'")->numRows();

$comm = $db->query("SELECT * FROM db_users WHERE id = '$uid'")->fetchArray();
$login = $comm['login'] ?? 'Guest';

# Добавляем отзыв
if(isset($_POST['yousend'])) {

$date = time();
$text = filter_var($_POST['youlink'], FILTER_SANITIZE_STRING);

if($limit >= 1) {
	$err[]='You have already left a review! Thank you for your activity!'; }
elseif (!isset($_SESSION["login"])) {
	$err[]='You need to log in to your account to send!'; }
elseif(mb_strlen($text) < 10 or mb_strlen($text) > 1000) {
	$err[]='Link length can not be at least 10 maximum 1000 characters'; }
else {
	$db->query('INSERT INTO db_reviews (login, uid, text,`date`) VALUES (?,?,?,?)', array($login, $uid, $text, $date)); // Сохраняем

	echo '<div class="alert alert-success">Your link has been accepted, pending approval</div>'; }
}
# ошибки
if (!empty($err)) {
	echo '<div class="alert alert-danger"><i class="fa fa-exclamation-triangle mr-3"></i> '.array_shift($err).'</div>';
}
?>
<div class="text-center mt-3">
<h4>Link to page or video / channel</h4>

<div class="p-2">

    <form action="" method="POST">
<div class="p-1 text-uppercase">
<div class="input-group input-group-lg mb-2">
	<input type="text" class="form-control" placeholder="Link video / page / channel" value="" name="youlink">
</div>

<button class="btn btn-lg btn-primary text-uppercase" name="yousend" type="submit">SEND</button>
</div></form>

</div>

</div>
<!-- Post bounty -->