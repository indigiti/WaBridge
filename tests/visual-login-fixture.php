<?php
declare(strict_types=1);
// CI-only sign-in presentation fixture; never included in release.
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>WaBridge sign-in visual QA</title>
<link rel="stylesheet" href="/wabridge/assets/wabridge-ui-v6.css?v=visual-auth">
</head><body class="auth-page">
<div class="ambient ambient-one"></div><div class="ambient ambient-two"></div><div class="ambient ambient-three"></div>
<div class="site-shell"><header class="site-header">
<a class="brand-lockup" href="/wabridge/" aria-label="WaBridge home">
<span class="brand-mark"><span class="brand-mark-inner">W</span></span>
<span class="brand-copy"><strong>Wa<span>Bridge</span></strong><small>Messaging infrastructure</small></span></a>
<span class="environment-chip"><span class="live-dot"></span> Secure console</span></header>
<main class="auth-main" id="main-content">
<section class="auth-card" aria-labelledby="login-title">
<div class="card-symbol">✦</div><div class="eyebrow">Your secure workspace</div>
<h1 id="login-title">Welcome back.</h1>
<p class="intro">Sign in securely to continue to Media messages.</p>
<form class="auth-form" method="post">
<div class="field"><label for="u">Username</label><div class="input-holder">
<span class="input-glyph">◎</span><input id="u" name="username" autocomplete="username" placeholder="Your username" required>
</div></div>
<div class="field"><label for="p">Password</label><div class="input-holder">
<span class="input-glyph">◇</span><input id="p" type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
</div></div>
<button class="button button-primary button-wide" type="submit">Sign in <span aria-hidden="true">→</span></button>
</form>
<div class="auth-footer"><span class="secure-mark">◈</span> Protected administrator access <span class="footer-separator">·</span> WaBridge</div>
</section></main></div>
<script>
window.addEventListener('load',()=>{
 const b=document.body,c=document.querySelector('.auth-card');
 const form=document.querySelector('.auth-form'),field=document.querySelector('.input-holder input');
 const w=document.documentElement.clientWidth,r=c.getBoundingClientRect();
 b.dataset.loginReady='true';
 b.dataset.loginCard=String(r.width>260);
 b.dataset.loginFont=getComputedStyle(c).fontFamily.includes('WaBridge Inter')?'inter':'fallback';
 b.dataset.loginForm=String(form.getBoundingClientRect().width>230&&field.getBoundingClientRect().right<=r.right+2);
 b.dataset.loginOverflow=String(document.documentElement.scrollWidth>w+5);
 b.dataset.loginCentered=String(Math.abs((r.left+r.right)/2-w/2)<Math.max(80,w*.1));
});
</script></body></html>
