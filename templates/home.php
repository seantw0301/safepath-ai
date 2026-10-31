<h1><?= $t('hero') ?></h1>
<p class="muted"><?= $t('hero_sub') ?></p>
<?php if (\SafePath\Http\Access::liveUploads()): ?>
<p><a class="btn" href="<?= $u('/new') ?>"><?= $t('new_scan') ?></a></p>
<?php else: ?>
<div class="banner"><?= $t('uploads_off') ?></div>
<?php endif; ?>

<h2><?= $t('how') ?></h2>
<div class="steps">
<?php foreach ([1, 2, 3, 4, 5] as $n): ?>
  <div><b><?= $t("step$n") ?></b><?= $t("step{$n}d") ?></div>
<?php endforeach; ?>
</div>

<h2><?= $t('demos') ?></h2>
<div class="banner"><?= $t('sim_home') ?></div>
<div class="grid">
<?php foreach ($demos as $d): ?>
  <a class="card" style="text-decoration:none;color:inherit" href="<?= $u('/scans/' . (int) $d['id']) ?>">
    <b><?= $e($d['name']) ?></b><br>
    <span class="muted small"><?= $t('m_' . $d['mobility']) ?></span>
  </a>
<?php endforeach; ?>
</div>

<?php if ($recent !== []): ?>
<h2><?= $t('your_scans') ?></h2>
<div class="card tablewrap"><table>
<?php foreach ($recent as $r): ?>
<tr><td><a href="<?= $u('/scans/' . (int) $r['id']) ?>"><?= $e($r['name']) ?></a></td><td><?= $e($r['status']) ?></td><td class="muted"><?= $e($r['created_at']) ?></td></tr>
<?php endforeach; ?>
</table></div>
<?php endif; ?>

<p class="muted small" style="margin-top:24px"><?= $t('disclaimer') ?></p>
