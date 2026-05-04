# Digital CV System

A comprehensive web-based CV management system with role-based access control, QR code integration, and invitation-based registration.

## 🚀 Features

### Core Functionality
- **Role-based authentication** (Student, Supervisor, Examiner, Recruiter, Manager)
- **CV creation and management** with rich content (skills, education, experience, projects)
- **QR code generation** for easy CV sharing
- **Invitation-based registration** for supervisors and examiners
- **HR approval workflow** for recruiter accounts
- **Audit logging** for system monitoring

### User Roles
- **Student**: Create, edit, and submit CVs
- **Supervisor**: Review and evaluate student CVs
- **Examiner**: External CV evaluation with scoring
- **Recruiter**: View approved CVs (requires manager approval)
- **Manager**: Full system administration and user management

### Technical Features
- **RESTful API** with JSON responses
- **Secure session management** with HTTP-only cookies
- **Database-driven permissions** with RBAC system
- **File upload support** for documents and images
- **Responsive design** with modern UI/UX

## 🛠️ Technology Stack

### Frontend
- **HTML5** with semantic markup
- **CSS3** with modern styling
- **Vanilla JavaScript** (no frameworks)
- **Bootstrap** for responsive design
- **QRCode.js** for QR code generation

### Backend
- **PHP 8+** with PDO for database access
- **MySQL/MariaDB** for data storage
- **RESTful API** architecture
- **JWT-like session tokens**

### Database
- **22+ tables** with proper relationships
- **Role-based access control (RBAC)**
- **Audit logging** and activity tracking
- **Migration support** for schema updates

## 📦 Installation

### Local Development

1. **Clone the repository**
   ```bash
   git clone https://github.com/sumeya-ak/Digital-CV-system.git
   cd Digital-CV-system
   ```

2. **Database Setup**
   ```bash
   # Import database schema
   mysql -u root -p < database.sql
   
   # Run migration for account_status feature
   mysql -u root -p < migration_add_auth_features.sql
   ```

3. **Configuration**
   ```bash
   # Update database credentials
   cp php/includes/db.php.example php/includes/db.php
   # Edit db.php with your database details
   ```

4. **Start PHP Server**
   ```bash
   php -S localhost:8000
   ```

5. **Create Manager Account**
   ```bash
   php create_manager.php
   ```

### Production Deployment

#### Free Hosting (InfinityFree)

1. **Create database** in hosting panel
2. **Update database credentials** in `php/includes/db.php`
3. **Import database** using phpMyAdmin
4. **Run migration** for account_status feature
5. **Upload all files** to `htdocs` folder
6. **Create manager account** using SQL

#### Docker Deployment

```bash
docker-compose up -d
```

## 🔐 Default Credentials

**Manager Account:**
- Email: `manager@cvsystem.com`
- Password: `demo1234`

## 📋 API Endpoints

### Authentication
- `POST /php/api/auth.php?action=login` - User login
- `POST /php/api/auth.php?action=register` - User registration
- `POST /php/api/auth.php?action=logout` - User logout
- `GET /php/api/auth.php?action=me` - Current user info

### CV Management
- `GET /php/api/cvs.php?action=list` - List CVs
- `POST /php/api/cvs.php?action=create` - Create CV
- `GET /php/api/cvs.php?action=get&id=X` - Get CV details
- `PUT /php/api/cvs.php?action=update&id=X` - Update CV
- `DELETE /php/api/cvs.php?action=delete&id=X` - Delete CV

### User Management
- `GET /php/api/users.php?action=list` - List users
- `POST /php/api/users.php?action=approve&id=X` - Approve user
- `POST /php/api/users.php?action=reject&id=X` - Reject user

### Invitations
- `GET /php/api/invitations.php?action=list` - List invitations
- `POST /php/api/invitations.php?action=create` - Create invitation
- `GET /php/api/invitations.php?action=validate&token=X` - Validate token

## 🏗️ Database Schema

### Core Tables
- `users` - User accounts with roles
- `roles` - System roles (student, supervisor, examiner, recruiter, manager)
- `permissions` - System permissions
- `role_permissions` - Role-permission mapping
- `cvs` - CV records with status tracking

### Profile Tables
- `student_profiles` - Student-specific information
- `supervisor_profiles` - Supervisor details
- `recruiter_profiles` - Recruiter company information

### Supporting Tables
- `cv_skills` - CV skills and proficiencies
- `cv_education` - Educational background
- `cv_experience` - Work experience
- `cv_projects` - Project portfolio
- `examiner_evaluations` - CV evaluations with scoring
- `invitations` - Registration invitations with tokens
- `audit_logs` - System activity logging
- `notifications` - User notifications

## 🎯 Workflow

### Student Workflow
1. Register (public or via invitation)
2. Create and edit CV
3. Submit CV for review
4. Generate QR code for approved CVs
5. Share CV with recruiters

### Manager Workflow
1. Manage user accounts
2. Approve/reject HR recruiters
3. Create invitations for supervisors/examiners
4. Monitor system activity
5. Generate reports

### Recruiter Workflow
1. Register (requires manager approval)
2. Browse approved CVs
3. Contact qualified candidates

## 🔧 Configuration

### Database Settings
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'digital_cv_system');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
```

### Session Settings
```php
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
```

## 🌟 Key Features Explained

### Invitation System
- Secure token-based registration
- Role-specific invitations (supervisor/examiner)
- Manual link sharing (no email required)
- Expiration and usage tracking

### QR Code Integration
- Automatic QR generation for approved CVs
- Public access links with tracking
- Scan analytics and usage statistics
- Mobile-friendly CV viewing

### HR Approval Workflow
- Recruiter accounts start as "pending"
- Manager approval required for access
- Clear UI feedback for approval status
- Audit trail for all approvals

## 🚀 Deployment Notes

### Security Considerations
- Disable debug mode in production
- Use HTTPS for secure connections
- Regular database backups
- Monitor audit logs

### Performance Optimization
- Database indexing for fast queries
- Efficient file upload handling
- Optimized QR code generation
- Minimal JavaScript dependencies

## 📞 Support

For issues and questions:
1. Check the audit logs for errors
2. Verify database connection
3. Test API endpoints individually
4. Review browser console for JavaScript errors

## 📄 License

This project is open source and available under the [MIT License](LICENSE).

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## 🎉 Acknowledgments

- Built with PHP, MySQL, and vanilla JavaScript
- Responsive design with Bootstrap
- QR code functionality with QRCode.js
- Secure authentication with PHP sessions
