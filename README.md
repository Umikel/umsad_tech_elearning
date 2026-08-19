# Umsad Tech E-Learning Platform

A professional, full-featured e-learning platform built with PHP, MySQL, and Bootstrap.

## 🚀 Features

### Public Website
- **Homepage** - Hero section with featured courses, statistics, and CTAs
- **Courses Listing** - Browse all published courses with search and filtering
- **Course Details** - Comprehensive course information with reviews and ratings
- **About Page** - Company information and mission
- **Contact Page** - Contact form for inquiries

### Student Dashboard
- **Dashboard** - Overview of enrolled courses and progress
- **My Courses** - View enrolled courses with progress tracking
- **Learn** - Video lessons, materials, and resources
- **Quizzes** - Take course quizzes and track scores
- **Assignments** - Submit assignments and receive feedback
- **Certificates** - Download certificates upon completion
- **Profile** - Manage student profile and settings

### Instructor Dashboard
- **Dashboard** - Course analytics and student statistics
- **Manage Courses** - Create, edit, and publish courses
- **Course Modules** - Organize course content into modules
- **Lessons** - Add video lessons and learning materials
- **Student Management** - Track student progress and performance
- **Grading** - Grade quizzes and assignments
- **Analytics** - View course performance metrics

### Admin Dashboard
- **Dashboard** - Platform-wide statistics and analytics
- **User Management** - Manage students, instructors, and admins
- **Course Management** - Approve and manage all courses
- **Payment Management** - View and manage transactions
- **Settings** - Configure platform settings

### Payment Integration
- **Paystack Integration** - Secure payment processing
- **Payment History** - Track all transactions
- **Invoice Generation** - Automated invoice creation

### Additional Features
- User authentication & authorization
- Secure password hashing (bcrypt)
- Session management with timeout
- File upload handling
- Responsive design (mobile-friendly)
- RESTful API endpoints
- Error handling and logging
- Database transaction support

## 📋 Technology Stack

- **Frontend**: HTML5, CSS3, JavaScript (ES6+), Bootstrap 5.3
- **Backend**: PHP 8.0+
- **Database**: MySQL 5.7+
- **Payment Gateway**: Paystack
- **Web Server**: Apache (XAMPP)

## 🔧 Installation & Setup

### Prerequisites
- XAMPP 7.4+ (with PHP 8.0+ and MySQL)
- Apache web server
- MySQL database

### Step 1: Extract Project
```bash
# Extract the project to XAMPP htdocs
/Applications/XAMPP/xamppfiles/htdocs/umsadtech/
```

### Step 2: Start XAMPP Services
```bash
# Start Apache and MySQL from XAMPP Control Panel
# Or from terminal:
cd /Applications/XAMPP
./xamppfiles/bin/mysql.server start
```

### Step 3: Create Database
1. Open MySQL command line or phpMyAdmin
2. Run the SQL script:
```bash
mysql -u root < database/schema.sql
```

Or via phpMyAdmin:
- Navigate to `http://localhost/phpmyadmin`
- Click "New" to create database
- Name it `umsad_tech_elearning`
- Import `database/schema.sql`

### Step 4: Configure Application
1. Copy and edit configuration file:
```bash
cp includes/config.php.example includes/config.php
```

2. Edit `includes/config.php` with your settings:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); // Your MySQL password
define('DB_NAME', 'umsad_tech_elearning');
```

3. Update Paystack keys:
```php
define('PAYSTACK_PUBLIC_KEY', 'pk_test_your_key_here');
define('PAYSTACK_SECRET_KEY', 'sk_test_your_key_here');
```

### Step 5: Create Upload Directory
```bash
mkdir -p uploads/courses
mkdir -p uploads/materials
mkdir -p uploads/assignments
chmod 755 uploads/
```

### Step 6: Access Application
Open your browser and navigate to:
```
http://localhost/umsadtech
```

## 📁 Project Structure

```
umsadtech/
├── includes/               # Core PHP classes and configs
│   ├── config.php         # Configuration file
│   ├── Database.php       # PDO Database class
│   ├── Auth.php           # Authentication class
│   └── helpers.php        # Helper functions
├── templates/             # Reusable templates
│   ├── header.php         # Page header
│   └── footer.php         # Page footer
├── assets/                # Frontend assets
│   ├── css/
│   │   └── style.css      # Main stylesheet
│   └── js/
│       └── script.js      # Main JavaScript
├── database/              # Database files
│   └── schema.sql         # Database schema
├── uploads/               # User uploads (images, files)
│   ├── courses/
│   ├── materials/
│   └── assignments/
├── admin/                 # Admin dashboard
├── instructor/            # Instructor dashboard
├── student/               # Student dashboard
├── api/                   # API endpoints
├── public/                # Publicly accessible files
├── index.php              # Homepage
├── login.php              # Login page
├── register.php           # Registration page
├── courses.php            # Courses listing
├── logout.php             # Logout action
└── README.md              # This file
```

## 🔐 Security Features

- **Password Hashing**: bcrypt with cost factor 12
- **SQL Injection Prevention**: Prepared statements (PDO)
- **XSS Protection**: HTML escaping and sanitization
- **CSRF Protection**: Session-based token validation
- **Session Management**: Secure session handling with timeout
- **Input Validation**: Server-side validation on all inputs
- **File Upload Security**: Type and size validation

## 💾 Database Schema

Key tables:
- `users` - User accounts (students, instructors, admins)
- `courses` - Course information
- `course_modules` - Course structure
- `course_lessons` - Individual lessons
- `student_enrollments` - Course enrollments
- `student_progress` - Student progress tracking
- `quizzes` - Quiz definitions
- `quiz_attempts` - Quiz attempt history
- `assignments` - Assignment definitions
- `payments` - Payment transactions (Paystack)
- `certificates` - Course certificates

## 🔑 Default Admin Account

After database setup, you can create an admin account:

```sql
INSERT INTO users (email, password, full_name, user_type, is_active) 
VALUES ('admin@example.com', '$2y$12$...', 'Admin User', 'admin', 1);
```

## 📚 API Endpoints

### Authentication
- `POST /api/auth/login` - User login
- `POST /api/auth/register` - User registration
- `POST /api/auth/logout` - User logout

### Courses
- `GET /api/courses` - Get all courses
- `GET /api/courses/:id` - Get course details
- `POST /api/courses` - Create course (instructor only)
- `PUT /api/courses/:id` - Update course
- `DELETE /api/courses/:id` - Delete course

### Payments
- `POST /api/payments/initialize` - Initialize payment
- `POST /api/payments/verify` - Verify payment
- `GET /api/payments/history` - Payment history

## 🔗 Paystack Integration

### Setup Paystack
1. Create Paystack account at https://paystack.com
2. Get your API keys from dashboard
3. Update configuration in `includes/config.php`
4. API endpoints are in `api/payments/`

### Payment Flow
1. Student selects course
2. Click "Enroll" button
3. Redirected to Paystack payment form
4. Complete payment
5. Payment verified
6. Course unlocked
7. Certificate ready after completion

## 📧 Email Configuration

To enable email notifications, configure SMTP in `includes/config.php`:

```php
define('MAIL_HOST', 'smtp.mailtrap.io');
define('MAIL_PORT', 2525);
define('MAIL_USER', 'your_username');
define('MAIL_PASS', 'your_password');
```

## 🐛 Troubleshooting

### Database Connection Error
- Verify MySQL is running
- Check database credentials in `config.php`
- Ensure database `umsad_tech_elearning` exists

### File Upload Issues
- Check `uploads/` directory permissions
- Ensure PHP has write permissions
- Verify upload file size limit in php.ini

### Session Issues
- Check PHP session configuration
- Verify session directory is writable
- Clear browser cookies if login problems persist

### Paystack Payment Issues
- Verify API keys are correct
- Check payment reference format
- Review Paystack documentation

## 🚀 Deployment

### Production Checklist
- [ ] Set `APP_ENV` to 'production'
- [ ] Disable error display in `config.php`
- [ ] Update database credentials
- [ ] Configure HTTPS/SSL
- [ ] Set proper file permissions (644 for files, 755 for dirs)
- [ ] Backup database regularly
- [ ] Monitor error logs
- [ ] Update Paystack to live keys

### Environment Variables
Create `.env` file:
```
DB_HOST=localhost
DB_USER=root
DB_PASS=password
DB_NAME=umsad_tech_elearning
APP_ENV=production
PAYSTACK_PUBLIC_KEY=pk_live_xxxxx
PAYSTACK_SECRET_KEY=sk_live_xxxxx
```

## 📝 Usage Examples

### Register a New User
```bash
curl -X POST http://localhost/umsadtech/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user@example.com",
    "password": "securepassword",
    "full_name": "John Doe",
    "user_type": "student"
  }'
```

### Get Courses
```bash
curl http://localhost/umsadtech/api/courses?category=programming
```

### Initialize Payment
```bash
curl -X POST http://localhost/umsadtech/api/payments/initialize \
  -H "Content-Type: application/json" \
  -d '{
    "course_id": 1,
    "email": "user@example.com",
    "amount": 10000
  }'
```

## 📄 License

This project is proprietary software of Umsad Tech.

## 👥 Support

For support, contact: support@umsadtech.com

## 🔄 Updates & Maintenance

- Check regularly for PHP/MySQL updates
- Perform database backups weekly
- Review error logs monthly
- Update security patches immediately

## 🎓 Getting Started Guide

1. **For Students**:
   - Create account on registration page
   - Browse available courses
   - Enroll in course (pay via Paystack if paid)
   - Start learning through lessons
   - Take quizzes and submit assignments
   - Get certificate upon completion

2. **For Instructors**:
   - Register as instructor
   - Create new course
   - Add modules and lessons
   - Upload video content
   - Create quizzes and assignments
   - Track student progress
   - View course analytics

3. **For Admins**:
   - Access admin dashboard
   - Approve new instructors and courses
   - Manage users and payments
   - View platform analytics
   - Configure system settings

---

**Umsad Tech E-Learning Platform** - Empowering education through technology
