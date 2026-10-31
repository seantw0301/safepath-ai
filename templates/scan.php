<?php
use SafePath\Http\I18n;
use SafePath\Http\Url;

$scan = $report['scan'];
$id = (int) $scan['id'];
$zh = I18n::isZh();
$busy = !in_array($scan['status'], ['report_ready', 'verified', 'partially_verified', 'failed'], true);
$hazards = $report['hazards'];
$orders = $report['work_orders'];
$clear = array_filter($orders, static fn ($w) => $w['kind'] === 'clear');
$build = array_filter($orders, static fn ($w) => $w['kind'] === 'construct');
$noted = array_filter($hazards, static fn ($h) => $h['status'] === 'noted');
$count = static fn (string $l) => count(array_filter($hazards, static fn ($h) => $h['level'] === $l));
$media = static fn (int $sid, int $idx, string $f = 'overlay') => Url::to("/media/$sid?f=det/$idx/$f.jpg");
$both = static fn (?string $en, ?string $cn) => $e($zh ? ($cn ?: $en) : ($en ?: $cn)) . '<br><span class="muted small">' . $e($zh ? $en : $cn) . '</span>';
$isSam = static fn ($st) => in_array($st['tool'], ['sam_segment', 'sam_track'], true);
$isVideo = $scan['video_path'] !== null;
?>
<p class="small"><a href="<?= $u('/') ?>">← <?= $t('home') ?></a></p>
<h1><?= $e($scan['household']) ?></h1>
<p class="muted"><?= $t('m_' . $scan['mobility']) ?> · #<?= $id ?></p>
<?php if ($scan['simulated']): ?><div class="banner"><?= $t('sim_scan') ?></div><?php endif; ?>

<?php if ($scan['status'] === 'failed'): ?>
  <div class="banner"><b><?= $t('failed') ?></b> <?= $e($scan['error']) ?></div>
<?php elseif ($busy): ?>
  <div class="card"><b><?= $t('analysing') ?></b> <span id="st" class="muted" role="status"><?= $e($scan['status']) ?></span></div>
  <script>
  setInterval(async () => {
    const s = await (await fetch(<?= json_encode($u("/api/scans/$id")) ?>)).json();
    document.getElementById('st').textContent = s.status + ' · ' + s.steps;
    if (['report_ready', 'verified', 'partially_verified', 'failed'].includes(s.status)) location.reload();
  }, 2000);
  </script>
<?php else: ?>

<div class="card kpi">
  <div><b style="color:var(--red)"><?= $count('red') ?></b><span class="small muted"><?= $t('red') ?></span></div>
  <div><b style="color:var(--yellow)"><?= $count('yellow') ?></b><span class="small muted"><?= $t('yellow') ?></span></div>
  <div><b style="color:var(--grey)"><?= $count('unknown') ?></b><span class="small muted"><?= $t('unknown') ?></span></div>
  <div><b style="color:var(--green)"><?= count($noted) ?></b><span class="small muted"><?= $t('noted') ?></span></div>
</div>
<?php if (!empty($report['summary']['summary_en'])): ?>
<div class="card"><?= $both($report['summary']['summary_en'], $report['summary']['summary_zh']) ?></div>
<?php endif; ?>

<h2><?= $t('found') ?></h2>
<div class="grid">
<?php foreach ($report['frames'] as $f): ?>
  <div class="card"><img src="<?= $e($media($id, (int) $f['idx'])) ?>" alt="<?= $e($f['room']) ?>"><b><?= $e($f['room']) ?></b></div>
<?php endforeach; ?>
</div>
<div class="card tablewrap"><table>
<tr><th><?= $t('room') ?></th><th><?= $t('item') ?></th><th><?= $t('verdict') ?></th><th><?= $t('measured') ?></th><th><?= $t('why') ?></th></tr>
<?php foreach ($hazards as $h): ?>
<tr><td><?= $e($h['room']) ?></td><td><?= $e($h['name']) ?></td><td><span class="pill <?= $e($h['level']) ?>"><?= $e(strtoupper($h['level'])) ?></span></td>
<td><?= $h['value_cm'] !== null ? sprintf('%.1f cm', $h['value_cm']) : '—' ?></td><td><?= $both($h['note_en'], $h['note']) ?></td></tr>
<?php endforeach; ?>
</table></div>

<?php if ($clear !== []): ?>
<h2><?= $t('clear') ?> <span class="muted small">(<?= $t('clear_d') ?>)</span></h2>
<div class="card"><?php foreach ($clear as $w): ?><p style="margin:6px 0">☐ <?= $both($w['text_en'], $w['text']) ?></p><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($build !== []): ?>
<h2><?= $t('orders') ?></h2>
<div class="card"><?php foreach ($build as $w): ?><p style="margin:6px 0"><b><?= $e($w['subsidy_code']) ?></b> <?= $both($w['text_en'], $w['text']) ?></p><?php endforeach; ?></div>
<?php endif; ?>

<?php if (($s = $report['subsidy']) !== null && $s['items'] !== []): ?>
<h2><?= $t('draft') ?></h2>
<div class="banner"><?= $t('draft_warn') ?></div>
<div class="card tablewrap"><table>
<tr><th><?= $t('code') ?></th><th><?= $t('official') ?></th><th><?= $t('where') ?></th><th><?= $t('cap') ?></th><th><?= $t('share') ?></th></tr>
<?php foreach ($s['items'] as $i): ?>
<tr><td><?= $e($i['code']) ?></td><td><?= $e($i['name']) ?></td><td><?= $e($i['location']) ?></td><td><?= number_format($i['cap_ntd']) ?><?= $i['priced'] ? '' : ' / 10 cm' ?></td><td><?= $i['priced'] ? number_format($i['copay_ntd']) : $t('by_length') ?></td></tr>
<?php endforeach; ?>
</table>
<p class="small"><?= sprintf($t('total'), number_format($s['total_cap_ntd']), number_format($s['quota_ntd']), (int) $s['copay_percent'], number_format($s['copay_total_ntd'])) ?><?= $s['has_per_length_item'] ? ' · ' . $t('per_length') : '' ?></p>
<p class="muted small"><?= $t('source') ?>: <a href="<?= $e($s['source_url']) ?>" rel="noopener"><?= $e($s['source']) ?></a></p>
<p><a class="btn" href="<?= $u("/api/scans/$id/pack.pdf") ?>" target="_blank"><?= $t('open_pdf') ?></a></p></div>
<?php endif; ?>

<?php if ($scan['parent_scan_id'] === null): ?>
<h2><?= $t('verify') ?></h2>
<?php if ($verify !== null): ?>
  <?php $results = $verify['summary']['verify'] ?? []; $fixedCount = count(array_filter($results, static fn ($r) => $r['status'] === 'verified_fixed')); $vid = (int) $verify['scan']['id']; ?>
  <div class="card"><b><?= sprintf($t('verified_n'), $fixedCount, count($results)) ?></b> <span class="muted small"><a href="<?= $u("/scans/$vid") ?>"><?= $t('rescan') ?> #<?= $vid ?></a></span>
  <?php if (!empty($verify['summary']['summary_en'])): ?><p style="margin:8px 0 0"><?= $both($verify['summary']['summary_en'], $verify['summary']['summary_zh']) ?></p><?php endif; ?></div>
  <?php if (!$isVideo): ?>
  <div class="grid">
  <?php foreach ($report['frames'] as $f): ?>
    <?php $after = array_values(array_filter($verify['frames'], static fn ($x) => (int) $x['idx'] === (int) $f['idx']))[0] ?? null; if ($after === null) continue; ?>
    <div class="card"><b><?= $e($f['room']) ?></b> <span class="muted small"><?= $t('drag') ?></span>
      <div class="ba" style="margin-top:8px">
        <img src="<?= $e($media($vid, (int) $after['idx'])) ?>" alt="<?= $t('after') ?>: <?= $e($f['room']) ?>">
        <img class="top" src="<?= $e($media($id, (int) $f['idx'])) ?>" alt="<?= $t('before') ?>: <?= $e($f['room']) ?>">
        <div class="bar"></div>
        <span class="pill red tagL"><?= $t('before') ?></span><span class="pill green tagR"><?= $t('after') ?></span>
        <input type="range" min="0" max="100" value="50" aria-label="<?= $t('drag') ?>: <?= $e($f['room']) ?>" oninput="this.parentElement.style.setProperty('--pos', this.value + '%')">
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="card tablewrap"><table>
  <tr><th><?= $t('room') ?></th><th><?= $t('item') ?></th><th><?= $t('before') ?> → <?= $t('after') ?></th><th><?= $t('result') ?></th><th><?= $t('evidence') ?></th></tr>
  <?php foreach ($results as $r): ?>
  <tr><td><?= $e($r['room']) ?></td><td><?= $e($r['name']) ?></td><td><span class="pill <?= $e($r['before']) ?>"><?= $e($r['before']) ?></span> → <span class="pill <?= $e($r['after']) ?>"><?= $e($r['after']) ?></span></td>
  <td><?= $e(str_replace('_', ' ', $r['status'])) ?></td><td class="small"><?= $e($r['confirmation'] ?? ($r['status'] === 'verified_fixed' ? $t($isVideo ? 'gone_video' : 'gone') : '')) ?></td></tr>
  <?php endforeach; ?>
  </table></div>
<?php elseif (\SafePath\Http\Access::liveUploads() && !$scan['is_demo']): ?>
  <form id="v" class="card">
    <?php if ($isVideo): ?>
      <p class="muted"><?= $t('same_route') ?></p>
      <label for="rv"><?= $t('reshoot') ?></label><input id="rv" type="file" name="video" accept="video/mp4,video/quicktime" required>
    <?php else: ?>
      <p class="muted"><?= $t('same_photos') ?></p>
      <?php foreach ($report['frames'] as $i => $f): ?>
        <label for="rp<?= $i ?>"><?= $e($f['room']) ?></label><input id="rp<?= $i ?>" type="file" name="photos[]" accept="image/jpeg,image/png" required>
      <?php endforeach; ?>
    <?php endif; ?>
    <p><button class="btn" type="submit"><?= $t('verify_btn') ?></button> <span id="vm" class="muted" role="status"></span></p>
  </form>
  <script>
  document.getElementById('v').addEventListener('submit', async (e) => {
    e.preventDefault();
    const m = document.getElementById('vm');
    m.textContent = <?= json_encode($t('uploading')) ?>;
    const res = await (await fetch(<?= json_encode($u("/api/scans/$id/verify")) ?>, {method: 'POST', body: new FormData(e.target)})).json();
    if (res.id) location.reload(); else m.textContent = <?= json_encode($t('no_upload')) ?> + ' ' + (res.error || 'error');
  });
  </script>
<?php endif; ?>
<?php else: ?>
<p class="small"><a href="<?= $u('/scans/' . (int) $scan['parent_scan_id']) ?>">← <?= $t('back') ?></a></p>
<?php endif; ?>

<?php $agentSteps = array_values(array_filter($report['steps'], static fn ($st) => $st['agent'] === 'case_agent' || $st['agent'] === 'verify')); ?>
<?php if ($agentSteps !== []): ?>
<h2><?= $t('trace') ?> <span class="muted small">(<?= $t('trace_d') ?>)</span></h2>
<div class="card tablewrap"><table>
<tr><th>#</th><th><?= $t('tool') ?></th><th><?= $t('out') ?></th></tr>
<?php foreach ($agentSteps as $n => $st): ?>
<?php $isError = str_contains((string) $st['output_json'], '"error"'); ?>
<tr class="<?= $isError ? 'err' : '' ?>"><td><?= $n + 1 ?></td><td><b><?= $e($st['tool']) ?></b><?= $isError ? '<br><span class="small">' . $t('error_step') . '</span>' : '' ?></td>
<td><details><summary class="small"><?= $e(mb_strimwidth((string) $st['output_json'], 0, 90, '…')) ?></summary>
<?php if (!in_array($st['input_json'], [null, '[]', '{}'], true)): ?><span class="small muted"><?= $t('in') ?></span><pre><?= $e(json_encode(json_decode((string) $st['input_json']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre><?php endif; ?>
<span class="small muted"><?= $t('out') ?></span><pre><?= $e(json_encode(json_decode((string) $st['output_json']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details></td></tr>
<?php endforeach; ?>
</table></div>
<?php endif; ?>
<p class="muted small"><?= sprintf($t('sam_calls'), count(array_filter($report['steps'], $isSam))) ?></p>
<?php endif; ?>
