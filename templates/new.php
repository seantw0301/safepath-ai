<h1>New scan</h1>
<p class="muted">Tape an A4 sheet upright on the door frame near the threshold so we can measure. Without it you still get the clearing list, but no heights.</p>
<form id="f" class="card">
  <label>Resident / household name 住戶</label><input name="name" required maxlength="100">
  <label>How do they move around? 行動方式</label>
  <select name="mobility"><option value="independent">Walks unaided 自行行走</option><option value="walker">Walker 助行器</option><option value="wheelchair">Wheelchair 輪椅</option></select>
  <label>LTC care level 長照需要等級 (self-reported)</label>
  <select name="ltc_level"><?php for ($i = 1; $i <= 8; $i++): ?><option value="<?= $i ?>" <?= $i === 4 ? 'selected' : '' ?>>Level <?= $i ?></option><?php endfor; ?></select>
  <label>LTC status 長照身分別</label>
  <select name="ltc_category"><option value="3">第三類 General (30%)</option><option value="2">第二類 (10%)</option><option value="1">第一類 Low income (0%)</option></select>
  <label>Walkthrough video 繞家影片 (MP4/MOV, first 60 seconds)</label><input type="file" name="video" accept="video/mp4,video/quicktime">
  <label>…or photos 或照片 (JPEG/PNG, up to 12)</label><input type="file" name="photos[]" accept="image/jpeg,image/png" multiple>
  <p><button class="btn" type="submit">Start scan</button> <span id="msg" class="muted"></span></p>
</form>
<script>
document.getElementById('f').addEventListener('submit', async (e) => {
  e.preventDefault();
  const msg = document.getElementById('msg'), fd = new FormData(e.target);
  msg.textContent = 'Uploading…';
  const home = new FormData();
  for (const k of ['name', 'mobility', 'ltc_level', 'ltc_category']) home.append(k, fd.get(k));
  const h = await (await fetch('/api/households', {method: 'POST', body: home})).json();
  if (!h.id) { msg.textContent = 'Could not save: ' + (h.error || 'error'); return; }
  const up = new FormData();
  if (e.target.elements.video.files.length) up.append('video', e.target.elements.video.files[0]);
  else for (const file of e.target.elements['photos[]'].files) up.append('photos[]', file);
  const res = await (await fetch('/api/households/' + h.id + '/scans', {method: 'POST', body: up})).json();
  if (!res.id) { msg.textContent = 'Could not upload: ' + (res.error || 'error'); return; }
  location.href = '/scans/' + res.id;
});
</script>
