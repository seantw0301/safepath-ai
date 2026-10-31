<h1><?= $t('new_scan') ?></h1>
<p class="muted"><?= $t('new_hint') ?></p>
<form id="f" class="card">
  <label for="name"><?= $t('f_name') ?></label><input id="name" name="name" required maxlength="100">
  <label for="mobility"><?= $t('f_mobility') ?></label>
  <select id="mobility" name="mobility"><?php foreach (['independent', 'walker', 'wheelchair'] as $m): ?><option value="<?= $m ?>"><?= $t("m_$m") ?></option><?php endforeach; ?></select>
  <label for="level"><?= $t('f_level') ?></label>
  <select id="level" name="ltc_level"><?php for ($i = 1; $i <= 8; $i++): ?><option value="<?= $i ?>" <?= $i === 4 ? 'selected' : '' ?>><?= $t('level') ?> <?= $i ?></option><?php endfor; ?></select>
  <label for="status"><?= $t('f_status') ?></label>
  <select id="status" name="ltc_category"><option value="3"><?= $t('s3') ?></option><option value="2"><?= $t('s2') ?></option><option value="1"><?= $t('s1') ?></option></select>
  <label for="video"><?= $t('f_video') ?></label><input id="video" type="file" name="video" accept="video/mp4,video/quicktime">
  <label for="photos"><?= $t('f_photos') ?></label><input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png" multiple>
  <p><button class="btn" type="submit"><?= $t('start') ?></button> <span id="msg" class="muted" role="status"></span></p>
</form>
<script>
const BASE = <?= json_encode(\SafePath\Http\Url::base()) ?>;
document.getElementById('f').addEventListener('submit', async (e) => {
  e.preventDefault();
  const msg = document.getElementById('msg'), els = e.target.elements;
  if (!els.video.files.length && !els['photos[]'].files.length) { msg.textContent = <?= json_encode($t('pick_file')) ?>; return; }
  msg.textContent = <?= json_encode($t('uploading')) ?>;
  const home = new FormData();
  for (const k of ['name', 'mobility', 'ltc_level', 'ltc_category']) home.append(k, els[k].value);
  const h = await (await fetch(BASE + '/api/households', {method: 'POST', body: home})).json();
  if (!h.id) { msg.textContent = <?= json_encode($t('no_upload')) ?> + ' ' + (h.error || 'error'); return; }
  const up = new FormData();
  if (els.video.files.length) up.append('video', els.video.files[0]);
  else for (const file of els['photos[]'].files) up.append('photos[]', file);
  const res = await (await fetch(BASE + '/api/households/' + h.id + '/scans', {method: 'POST', body: up})).json();
  if (!res.id) { msg.textContent = <?= json_encode($t('no_upload')) ?> + ' ' + (res.error || 'error'); return; }
  location.href = BASE + '/scans/' + res.id;
});
</script>
