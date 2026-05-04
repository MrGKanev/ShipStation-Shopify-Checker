<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($appTitle) ?></title>
<link rel="stylesheet" href="assets/app.css">
<script>(function(){if(localStorage.getItem('theme')==='dark')document.documentElement.setAttribute('data-theme','dark');})();</script>
</head>
<body>

<div class="login-wrap">
  <div class="login-card">
    <div class="logo"><?= esc($appBrand) ?></div>
    <div class="sub"><?= esc($appTitle) ?></div>

    <?php if ($error): ?>
      <div class="error-msg"><?= esc($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="action" value="login">
      <div class="field">
        <label>Username</label>
        <input type="text" name="username" autofocus autocomplete="username">
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" autocomplete="current-password">
      </div>
      <button class="btn btn-full" type="submit">Sign in</button>
    </form>
  </div>
</div>

</body>
</html>
