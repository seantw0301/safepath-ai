<div class="hero">
<h1><?= $t('hero') ?></h1>
<p class="muted"><?= $t('hero_sub') ?></p>
<?php if (\SafePath\Http\Access::liveUploads()): ?>
<p><a class="btn" href="<?= $u('/new') ?>"><?= $i('plus') ?><?= $t('new_scan') ?></a></p>
<?php else: ?>
<div class="banner"><?= $i('info') ?><span><?= $t('uploads_off') ?></span></div>
<?php endif; ?>
</div>

<h2><?= $i('list') ?><?= $t('how') ?></h2>
<div class="steps">
<?php foreach ([1 => 'film', 2 => 'eye', 3 => 'ruler', 4 => 'file', 5 => 'check'] as $n => $ic): ?>
  <div class="c<?= $n ?>"><span class="dot"><?= $i($ic) ?></span><b><?= $t("step$n") ?></b><?= $t("step{$n}d") ?></div>
<?php endforeach; ?>
</div>

<h2><?= $i('home') ?><?= $t('demos') ?></h2>
<div class="banner"><?= $i('info') ?><span><?= $t('sim_home') ?></span></div>
<div class="grid">
<?php foreach ($demos as $d): ?>
  <a class="card demo" style="text-decoration:none;color:inherit" href="<?= $u('/scans/' . (int) $d['id']) ?>">
    <span class="tile <?= $d['mobility'] === 'wheelchair' ? 'c2' : 'c1' ?>"><?= $i($d['mobility'] === 'wheelchair' ? 'wheelchair' : 'user') ?></span>
    <span><b><?= $e($d['name']) ?></b><br><span class="muted small"><?= $t('m_' . $d['mobility']) ?></span></span>
    <span class="go"><?= $i('next') ?></span>
  </a>
<?php endforeach; ?>
</div>

<?php if ($recent !== []): ?>
<h2><?= $i('image') ?><?= $t('your_scans') ?></h2>
<div class="card tablewrap"><table>
<?php foreach ($recent as $r): ?>
<tr><td><a href="<?= $u('/scans/' . (int) $r['id']) ?>"><?= $e($r['name']) ?></a></td><td><?= $e($r['status']) ?></td><td class="muted"><?= $e($r['created_at']) ?></td></tr>
<?php endforeach; ?>
</table></div>
<?php endif; ?>

<p class="muted small" style="margin-top:24px"><?= $t('disclaimer') ?></p>
