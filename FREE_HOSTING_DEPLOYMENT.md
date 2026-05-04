# Digital CV System - Free Hosting Deployment Guide

## Deploy to InfinityFree (or similar free PHP hosts)

### Step 1: Sign Up for Free Hosting
1. Go to [InfinityFree](https://infinityfree.net/) or similar
2. Create a free account
3. Choose a subdomain (e.g., yoursite.infinityfreeapp.com)
4. Wait for account activation (usually instant)

### Step 2: Get Database Credentials
1. Log in to your hosting control panel
2. Go to MySQL Databases
3. Create a new database
4. Create a database user with password
5. Note down:
   - Database host (usually `sqlXXX.infinityfree.com`)
   - Database name
   - Database username
   - Database password

### Step 3: Prepare Your Files

#### Update Database Configuration
Open `php/includes/db.php` and update:

```php
private $host = 'your_db_host';        // e.g., sqlXXX.infinityfree.com
private $dbname = 'your_db_name';     // Your database name
private $username = 'your_db_user';   // Your database username
private $password = 'your_db_pass';   // Your database password
```

#### Disable Debug Mode (Important for production)
Open `php/api/auth.php` and remove/comment out error_log lines:
```php
// Comment out these lines for production
// error_log("Registration data: " . json_encode($data));
// error_log("Token found: " . $token);
// etc.
```

### Step 4: Upload Files

#### Method 1: File Manager (Easier)
1. Log in to hosting control panel
2. Go to File Manager / File Explorer
3. Navigate to `htdocs` folder
4. Upload all files from your project
5. Make sure folder structure is preserved:
   ```
   htdocs/
   ├── css/
   ├── js/
   ├── php/
   │   ├── api/
   │   ├── includes/
   ├── *.html files
   ```

#### Method 2: FTP (Better for many files)
1. Download FileZilla or similar FTP client
2. Get FTP credentials from hosting panel
3. Connect to server
4. Upload all files to `htdocs` folder

### Step 5: Import Database

#### Option A: phpMyAdmin (Recommended)
1. Go to phpMyAdmin from hosting panel
2. Select your database
3. Click "Import" tab
4. Choose `database.sql` from your project
5. Click "Go"

#### Option B: Import from SQL file
1. Open `database.sql` in text editor
2. Copy all SQL commands
3. Paste into SQL query box in phpMyAdmin
4. Execute

### Step 6: Run Database Migration (Optional but Recommended)
Your system has a migration file for the account_status feature. If needed:

1. Open phpMyAdmin
2. Go to SQL tab
3. Run this SQL:
```sql
ALTER TABLE users 
ADD COLUMN account_status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved' AFTER email_verified;

CREATE TABLE IF NOT EXISTS invitations (
    invitation_id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('pending', 'used', 'expired') DEFAULT 'pending',
    expires_at TIMESTAMP NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used_at TIMESTAMP NULL,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE
);
```

### Step 7: Test Your Site
1. Visit your site: `https://your-site.infinityfreeapp.com`
2. Try to access `login.html`
3. Test registration
4. Test login

### Step 8: Create Manager Account
Since the database.sql might not have a manager account:

1. Go to phpMyAdmin
2. Run this SQL:
```sql
INSERT INTO users (email, password_hash, first_name, last_name, role_id, account_status)
VALUES (
    'manager@yourdomain.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'System',
    'Manager',
    (SELECT role_id FROM roles WHERE role_name = 'manager'),
    'approved'
);
```
Password: `demo1234`

## Common Issues & Solutions

### Issue: Database Connection Failed
- Check database credentials in `php/includes/db.php`
- Verify database host (not localhost on free hosting)
- Make sure database user has proper permissions

### Issue: 403/404 Errors
- Check file permissions (should be 755 for folders, 644 for files)
- Make sure you uploaded to `htdocs` folder
- Check `.htaccess` file exists

### Issue: Session Not Working
- Some free hosts have session issues
- Try adding this to top of PHP files:
```php
ini_set('session.save_path', '/tmp');
session_start();
```

### Issue: Email Not Working
- Free hosts often block email
- The invitation system works without email (manual link sharing)
- Email is optional for this system

## Alternative Free Hosting Options

1. **InfinityFree** - Best for PHP
2. **000webhost** - Good alternative
3. **AwardSpace** - Reliable
4. **ByetHost** - No ads
5. **HelioHost** - Community-based

## Security Tips for Free Hosting

1. Change default manager password immediately
2. Don't use the manager account for regular users
3. Keep your database credentials private
4. Regular backups (export database from phpMyAdmin)
5. Monitor for suspicious activity

## Post-Deployment Checklist

- [ ] Update database credentials
- [ ] Remove debug logging
- [ ] Test all features
- [ ] Create manager account
- [ ] Test registration flow
- [ ] Test login/logout
- [ ] Test CV submission
- [ ] Test approval workflow
- [ ] Set up regular backups

## Support

If you encounter issues:
1. Check hosting error logs
2. Enable PHP error display temporarily for debugging
3. Check browser console for JavaScript errors
4. Verify database connection
5. Check file permissions

## Next Steps

After successful deployment:
1. Share your site URL
2. Create initial users
3. Test all functionality
4. Set up backup schedule
5. Monitor performance
