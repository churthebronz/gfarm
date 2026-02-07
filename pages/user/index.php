<?php
header('Location: /user/dashboard'); return;
/**
  * Сокрытие директории файлом index.php либо .htaccess
 */
?>

<div id="vx-activity-ticker" style="margin:12px 0;"></div>
<script src="/assets/js/activity_ticker.js" data-endpoint="/api/activity_feed.php" data-root="vx-activity-ticker" defer></script>
<link rel="stylesheet" href="/assets/css/vx_rarity.css">
