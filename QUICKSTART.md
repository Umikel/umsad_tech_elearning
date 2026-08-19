# Umsad Tech E-Learning Platform - Quick Start Guide

## ⚡ Quick Installation (5 minutes)

### 1. Database Setup

**Option A: Using phpMyAdmin (Recommended)**
1. Open phpMyAdmin: http://localhost/phpmyadmin
2. Click "New" (top left)
3. Name: `umsad_tech_elearning`
4. Click "Create"
5. Click "Import" tab
6. Browse to `database/schema.sql` in this project
7. Click "Go"

**Option B: Using MySQL Command Line**
```bash
mysql -u root -p
CREATE DATABASE umsad_tech_elearning;
USE umsad_tech_elearning;
source /Applications/XAMPP/xamppfiles/htdocs/umsadtech/database/schema.sql;
exit;
```

### 2. Configuration

```bash
# Copy the environment file
cp .env.example .env

# Edit .env with your settings
nano .env
```

Update these values in `.env`:
```
DB_HOST=localhost
DB_USER=root
DB_PASS=          # Leave blank if no password
DB_NAME=umsad_tech_elearning
APP_URL=http://localhost/umsadtech
PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
PAYSTACK_SECRET_KEY=sk_test_xxxxx
```

### 3. Create Upload Directories

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/umsadtech
mkdir -p uploads/courses
mkdir -p uploads/materials
mkdir -p uploads/assignments
chmod -R 755 uploads/
```

### 4. Start Services

Open XAMPP Control Panel and start:
- Apache
- MySQL

Or use terminal:
```bash
cd /Applications/XAMPP
./xamppfiles/bin/mysql.server start
# Apache is usually running
```

### 5. Access Application

Open browser: **http://localhost/umsadtech**

## 🔐 Create First Admin Account

### Option 1: Manually via phpMyAdmin
1. Go to phpMyAdmin → `umsad_tech_elearning` database → `users` table
2. Click "Insert"
3. Fill in:
   - email: `admin@example.com`
   - password: `$2y$12$R9h7cIPz0gi.URNNW3kh2OPST9/PgBkqquzi.Ss7KIUgO2t0jKMm6` (bcrypt hash of "admin123")
   - full_name: `Admin User`
   - user_type: `admin`
   - is_active: `1`
4. Click "Go"

### Option 2: Using SQL Command
```sql
INSERT INTO users (email, password, full_name, user_type, is_active) 
VALUES ('admin@example.com', '$2y$12$R9h7cIPz0gi.URNNW3kh2OPST9/PgBkqquzi.Ss7KIUgO2t0jKMm6', 'Admin User', 'admin', 1);
```

**Login Details:**
- Email: `admin@example.com`
- Password: `admin123`

## 🎯 Key URLs

After installation, access these pages:

| URL | Purpose |
|-----|---------|
| http://localhost/umsadtech | Homepage |
| http://localhost/umsadtech/login.php | User Login |
| http://localhost/umsadtech/register.php | User Registration |
| http://localhost/umsadtech/courses.php | Browse Courses |
| http://localhost/umsadtech/student/dashboard.php | Student Dashboard |
| http://localhost/umsadtech/instructor/dashboard.php | Instructor Dashboard |
| http://localhost/umsadtech/admin/dashboard.php | Admin Dashboard |
| http://localhost/phpmyadmin | Database Management |

## 📁 Project Structure

```
umsadtech/
├── includes/                      # Core PHP files
│   ├── config.php                # Database & App config
│   ├── Database.php              # PDO Database class
│   ├── Auth.php                  # Authentication class
│   └── helpers.php               # Helper functions
├── templates/                     # Page templates
│   ├── header.php                # Header (included on all pages)
│   └── footer.php                # Footer (included on all pages)
├── assets/                        # Frontend assets
│   ├── css/style.css             # Main stylesheet
│   └── js/script.js              # Main JavaScript
├── database/                      # Database files
│   └── schema.sql                # Database schema (import this)
├── uploads/                       # File uploads
│   ├── courses/                  # Course images
│   ├── materials/                # Course materials
│   └── assignments/              # Student assignments
├── api/                           # API endpoints
│   └── payments/
│       ├── initialize.php        # Payment initialization
│       └── verify.php            # Payment verification
├── admin/                         # Admin pages
│   └── dashboard.php             # Admin dashboard
├── instructor/                    # Instructor pages
│   └── dashboard.php             # Instructor dashboard
├── student/                       # Student pages
│   ├── dashboard.php             # Student dashboard
│   └── course-lessons.php        # Lesson viewer
├── index.php                      # Homepage
├── login.php                      # Login page
├── register.php                   # Registration page
├── courses.php                    # Courses listing
├── course-detail.php             # Course details
├── logout.php                     # Logout action
├── .env.example                   # Environment template
├── README.md                      # Full documentation
└── QUICKSTART.md                 # This file
```

## 🔧 Common Tasks

### Create a New Course (As Instructor)

1. Login as instructor or admin
2. Go to Instructor Dashboard
3. Click "Create Course"
4. Fill in:
   - Title
   - Description
   - Category
   - Price
   - Course Image (upload)
5. Click "Create"
6. Add Modules
7. Add Lessons to each module
8. Publish course

### Add Course Content

1. Course → Modules
2. Add Module (e.g., "Module 1: Introduction")
3. Add Lessons to Module
4. For each lesson:
   - Title
   - Description
   - Video URL (YouTube link or upload)
   - Duration
   - Learning materials (PDFs, etc.)

### Create Quiz

1. Course → Lessons → Select Lesson
2. Click "Add Quiz"
3. Create Questions:
   - Question text
   - Question type (multiple choice, true/false)
   - Answer options
   - Mark correct answer
4. Set passing score (default 70%)
5. Publish

### Student Enrollment

1. Student registers
2. Browses courses
3. Clicks "Enroll Now"
4. If paid course:
   - Paystack payment form appears
   - Student completes payment
   - Auto-enrolled after verification
5. If free course:
   - Immediate enrollment
6. Can now access lessons

## 🐛 Troubleshooting

### Database Connection Error
```
Error: Database Connection Error
```
**Solution:**
- Check MySQL is running
- Verify credentials in `.env`
- Ensure database exists: `umsad_tech_elearning`

### Page Not Found (404)
```
Not Found: /umsadtech/page.php
```
**Solution:**
- Check file exists in project root
- Verify Apache is serving `/xamppfiles/htdocs/`
- Clear browser cache

### Permission Denied (File Upload)
```
Error: Failed to upload file
```
**Solution:**
```bash
chmod -R 755 /Applications/XAMPP/xamppfiles/htdocs/umsadtech/uploads/
sudo chown -R nobody:nogroup uploads/
```

### Paystack Payment Not Working
**Solution:**
1. Get test keys from https://paystack.com/dashboard
2. Update in `.env`:
   ```
   PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
   PAYSTACK_SECRET_KEY=sk_test_xxxxx
   ```
3. Use test card: 4111 1111 1111 1111
4. Use any future date and any CVC

## 📝 Test Accounts

Use these to test different user types:

| Role | Email | Password |
|------|-------|----------|
| Student | student@example.com | password123 |
| Instructor | instructor@example.com | password123 |
| Admin | admin@example.com | admin123 |

Create these manually in phpMyAdmin or through registration.

## 🚀 Next Steps

1. **Customize branding:**
   - Update logo in navbar (header.php)
   - Update company name in config
   - Customize colors in CSS

2. **Add sample courses:**
   - Create as admin/instructor
   - Add modules and lessons
   - Upload video content

3. **Configure Paystack:**
   - Get live API keys
   - Update in production .env
   - Test end-to-end payments

4. **Set up email:**
   - Configure SMTP settings
   - Test email notifications
   - Set up email templates

5. **Go live:**
   - Change `APP_ENV` to 'production'
   - Set correct domain in `APP_URL`
   - Use SSL/HTTPS
   - Set proper file permissions

## 📞 Support

For issues or questions:
- Check README.md for detailed documentation
- Review error logs in browser console
- Check MySQL error logs

## ✅ Installation Checklist

- [ ] Database created and schema imported
- [ ] `.env` file configured
- [ ] Upload directories created with proper permissions
- [ ] Apache and MySQL running
- [ ] First admin account created
- [ ] Homepage loads at http://localhost/umsadtech
- [ ] Can register new account
- [ ] Can login with admin account
- [ ] Can view courses
- [ ] Paystack API keys configured (test or live)

---

**Done!** Your Umsad Tech E-Learning Platform is ready to use. Start by creating courses and enrolling students!
