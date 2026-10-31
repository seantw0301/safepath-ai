<?php
$scan = $report['scan'];
$id = (int) $scan['id'];
$busy = !in_array($scan['status'], ['report_ready', 'verified', 'partially_verified', 'failed'], true);
$hazards = $report['hazards'];
$orders = $report['work_orders'];
$clear = array_filter($orders, static fn ($w) => $w['kind'] === 'clear');
$build = array_filter($orders, static fn ($w) => $w['kind'] === 'construct');
$noted = array_filter($hazards, static fn ($h) => $h['status'] === 'noted');
$count = static fn (string $l) => count(array_filter($hazards, static fn ($h) => $h['level'] === $l));
$mobility = ['independent' => 'Walks unaided 自行行走', 'walker' => 'Walker 助行器', 'wheelchair' => 'Wheelchair 輪椅'][$scan['mobility']];
$e = static fn ($s) => htmlspecialchars((string) $s);
$media = static fn (int $sid, int $idx, string $f = 'overlay') => "/media/$sid?f=det/$idx/$f.jpg";
?>
<p class="small"><a href="/">← Home</a></p>
<h1><?= $e($scan['household']) ?></h1>
<p class="muted"><?= $e($mobility) ?> · Scan #<?= $id ?> · <?= $e($scan['status']) ?></p>
<?php if ($scan['simulated']): ?><div class="banner"><b>SIMULATED 模擬資料：</b> photos are AI-generated and the scale is assumed; heights here show the flow, not a real measurement.</div><?php endif; ?>

<?php if ($scan['status'] === 'failed'): ?>
  <div class="banner"><b>This scan failed:</b> <?= $e($scan['error']) ?></div>
<?php elseif ($busy): ?>
  <div class="card"><b>Analysing…</b> <span id="st" class="muted"><?= $e($scan['status']) ?></span></div>
  <script>
  setInterval(async () => {
    const s = await (await fetch('/api/scans/<?= $id ?>')).json();
    document.getElementById('st').textContent = s.status + ' · ' + s.steps + ' steps';
    if (['report_ready', 'verified', 'partially_verified', 'failed'].includes(s.status)) location.reload();
  }, 2000);
  </script>
<?php else: ?>

<div class="card kpi">
  <div><b style="color:var(--red)"><?= $count('red') ?></b><span class="small muted">Red 需處理</span></div>
  <div><b style="color:var(--yellow)"><?= $count('yellow') ?></b><span class="small muted">Yellow 注意</span></div>
  <div><b style="color:var(--grey)"><?= $count('unknown') ?></b><span class="small muted">Unknown 需補拍</span></div>
  <div><b style="color:var(--green)"><?= count($noted) ?></b><span class="small muted">Off the path 只記錄</span></div>
</div>
<?php if (!empty($report['summary']['summary_en'])): ?>
<div class="card"><?= $e($report['summary']['summary_en']) ?><br><span class="muted small"><?= $e($report['summary']['summary_zh']) ?></span></div>
<?php endif; ?>

<h2>1 · What we found 現場發現</h2>
<div class="grid">
<?php foreach ($report['frames'] as $f): ?>
  <div class="card"><img src="<?= $media($id, (int) $f['idx']) ?>" alt="Annotated photo of <?= $e($f['room']) ?>"><b><?= $e($f['room']) ?></b></div>
<?php endforeach; ?>
</div>
<div class="card tablewrap"><table>
<tr><th>Room</th><th>Item</th><th>Verdict</th><th>Measured</th><th>Why</th></tr>
<?php foreach ($hazards as $h): ?>
<tr><td><?= $e($h['room']) ?></td><td><?= $e($h['name']) ?></td><td><span class="pill <?= $e($h['level']) ?>"><?= $e(strtoupper($h['level'])) ?></span></td>
<td><?= $h['value_cm'] !== null ? sprintf('%.1f cm', $h['value_cm']) : '—' ?></td><td><?= $e($h['note']) ?></td></tr>
<?php endforeach; ?>
</table></div>

<?php if ($clear !== []): ?>
<h2>2 · Clear today 清除清單 <span class="muted small">(no construction, no subsidy needed)</span></h2>
<div class="card"><?php foreach ($clear as $w): ?><p style="margin:6px 0">☐ <?= $e($w['text']) ?><br><span class="muted small"><?= $e($w['text_en']) ?></span></p><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($build !== []): ?>
<h2>3 · Work orders 改善工單</h2>
<div class="card"><?php foreach ($build as $w): ?><p style="margin:6px 0"><b><?= $e($w['subsidy_code']) ?></b> <?= $e($w['text']) ?><br><span class="muted small"><?= $e($w['text_en']) ?></span></p><?php endforeach; ?></div>
<?php endif; ?>

<?php if (($s = $report['subsidy']) !== null && $s['items'] !== []): ?>
<h2>4 · LTC 2.0 subsidy draft 補助申請草稿</h2>
<div class="banner"><b>Draft only 僅為草稿：</b> pending assessment and approval by a care professional. Amounts are official caps, not a grant decision.</div>
<div class="card tablewrap"><table>
<tr><th>Code</th><th>Item (official)</th><th>Where</th><th>Cap (NTD)</th><th>Your share</th></tr>
<?php foreach ($s['items'] as $i): ?>
<tr><td><?= $e($i['code']) ?></td><td><?= $e($i['name']) ?></td><td><?= $e($i['location']) ?></td><td><?= number_format($i['cap_ntd']) ?><?= $i['priced'] ? '' : ' / 10 cm' ?></td><td><?= $i['priced'] ? number_format($i['copay_ntd']) : 'by length' ?></td></tr>
<?php endforeach; ?>
</table>
<p class="small">Total of listed caps NT$<?= number_format($s['total_cap_ntd']) ?> of the NT$<?= number_format($s['quota_ntd']) ?> three-year quota · your share at <?= (int) $s['copay_percent'] ?>%: NT$<?= number_format($s['copay_total_ntd']) ?><?= $s['has_per_length_item'] ? ' · per-length items not included' : '' ?></p>
<p class="muted small">Source: <a href="<?= $e($s['source_url']) ?>" rel="noopener"><?= $e($s['source']) ?></a></p>
<p><a class="btn" href="/api/scans/<?= $id ?>/pack.pdf" target="_blank">Open draft PDF 下載申請草稿</a></p></div>
<?php endif; ?>

<?php if ($scan['parent_scan_id'] === null): ?>
<h2>5 · After the fix: re-shoot and verify 重拍驗證</h2>
<?php if ($verify !== null): ?>
  <?php $results = $verify['summary']['verify'] ?? []; $fixedCount = count(array_filter($results, static fn ($r) => $r['status'] === 'verified_fixed')); ?>
  <div class="card"><b><?= $fixedCount ?> of <?= count($results) ?> items verified fixed</b> <span class="muted small"><a href="/scans/<?= (int) $verify['scan']['id'] ?>">re-scan #<?= (int) $verify['scan']['id'] ?></a></span></div>
  <?php foreach ($report['frames'] as $f): ?>
  <?php $after = array_values(array_filter($verify['frames'], static fn ($x) => $x['idx'] === $f['idx']))[0] ?? null; if ($after === null) continue; ?>
  <div class="card"><b><?= $e($f['room']) ?></b><div class="grid" style="margin-top:8px">
    <div><span class="pill red">Before</span><img src="<?= $media($id, (int) $f['idx']) ?>" alt="Before: <?= $e($f['room']) ?>"></div>
    <div><span class="pill green">After</span><img src="<?= $media((int) $verify['scan']['id'], (int) $after['idx']) ?>" alt="After: <?= $e($f['room']) ?>"></div>
  </div></div>
  <?php endforeach; ?>
  <div class="card tablewrap"><table>
  <tr><th>Room</th><th>Item</th><th>Before → After</th><th>Result</th><th>Evidence</th></tr>
  <?php foreach ($results as $r): ?>
  <tr><td><?= $e($r['room']) ?></td><td><?= $e($r['name']) ?></td><td><span class="pill <?= $e($r['before']) ?>"><?= $e($r['before']) ?></span> → <span class="pill <?= $e($r['after']) ?>"><?= $e($r['after']) ?></span></td>
  <td><?= $e(str_replace('_', ' ', $r['status'])) ?></td><td class="small"><?= $e($r['confirmation'] ?? 'Gone from the same spot in the re-shoot.') ?></td></tr>
  <?php endforeach; ?>
  </table></div>
<?php else: ?>
  <form id="v" class="card">
    <?php if ($scan['video_path'] !== null): ?>
      <p class="muted">Walk the same route again.</p>
      <label>Re-shoot video 重拍影片</label><input type="file" name="video" accept="video/mp4,video/quicktime" required>
    <?php else: ?>
      <p class="muted">Take the same photos again, one per room.</p>
      <?php foreach ($report['frames'] as $f): ?>
        <label><?= $e($f['room']) ?></label><input type="file" name="photos[]" accept="image/jpeg,image/png" required>
      <?php endforeach; ?>
    <?php endif; ?>
    <p><button class="btn" type="submit">Verify 開始驗證</button> <span id="vm" class="muted"></span></p>
  </form>
  <script>
  document.getElementById('v').addEventListener('submit', async (e) => {
    e.preventDefault();
    document.getElementById('vm').textContent = 'Uploading…';
    const res = await (await fetch('/api/scans/<?= $id ?>/verify', {method: 'POST', body: new FormData(e.target)})).json();
    if (res.id) location.reload(); else document.getElementById('vm').textContent = 'Could not upload: ' + (res.error || 'error');
  });
  </script>
<?php endif; ?>
<?php else: ?>
<p class="small"><a href="/scans/<?= (int) $scan['parent_scan_id'] ?>">← Back to the original scan, with the before/after comparison</a></p>
<?php endif; ?>

<h2>Agent trace <span class="muted small">how this result was produced</span></h2>
<div class="card tablewrap"><table>
<tr><th>Step</th><th>Tool</th><th>Result</th><th>ms</th></tr>
<?php foreach (array_slice(array_values(array_filter($report['steps'], static fn ($st) => $st['tool'] !== 'sam_segment')), 0, 24) as $i => $st): ?>
<tr><td><?= $e($st['agent'] ?? 'pipeline') ?></td><td><?= $e($st['tool']) ?></td><td><pre><?= $e(mb_strimwidth((string) $st['output_json'], 0, 160, '…')) ?></pre></td><td><?= (int) $st['ms'] ?></td></tr>
<?php endforeach; ?>
</table>
<p class="muted small"><?= count(array_filter($report['steps'], static fn ($st) => $st['tool'] === 'sam_segment')) ?> SAM 3.1 prompt calls not listed.</p></div>
<?php endif; ?>
