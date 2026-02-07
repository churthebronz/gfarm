
<?php
if (!defined('FastCore')) { exit('Oops!'); }
if (!isset($_SESSION['user_id'])) { header('Location:/'); exit; }

global $db;
$userId = (int)$_SESSION['user_id'];

/* ---------- CODEX LEADERBOARD (MariaDB-safe) ---------- */

// Top 3 Codex Discoverers
$topCodex = $db->fetchAll("
  SELECT u.id, u.username, COUNT(DISTINCT ug.vault_definition_id) AS codex_count
  FROM user_guardians ug
  JOIN db_users u ON u.id = ug.user_id
  GROUP BY ug.user_id
  ORDER BY codex_count DESC
  LIMIT 3
");

// User Codex Count
$myCodexCount = (int)$db->fetchOne("
  SELECT COUNT(DISTINCT vault_definition_id)
  FROM user_guardians
  WHERE user_id = ?
", [$userId]);

// User Rank (count how many users have MORE than me)
$myRank = (int)$db->fetchOne("
  SELECT COUNT(*) + 1
  FROM (
    SELECT COUNT(DISTINCT vault_definition_id) AS codex_count
    FROM user_guardians
    GROUP BY user_id
  ) t
  WHERE t.codex_count > ?
", [$myCodexCount]);

// Relative Rank (above / me / below)
$relativeCodex = $db->fetchAll("
  SELECT u.id, u.username, t.codex_count
  FROM (
    SELECT user_id, COUNT(DISTINCT vault_definition_id) AS codex_count
    FROM user_guardians
    GROUP BY user_id
    ORDER BY codex_count DESC
    LIMIT 3 OFFSET ?
  ) t
  JOIN db_users u ON u.id = t.user_id
", [max($myRank - 2, 0)]);
?>

<div class="gf-leaderboards">
  <h2>🏆 Leaderboards</h2>

  <div class="gf-lb-tabs">
    <button data-tab="codex" class="active">🧬 Codex</button>
    <button data-tab="ref">🤝 Referrals</button>
    <button data-tab="season">⏳ Season</button>
  </div>

  <div class="gf-lb-panel active" id="lb-codex">
    <h3>Top Discoverers</h3>
    <div class="gf-top3">
      <?php foreach ($topCodex as $i=>$p): ?>
        <div class="podium <?=['gold','silver','bronze'][$i]?>">
          #<?=($i+1)?> <?=$p['username']?> (<?=$p['codex_count']?>)
        </div>
      <?php endforeach; ?>
    </div>

    <h4>Your Rank</h4>
    <div class="gf-relative">
      <?php foreach ($relativeCodex as $p): ?>
        <div class="<?=($p['id']==$userId?'me':'')?>">
          <?=$p['username']?> — <?=$p['codex_count']?> discovered
          <?=($p['id']==$userId?' 👈 You':'')?>
        </div>
      <?php endforeach; ?>
      <small class="gf-muted">Your rank: #<?=$myRank?></small>
    </div>
  </div>

  <div class="gf-lb-panel" id="lb-ref">
    <?php
$topRef = $db->fetchAll("SELECT u.id,u.username,COUNT(*) c FROM vx_ref_earnings r JOIN db_users u ON u.id=r.ref_user_id GROUP BY r.ref_user_id ORDER BY c DESC LIMIT 3");
?>
<div class="gf-top3"><?php foreach($topRef as $i=>$p): ?><div class="podium <?=['gold','silver','bronze'][$i]?>">#<?=($i+1)?> <?=$p['username']?> (<?=$p['c']?>)</div><?php endforeach;?></div>
  </div>

  <div class="gf-lb-panel" id="lb-season">
    <?php
$seasonId = (int)$db->fetchOne("SELECT id FROM vx_seasons WHERE active=1 ORDER BY id DESC LIMIT 1");
$topSeason = $db->fetchAll("SELECT u.id,u.username,SUM(p.points) pts FROM vx_season_points p JOIN db_users u ON u.id=p.user_id WHERE p.season_id=? GROUP BY p.user_id ORDER BY pts DESC LIMIT 3", [$seasonId]);
?>
<div class="gf-top3"><?php foreach($topSeason as $i=>$p): ?><div class="podium <?=['gold','silver','bronze'][$i]?>">#<?=($i+1)?> <?=$p['username']?> (<?=$p['pts']?>)</div><?php endforeach;?></div>
  </div>
</div>

<script>
document.querySelectorAll('.gf-lb-tabs button').forEach(b=>{
  b.onclick=()=>{
    document.querySelectorAll('.gf-lb-tabs button').forEach(x=>x.classList.remove('active'));
    document.querySelectorAll('.gf-lb-panel').forEach(p=>p.classList.remove('active'));
    b.classList.add('active');
    document.getElementById('lb-'+b.dataset.tab).classList.add('active');
  };
});
</script>
