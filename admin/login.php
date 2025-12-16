<?php
require_once __DIR__ . '/../config.php';

// If already logged in, redirect to homepage
if (is_admin()) {
 redirect('/');
}

// Handle login
$error = '';

// Check for session timeout message
if (isset($_GET['timeout'])) {
 $error = 'Din session har gått ut. Vänligen logga in igen.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 $username = $_POST['username'] ?? '';
 $password = $_POST['password'] ?? '';

 // Check rate limiting before attempting login
 if (isLoginRateLimited($username)) {
 $error = 'För många inloggningsförsök. Vänta 15 minuter och försök igen.';
 } elseif (login_admin($username, $password)) {
 // All users go to homepage after login
 redirect('/');
 } else {
 $error = 'Felaktigt användarnamn eller lösenord';
 }
}

$pageTitle = 'Logga in';
?>
<!DOCTYPE html>
<html lang="sv" data-theme="light">
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title><?= $pageTitle ?> - TheHUB</title>
 <link rel="stylesheet" href="/assets/css/reset.css">
 <link rel="stylesheet" href="/assets/css/tokens.css">
 <link rel="stylesheet" href="/assets/css/theme.css">
 <link rel="stylesheet" href="/assets/css/components.css">
 <style>
  .login-page {
   min-height: 100vh;
   display: flex;
   align-items: center;
   justify-content: center;
   background: var(--color-bg-page);
   padding: var(--space-lg);
  }
  .login-card {
   background: var(--color-bg-surface);
   border-radius: var(--radius-lg);
   box-shadow: var(--shadow-lg);
   padding: var(--space-2xl);
   width: 100%;
   max-width: 400px;
  }
  .login-header {
   text-align: center;
   margin-bottom: var(--space-xl);
  }
  .login-header h1 {
   font-size: 1.75rem;
   font-weight: 700;
   color: var(--color-text-primary);
   margin-bottom: var(--space-xs);
  }
  .login-header p {
   color: var(--color-text-secondary);
   font-size: var(--text-sm);
  }
  .login-form .form-group {
   margin-bottom: var(--space-md);
  }
  .login-form .form-label {
   display: block;
   font-size: var(--text-sm);
   font-weight: 500;
   color: var(--color-text-primary);
   margin-bottom: var(--space-xs);
  }
  .login-form .form-input {
   width: 100%;
   padding: var(--space-sm) var(--space-md);
   font-size: 1rem;
   border: 1px solid var(--color-border);
   border-radius: var(--radius-md);
   background: var(--color-bg-surface);
   color: var(--color-text-primary);
   transition: border-color 0.2s, box-shadow 0.2s;
  }
  .login-form .form-input:focus {
   outline: none;
   border-color: var(--color-accent);
   box-shadow: 0 0 0 3px rgba(97, 206, 112, 0.15);
  }
  .login-form .form-input::placeholder {
   color: var(--color-text-muted);
  }
  .login-btn {
   width: 100%;
   padding: var(--space-sm) var(--space-lg);
   font-size: 1rem;
   font-weight: 600;
   color: white;
   background: var(--color-accent);
   border: none;
   border-radius: var(--radius-md);
   cursor: pointer;
   transition: background-color 0.2s, transform 0.1s;
   margin-top: var(--space-md);
  }
  .login-btn:hover {
   background: var(--color-accent-hover, #4db85c);
  }
  .login-btn:active {
   transform: scale(0.98);
  }
  .login-error {
   background: rgba(239, 68, 68, 0.1);
   color: var(--color-danger);
   padding: var(--space-sm) var(--space-md);
   border-radius: var(--radius-md);
   font-size: var(--text-sm);
   margin-bottom: var(--space-lg);
   border: 1px solid rgba(239, 68, 68, 0.2);
  }
 </style>
</head>
<body class="login-page">

<div class="login-card">
 <div class="login-header">
  <h1>TheHUB Admin</h1>
  <p>Plattform för cykeltävlingar</p>
 </div>

 <?php if ($error): ?>
  <div class="login-error">
  <?= htmlspecialchars($error) ?>
  </div>
 <?php endif; ?>

 <form method="POST" class="login-form">
  <div class="form-group">
  <label for="username" class="form-label">Användarnamn</label>
  <input
   type="text"
   id="username"
   name="username"
   class="form-input"
   required
   autofocus
   autocomplete="username"
  >
  </div>

  <div class="form-group">
  <label for="password" class="form-label">Lösenord</label>
  <input
   type="password"
   id="password"
   name="password"
   class="form-input"
   required
   autocomplete="current-password"
  >
  </div>

  <button type="submit" class="login-btn">
  Logga in
  </button>
 </form>
</div>

</body>
</html>
