<?php
use SafePath\Http\I18n;
use SafePath\Http\Url;

$t = I18n::t(...);
$u = Url::to(...);
$e = static fn ($s) => htmlspecialchars((string) $s);
$here = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
?>
<!doctype html>
<html lang="<?= I18n::lang() ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SafePath AI</title>
<style>
:root{--bg:#f6f5f1;--card:#fff;--ink:#1d2321;--muted:#66706c;--line:#e2e0d9;--brand:#1f6f62;--red:#c8303f;--yellow:#a86f00;--green:#1f8a70;--grey:#6f7875}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 -apple-system,"PingFang TC","Noto Sans TC",Segoe UI,sans-serif}
a{color:var(--brand)}header{background:var(--card);border-bottom:1px solid var(--line);padding:12px 24px;display:flex;gap:16px;align-items:center;flex-wrap:wrap}
header a.logo{font-weight:700;text-decoration:none;color:var(--ink);font-size:18px}header .tag{color:var(--muted);font-size:14px}header .lang{margin-left:auto;font-size:14px}
main{max-width:1080px;margin:0 auto;padding:24px 16px 64px}h1{font-size:28px;line-height:1.25;margin:0 0 8px}h2{font-size:19px;margin:32px 0 10px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:14px}
.btn{display:inline-block;background:var(--brand);color:#fff;border:0;border-radius:8px;padding:10px 16px;font-size:15px;text-decoration:none;cursor:pointer}
.muted{color:var(--muted)}.small{font-size:13px}.banner{background:#fff4e0;border:1px solid #edd9ab;border-radius:8px;padding:10px 14px;margin:12px 0;font-size:14px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(300px,100%),1fr));gap:14px}img{max-width:100%;border-radius:8px;display:block}
.steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;counter-reset:s}.steps div{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:12px;font-size:14px}.steps b{display:block;font-size:15px;margin-bottom:2px}.steps b::before{counter-increment:s;content:counter(s) ". ";color:var(--brand)}
table{width:100%;border-collapse:collapse;font-size:14.5px}th,td{text-align:left;padding:8px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--muted);font-weight:600;font-size:13px}
.pill{display:inline-block;border-radius:99px;padding:1px 10px;color:#fff;font-size:12.5px;font-weight:600;white-space:nowrap}.red{background:var(--red)}.yellow{background:var(--yellow)}.green{background:var(--green)}.unknown{background:var(--grey)}
label{display:block;font-weight:600;margin:12px 0 4px;font-size:14px}input[type=text],input[type=file],input:not([type]),select{font:inherit;padding:8px;border:1px solid var(--line);border-radius:6px;width:100%;background:#fff}
.tablewrap{overflow-x:auto}.kpi{display:flex;gap:28px;flex-wrap:wrap}.kpi b{font-size:26px;display:block;line-height:1.2}
pre{white-space:pre-wrap;word-break:break-word;font-size:12.5px;margin:6px 0 0;background:#f4f3ee;padding:8px;border-radius:6px}details summary{cursor:pointer}
.ba{position:relative;overflow:hidden;border-radius:8px;line-height:0;--pos:50%}.ba img{border-radius:0;width:100%}.ba .top{position:absolute;inset:0;clip-path:inset(0 calc(100% - var(--pos)) 0 0)}.ba .bar{position:absolute;top:0;bottom:0;left:var(--pos);width:3px;background:#fff;box-shadow:0 0 4px #0008;pointer-events:none}
.ba .tagL,.ba .tagR{position:absolute;top:8px;line-height:1.5}.ba .tagL{left:8px}.ba .tagR{right:8px}.ba input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:ew-resize;margin:0}
tr.err td{background:#fff4e0}
</style>
</head>
<body>
<header>
  <a class="logo" href="<?= $u('/') ?>">SafePath AI</a><span class="tag"><?= $t('tagline') ?></span>
  <span class="lang"><?php if (I18n::isZh()): ?><a href="<?= $e($here) ?>?lang=en">English</a><?php else: ?><a href="<?= $e($here) ?>?lang=zh">繁體中文</a><?php endif; ?></span>
</header>
<main><?php require $body; ?></main>
</body>
</html>
