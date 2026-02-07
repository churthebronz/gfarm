<?php if(!defined('FastCore')){exit('Opss!');}
# Заголовки
$opt = array(
'title' => 'News',
'description' => 'The latest and current news on our website, hamster about contests and promotions, read so as not to miss.'
);

?>
<br><br>
<div class="row">
<?php

$rows = $db->query("SELECT * FROM `db_news` WHERE `id` > 0")->numRows();

# Пагинация
$cnt = 10;
$nav ='/news';
$page = $pg->params[1] ?? 1;
$start = ($page * $cnt) - $cnt;
$str_pag = ceil($rows / $cnt);

if($rows == 0) {
    echo '<div class="alert alert-danger">At the moment, the news has not been published.</div>';
} 
else {
$news = $db->query('SELECT * FROM `db_news` ORDER BY `id` DESC LIMIT '.$start.','.$cnt.'')->fetchAll();
foreach ($news as $news) { 
?>
<div class="col-xl-2"></div>
<div class="col-lg-12 col-xl-8">

<div class="center-title mt-2 pb-0">
<span><i class="far fa-calendar-alt"></i> <?=date("d M Y - H:i",$news['add']); ?></span>
<h2 style="font-size: 2.5em;"><?=$news['title']; ?></h2> <!-- Increased title size -->
</div>
<div class="text-center">
<div class="p-2" style="font-size: 1.5em;"><?=$news['text']; ?></div> <!-- Increased text size -->
</div><hr>
</div>

<div class="col-xl-2"></div>
<?php
    }
    ?>
</div>
    <?php
    # Выводим пагинацию
    if ($rows > $cnt) {
    echo '<ul class="pagination"><li class="page-item"><a class="page-link" href="'.$nav.'">«</a></li>';
    for ($i = 1; $i <= $str_pag; $i++){
        echo '<li class="page-item"><a class="page-link" href="'.$nav.'/p/'.$i.'">'.$i.'</a></li>';
        }
    echo '<li class="page-item"><a class="page-link" href="'.$nav.'/p/'.$str_pag.'">»</a></li></ul>';
    }
}
?>