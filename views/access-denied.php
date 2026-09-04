<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Access denied · <?= esc($appTitle) ?></title>
<link rel="stylesheet" href="assets/app.css">
<script>(function(){if(localStorage.getItem('theme')==='dark')document.documentElement.setAttribute('data-theme','dark');})();</script>
</head>
<body>

<div class="login-split">
  <div class="login-split-left">
    <div class="login-card access-denied-card">
      <div class="access-denied-icon" aria-hidden="true">×</div>
      <div class="logo">Sorry, you're not part of the team.</div>
      <p class="access-denied-copy">Your Google account is valid, but its Workspace domain is not allowed to access this dashboard.</p>
      <a class="btn btn-full google-login-btn" href="<?= esc($loginPath) ?>">Try another account</a>
    </div>
  </div>
  <div class="login-split-right"<?php if ($loginBgImage): ?> style="background-image:url('<?= esc($loginBgImage) ?>')"<?php endif; ?>></div>
</div>

</body>
</html>
