<?php
use SafePath\Http\I18n;
use SafePath\Http\Url;

$t = I18n::t(...);
$u = Url::to(...);
$e = static fn ($s) => htmlspecialchars((string) $s);
$here = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
require __DIR__ . '/icons.php';
?>
<!doctype html>
<html lang="<?= I18n::lang() ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SafePath AI</title>
<style>
:root{--bg:#f3f6f5;--card:#fff;--ink:#1d2321;--muted:#5f6a66;--line:#e1e6e3;--brand:#1f6f62;--brand2:#2a9d8f;--red:#c8303f;--yellow:#a86f00;--green:#1f8a70;--grey:#6f7875;--redbg:#fdecee;--yellowbg:#fff4dc;--greenbg:#e5f5ef;--greybg:#eef0ef;--bluebg:#e7f0fb;--blue:#2f6fb5;--purplebg:#f1ebfa;--purple:#6b4bb0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 -apple-system,"PingFang TC","Noto Sans TC",Segoe UI,sans-serif}
a{color:var(--brand)}header{background:linear-gradient(120deg,#17594f,var(--brand) 45%,var(--brand2));color:#fff;padding:12px 24px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;box-shadow:0 2px 10px #1f6f6233}
header a.logo{font-weight:700;text-decoration:none;color:#fff;font-size:19px;display:inline-flex;align-items:center;gap:8px}header a.logo .ic{width:26px;height:26px}header .tag{color:#d8efe9;font-size:14px}header .lang{margin-left:auto;font-size:14px}header .lang a{color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px;border:1px solid #ffffff66;border-radius:99px;padding:3px 12px}header .lang a:hover{background:#ffffff22}
.ic{width:1.15em;height:1.15em;vertical-align:-.2em;flex:none}h2{display:flex;align-items:center;gap:8px;flex-wrap:wrap}h2>.ic{color:var(--brand);width:22px;height:22px}
main{max-width:1080px;margin:0 auto;padding:24px 16px 64px}h1{font-size:28px;line-height:1.25;margin:0 0 8px}h2{font-size:19px;margin:32px 0 10px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:14px;box-shadow:0 1px 3px #0000000d}a.card{transition:transform .15s,box-shadow .15s}a.card:hover,a.card:focus-visible{transform:translateY(-2px);box-shadow:0 6px 18px #1f6f6226}
.btn{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(120deg,var(--brand),var(--brand2));box-shadow:0 2px 6px #1f6f6240;color:#fff;border:0;border-radius:8px;padding:10px 16px;font-size:15px;text-decoration:none;cursor:pointer}
.muted{color:var(--muted)}.small{font-size:13px}.banner{background:var(--yellowbg);border:1px solid #edd9ab;border-left:4px solid var(--yellow);border-radius:8px;padding:10px 14px;margin:12px 0;font-size:14px;display:flex;gap:10px;align-items:flex-start}.banner>.ic{color:var(--yellow);margin-top:3px}
.hero{background:linear-gradient(135deg,#e3f3ef,#eef4fb);border:1px solid #d3e8e2;border-radius:16px;padding:28px 24px;margin-bottom:8px}.hero h1{font-size:30px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(300px,100%),1fr));gap:14px}img{max-width:100%;border-radius:8px;display:block}
.steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;counter-reset:s}.steps div{background:var(--card);border:1px solid var(--line);border-top:4px solid var(--c);border-radius:12px;padding:14px 12px 12px;font-size:14px}.steps b{display:block;font-size:15px;margin-bottom:2px}.steps b::before{counter-increment:s;content:counter(s) ". ";color:var(--c)}
.steps .dot,.tile{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;background:var(--bgc);color:var(--c);margin-bottom:8px}.steps .dot .ic,.tile .ic{width:21px;height:21px}
.c1{--c:var(--blue);--bgc:var(--bluebg)}.c2{--c:var(--purple);--bgc:var(--purplebg)}.c3{--c:var(--yellow);--bgc:var(--yellowbg)}.c4{--c:var(--brand);--bgc:#e3f3ef}.c5{--c:var(--green);--bgc:var(--greenbg)}.cr{--c:var(--red);--bgc:var(--redbg)}.cg{--c:var(--grey);--bgc:var(--greybg)}
.demo{display:flex;gap:12px;align-items:center}.demo .tile{margin:0}.demo .go{margin-left:auto;color:var(--brand)}
table{width:100%;border-collapse:collapse;font-size:14.5px}th,td{text-align:left;padding:8px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--muted);font-weight:600;font-size:13px}
.pill{display:inline-block;border-radius:99px;padding:1px 10px;color:#fff;font-size:12.5px;font-weight:600;white-space:nowrap}.red{background:var(--red)}.yellow{background:var(--yellow)}.green{background:var(--green)}.unknown{background:var(--grey)}
label{display:block;font-weight:600;margin:12px 0 4px;font-size:14px}input[type=text],input[type=file],input:not([type]),select{font:inherit;padding:8px;border:1px solid var(--line);border-radius:6px;width:100%;background:#fff}
.tablewrap{overflow-x:auto}.kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;padding:10px}.kpi>div{background:var(--bgc);border-radius:10px;padding:10px 12px;display:flex;gap:10px;align-items:center}.kpi>div>.ic{color:var(--c);width:26px;height:26px}.kpi b{font-size:26px;display:block;line-height:1.2;color:var(--c)}
.pill .ic{width:13px;height:13px;margin-right:3px;vertical-align:-.15em}tr:hover td{background:#f8faf9}th{background:#f6f8f7}
pre{white-space:pre-wrap;word-break:break-word;font-size:12.5px;margin:6px 0 0;background:#f4f3ee;padding:8px;border-radius:6px}details summary{cursor:pointer}
.ba{position:relative;overflow:hidden;border-radius:8px;line-height:0;--pos:50%}.ba img{border-radius:0;width:100%}.ba .top{position:absolute;inset:0;clip-path:inset(0 calc(100% - var(--pos)) 0 0)}.ba .bar{position:absolute;top:0;bottom:0;left:var(--pos);width:3px;background:#fff;box-shadow:0 0 4px #0008;pointer-events:none}
.ba .tagL,.ba .tagR{position:absolute;top:8px;line-height:1.5}.ba .tagL{left:8px}.ba .tagR{right:8px}.ba input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:ew-resize;margin:0}
tr.err td{background:#fff4e0}.figure{padding:8px}.video{position:relative;aspect-ratio:16/9;border-radius:8px;overflow:hidden;background:#000}.video iframe{position:absolute;inset:0;width:100%;height:100%;border:0}.figure img{width:100%;height:auto}.okc{color:var(--green)}
</style>
</head>
<body>
<header>
  <?= $sprite ?>
  <a class="logo" href="<?= $u('/') ?>"><?= $icon('shield') ?>SafePath AI</a><span class="tag"><?= $t('tagline') ?></span>
  <span class="lang"><?php if (I18n::isZh()): ?><a href="<?= $e($here) ?>?lang=en"><?= $icon('globe') ?>English</a><?php else: ?><a href="<?= $e($here) ?>?lang=zh"><?= $icon('globe') ?>繁體中文</a><?php endif; ?></span>
</header>
<main><?php require $body; ?></main>
</body>
</html>
