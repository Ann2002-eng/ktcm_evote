# 🗳️ KTCM E-Vote System
### Web-Based Student Leadership Voting System
**Kiharu Technical College Murang'a**

![PHP](https://img.shields.io/badge/PHP-8.0+-blue?logo=php)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-orange?logo=mysql)
![HTML5](https://img.shields.io/badge/HTML5-CSS3-red?logo=html5)
![License](https://img.shields.io/badge/License-MIT-green)
![Status](https://img.shields.io/badge/Status-Production%20Ready-brightgreen)

---

## 📌 Overview

A fully-featured, production-ready web-based voting system built for student leadership elections at Kiharu Technical College Murang'a. Designed to handle **3,000–5,000 students** securely and efficiently.

Built with **PHP + MySQL + HTML/CSS/JavaScript** — runs on XAMPP locally or any cPanel web hosting for institution-wide use.

---

## ✨ Features

### 🎓 Student Portal
- Secure login with Student ID + PIN (bcrypt hashed)
- Vote for multiple positions in one session
- Candidate manifesto viewer before voting
- Unique voting receipt (e.g. `VR-A1B2C3D4`)
- Receipt verification — confirm vote was counted
- Live election countdown timer
- Real-time voter turnout display
- Confetti animation on successful vote
- Fully mobile responsive

### ⚙️ Admin Panel
- Secure admin login with rate limiting
- **Student management** — Add, Edit, Delete, Reset PIN
- **Bulk CSV import** — Upload thousands of students at once
- **Candidate management** — Add/Edit/Delete with photo upload
- **Position management** — Add/Delete election positions
- **Live results** with animated bar charts
- **Export results** to CSV
- **Print results** directly from browser
- **Winner announcement** page
- **Election settings** — Name, dates, status control
- **Audit log** — Every action tracked with IP address
- **Admin password change**
- Reset all votes (for testing)

### 🔐 Security Features
- bcrypt password hashing for all PINs
- Rate limiting (10 attempts per 15 min per IP)
- MySQL transactions (prevents race conditions)
- Session hardening (HttpOnly, SameSite, 30-min timeout)
- Session ID regeneration on login
- Security headers (XSS, Clickjacking protection)
- CSRF token support
- `.htaccess` protection (blocks SQL files, disables directory listing)
- Database indexes for high-performance queries

---

## 📁 Project Structure

```
ktcm/
├── index.html              # Student voting portal
├── admin.html              # Admin management panel
├── api.php                 # Backend API (all endpoints)
├── config.example.php      # Config template (rename to config.php)
├── database_complete.sql   # Full database schema + sample data
├── .htaccess               # Security & performance rules
├── .gitignore              # Protects sensitive files
├── LICENSE                 # MIT License
└── README.md               # This file
```

---

## ⚙️ Installation

### Option A — Local (XAMPP)

1. **Clone the repository**
   ```bash
   git clone https://github.com/yourusername/ktcm-evote.git
   ```

2. **Copy to XAMPP**
   ```
   Copy the ktcm/ folder to: C:\xampp\htdocs\ktcm\
   ```

3. **Set up database**
   - Open `http://localhost/phpmyadmin`
   - Create database: `eschool_voting`
   - Import: `database_complete.sql`

4. **Configure database**
   ```bash
   cp config.example.php config.php
   ```
   Edit `config.php` with your credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('DB_NAME', 'eschool_voting');
   ```

5. **Hash existing PINs**
   - Create `hash_generator.php` (see docs) and visit:
   ```
   http://localhost/ktcm/hash_generator.php
   ```
   - **Delete it immediately after!**

6. **Open the system**
   ```
   Student Portal: http://localhost/ktcm/index.html
   Admin Panel:    http://localhost/ktcm/admin.html
   ```

### Option B — Web Hosting (cPanel)

1. Upload and extract project to `public_html/ktcm/`
2. Create MySQL database in cPanel → update `config.php`
3. Import `database_complete.sql` via phpMyAdmin
4. Visit `https://yourdomain.com/ktcm/index.html`

---

## 🔑 Default Credentials

### Test Students
| Student ID | PIN |
|---|---|
| KTCM/001/2026 | 1234 |
| KTCM/002/2026 | 1234 |
| KTCM/003/2026 | 1234 |

### Admin
| Username | Password |
|---|---|
| admin | admin123 |

> ⚠️ Change all default credentials before going live!

---

## 📥 Bulk Student Import (CSV)

To add thousands of students at once:

1. Go to **Admin Panel → Students → Bulk Import CSV**
2. Prepare CSV with format:
   ```
   StudentID, Full Name, Class, PIN
   KTCM/006/2026, Alice Mwangi, ICT Diploma 1, 1234
   KTCM/007/2026, Brian Ouma, Business Diploma 2, 1234
   ```
3. Upload file or paste data → Click Import
4. System automatically hashes all PINs

---

## 🗄️ Database Schema

| Table | Purpose |
|---|---|
| `students` | Registered voters |
| `positions` | Election posts (President, VP, etc.) |
| `candidates` | All candidates with manifestos |
| `votes` | Anonymous vote audit trail |
| `election_settings` | Election name, dates, status |
| `admins` | Admin users |
| `audit_log` | Full activity log |
| `login_attempts` | Rate limiting tracker |

---

## 🚀 Tech Stack

| Layer | Technology |
|---|---|
| Frontend | HTML5, CSS3, Vanilla JavaScript |
| Backend | PHP 8.0+ |
| Database | MySQL 5.7+ (via XAMPP or cPanel) |
| Server | Apache (XAMPP / cPanel) |
| Security | bcrypt, Sessions, .htaccess |

---

## 📊 Performance

- Handles **3,000–5,000 concurrent students**
- Database indexed on all frequently queried columns
- MySQL transactions prevent data corruption under load
- For 10,000+ students: upgrade to VPS with dedicated MySQL

---

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature`
3. Commit changes: `git commit -m 'Add your feature'`
4. Push: `git push origin feature/your-feature`
5. Open a Pull Request

---

## 📄 License

MIT License — see [LICENSE](LICENSE) file for details.

---

## 👨‍💻 Author

Built for **KNEC ICT Diploma Practical Examination**  
**Kiharu Technical College Murang'a** — 2026

---

> ⭐ If this project helped you, please give it a star on GitHub!
