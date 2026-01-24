# Deployment Guide - TheHUB

Complete deployment instructions for TheHUB.

---

## Quick Deploy (InfinityFree)

### Step 1: Git pull on server

Via InfinityFree File Manager or SSH:
```bash
cd /htdocs
git pull origin main
```

### Step 2: Run deploy script ONCE

Open in browser:
```
https://thehub.infinityfree.me/deploy-infinityfree.php
```

This automatically:
- Creates `.env` with all credentials
- Configures database
- Activates production mode

### Step 3: DELETE deploy-infinityfree.php

**IMPORTANT!** Delete the file immediately in File Manager after running it.

### Step 4: Run SQL migrations

Go to phpMyAdmin and run migration files from `Tools/migrations/`

### Step 5: Verify

Visit: `https://thehub.infinityfree.me/admin/test-database-connection.php`

---

## Full Deployment Guide

### Pre-Deployment Checklist

#### Requirements
- PHP 8.0 or higher
- MySQL 5.7 or higher / MariaDB 10.3 or higher
- Apache/Nginx web server
- mod_rewrite enabled (Apache) or equivalent (Nginx)
- PHP extensions: mysqli, mbstring, json, fileinfo
- Minimum 50MB disk space
- Minimum 128MB PHP memory_limit

#### Pre-Deployment Tasks
- [ ] Backup existing database
- [ ] Backup existing files
- [ ] Review `CHANGELOG.md` for breaking changes
- [ ] Test in staging environment first
- [ ] Verify PHP version compatibility
- [ ] Check database user permissions

---

### 1. Prepare Database

#### 1.1 Run Database Migrations

Execute migrations from `Tools/migrations/` in order:

```bash
# If using MySQL command line:
mysql -u username -p database_name < Tools/migrations/001_first_migration.sql
```

Or use the admin tool: `https://your-domain.com/admin/migrations.php`

#### 1.2 Verify Tables Created

```sql
-- Check all tables exist
SHOW TABLES;
```

Expected tables include:
- admin_users
- categories
- clubs
- events
- import_history
- import_records
- riders
- results
- series

---

### 2. Deploy Files

#### 2.1 Upload Files

```bash
# Using rsync (recommended):
rsync -avz --exclude='.git' /local/TheHUB/ user@server:/var/www/html/TheHUB/

# Using FTP:
# Upload entire TheHUB directory to your web root
```

#### 2.2 Set File Permissions

```bash
# Set directory permissions
find /var/www/html/TheHUB -type d -exec chmod 755 {} \;

# Set file permissions
find /var/www/html/TheHUB -type f -exec chmod 644 {} \;

# Make uploads directory writable
chmod 775 /var/www/html/TheHUB/uploads
chown www-data:www-data /var/www/html/TheHUB/uploads

# Protect sensitive files
chmod 600 /var/www/html/TheHUB/config.php
```

#### 2.3 Configure Environment

Edit `config.php` with your production settings:

```php
<?php
// Database Configuration
define('DB_HOST', 'your_db_host');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Security
define('SESSION_LIFETIME', 86400); // 24 hours
ini_set('session.cookie_secure', '1'); // HTTPS only
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');

// Uploads
define('UPLOADS_PATH', __DIR__ . '/uploads');
define('MAX_UPLOAD_SIZE', 10485760); // 10MB
```

**IMPORTANT**: Change default admin credentials!

---

### 3. Web Server Configuration

#### 3.1 Apache Configuration

Create/update `.htaccess` in TheHUB root:

```apache
# Enable rewrite engine
RewriteEngine On

# Force HTTPS (recommended for production)
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Protect sensitive files
<FilesMatch "^(config\.php|\.git|composer\.(json|lock)|package(-lock)?\.json)">
    Require all denied
</FilesMatch>

# Set default charset
AddDefaultCharset UTF-8

# Enable compression
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript
</IfModule>

# Browser caching
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 year"
    ExpiresByType image/jpeg "access plus 1 year"
    ExpiresByType image/png "access plus 1 year"
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
</IfModule>
```

#### 3.2 Nginx Configuration

```nginx
server {
    listen 80;
    server_name thehub.example.com;
    root /var/www/html/TheHUB;
    index index.php;

    # Force HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name thehub.example.com;
    root /var/www/html/TheHUB;
    index index.php;

    # SSL Configuration
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    # PHP Processing
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Protect sensitive files
    location ~ /(config\.php|database/|\.git) {
        deny all;
        return 404;
    }

    # Static files caching
    location ~* \.(jpg|jpeg|png|gif|ico|css|js)$ {
        expires 1M;
        add_header Cache-Control "public, immutable";
    }

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
}
```

---

### 4. Post-Deployment Verification

#### 4.1 Access Website

1. Navigate to `https://your-domain.com`
2. Verify landing page loads correctly
3. Check that all links work

#### 4.2 Test Admin Login

1. Go to `https://your-domain.com/admin/login.php`
2. Login with admin credentials
3. Verify dashboard loads

#### 4.3 Security Checks

- [ ] HTTPS is enforced
- [ ] config.php is not accessible via browser
- [ ] database/ directory is not accessible
- [ ] Admin pages require authentication

---

### 5. Performance Optimization

#### 5.1 Enable OpCache (PHP)

Edit `php.ini`:

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=4000
opcache.revalidate_freq=60
```

#### 5.2 Database Optimization

```sql
-- Analyze tables
ANALYZE TABLE events, riders, results, clubs, series;

-- Optimize tables
OPTIMIZE TABLE events, riders, results, clubs, series;
```

---

### 6. Backup Strategy

**Daily Backups**:
```bash
#!/bin/bash
DATE=$(date +%Y%m%d)
BACKUP_DIR="/backups/thehub"

# Database backup
mysqldump -u username -p'password' database_name > $BACKUP_DIR/db_$DATE.sql

# Files backup
tar -czf $BACKUP_DIR/files_$DATE.tar.gz /var/www/html/TheHUB/uploads

# Keep only last 30 days
find $BACKUP_DIR -type f -mtime +30 -delete
```

**Add to crontab**:
```bash
0 2 * * * /path/to/backup-script.sh
```

---

### 7. Troubleshooting

**Issue**: Blank white page
- **Solution**: Check PHP error logs, enable display_errors temporarily
- **Location**: `/var/log/php/error.log` or `/var/log/apache2/error.log`

**Issue**: Database connection failed
- **Solution**: Verify credentials in `config.php`, check database server is running
- **Test**: `mysql -u username -p -h hostname database_name`

**Issue**: Permissions errors on file upload
- **Solution**: `chmod 775 uploads/ && chown www-data:www-data uploads/`

**Issue**: "Demo mode active"
- **Solution**: Run setup script again, verify `config/database.php` exists

---

### 8. Rollback Procedure

If deployment fails:

```bash
# Restore files from backup
rsync -avz /backups/thehub/files_backup/ /var/www/html/TheHUB/

# Restore database
mysql -u username -p database_name < /backups/thehub/db_backup.sql
```

---

### 9. Security Hardening

Use Let's Encrypt for free SSL:

```bash
# Install certbot
apt-get install certbot python3-certbot-apache

# Get certificate
certbot --apache -d thehub.example.com

# Auto-renewal
certbot renew --dry-run
```

Additional security measures:
- [ ] Change default admin password
- [ ] Disable directory listing
- [ ] Set up firewall rules (only allow 80, 443, SSH)
- [ ] Regular security updates

---

## Files Created on Server (gitignored)

These files exist ONLY on the server, not in git:

- `.env` - Database credentials
- `uploads/*` - Uploaded files
- `*.log` - Log files

---

## Success Criteria

Deployment is successful when:
- All pages load without errors
- Admin authentication works
- All CRUD operations functional
- Import system working with history tracking
- No errors in logs
- Security measures in place
- Backups configured

---

*For change history, see `CHANGELOG.md`*
