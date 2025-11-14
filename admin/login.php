<?php
require_once __DIR__ . '/../config.php';

// If already logged in, redirect to dashboard
if (is_admin()) {
    redirect('/admin/dashboard.php');
}

// Handle login
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (login_admin($username, $password)) {
        redirect('/admin/dashboard.php');
    } else {
        $error = 'Felaktigt användarnamn eller lösenord';
    }
}

$pageTitle = 'Logga in';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - TheHUB</title>
    <link rel="stylesheet" href="/assets/gravityseries-theme.css">
</head>
<body class="gs-login-page">

<div class="gs-login-container">
    <div class="gs-login-card">
        <div class="gs-login-header">
            <h1 class="gs-h2">TheHUB Admin</h1>
            <p class="gs-text-secondary">Plattform för cykeltävlingar</p>
        </div>

        <?php if ($error): ?>
            <div class="gs-alert gs-alert-danger gs-mb-md">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="gs-login-form">
            <div class="gs-form-group">
                <label for="username" class="gs-label">Användarnamn</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    class="gs-input" 
                    required 
                    autofocus
                    placeholder="admin"
                >
            </div>

            <div class="gs-form-group">
                <label for="password" class="gs-label">Lösenord</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    class="gs-input" 
                    required
                    placeholder="********"
                >
            </div>

            <button type="submit" class="gs-btn gs-btn-primary gs-btn-block gs-btn-lg">
                Logga in
            </button>
        </form>

        <div class="gs-login-footer">
            <p class="gs-text-sm gs-text-secondary">
                Standard login: <strong>admin / admin</strong>
            </p>
        </div>
    </div>
</div>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    lucide.createIcons();
</script>

</body>
</html>
```

---

## 📝 **EFTER ERSÄTTNING:**

1. **Ladda om:** `https://thehub.infinityfree.me/admin/login.php`
2. **Ska nu se:** Formulär med Username + Password fält
3. **Logga in:** admin / admin
4. **Redirects till:** Dashboard eller riders

---

## 🎯 **OM CSS SAKNAS:**

**Formuläret kommer synas ändå (utan styling), men fungerar!**

**Viktigt är att du ser:**
- Username-fält
- Password-fält  
- Login-knapp

---

## 💡 **ALTERNATIV - BYPASS LOGIN:**

**Om login inte fungerar, gå direkt till:**
```
https://thehub.infinityfree.me/admin/riders.php
```

**Om det säger "not logged in" - gå då till debug:**
```
https://thehub.infinityfree.me/admin/debug.php
