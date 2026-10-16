# EVORA HRMS (Human Resource Management System)

A clean, modular, and accessible Human Resource Management System developed as a college Web Development project following strict educational guidelines and standard web practices.

---

## 🛠 Tech Stack

- **Frontend:** HTML5, CSS3, Vanilla JavaScript (ES6+)
- **Backend:** Core PHP (Procedural / Object-Oriented without frameworks)
- **Database:** MySQL (Structured Relational Schema)
- **Data Exchange:** JavaScript Fetch API / AJAX (JSON payload communication)
- **Visualizations:** Chart.js (for administrative and employee dashboards)
- **Environment:** Local PHP & MySQL server (e.g., XAMPP, WampServer, or native PHP/MySQL)

> **Architectural Constraint:** This project strictly uses vanilla web technologies. No frontend frameworks (React, Next.js, Vue) or backend frameworks (Laravel, Symfony, Node.js/Express) are permitted.

---

## 🎨 Design System & Palette

EVORA HRMS features a natural, calming, and aesthetic color scheme tailored for professional HR workflows:
- **Base Cream:** `#FBF9F5` (Soft background)
- **Warm Cream / Card Surface:** `#F4F0E8` (Containers & panels)
- **Sage Green:** `#8A9A86` (Accents, borders, secondary actions)
- **Muted Sage:** `#A3B19B` (Subtle indicators & highlights)
- **Deep Forest Green:** `#2D4739` / `#1F3327` (Primary buttons, typography, headers)
- **Alert Tones:** Soft Terracotta `#C86D51` (Errors/rejections), Amber `#D4A373` (Pending)

---

## 👥 User Roles & Access Control

The system implements strict **Role-Based Access Control (RBAC)**:

1. **Admin (`admin`)**:
   - Complete system oversight.
   - Review and approve/reject pending employee registrations.
   - Manage employees (status, department, designation).
   - Manage departments.
   - Review, approve, or reject employee leave requests and monitor attendance logs.
   - View analytics and organizational summary charts.
   - *Note: Admin accounts can never be created via public registration; they are seeded securely.*

2. **Employee (`employee`)**:
   - Self-registration triggers a **Pending** status.
   - **Approval Gate:** Pending employees cannot log in or access protected modules until an Admin approves their account.
   - Once approved, employees can mark attendance (check-in/check-out), submit leave requests, view leave statuses, receive system notifications, and manage their personal profile.

---

## 📁 Project Directory Structure

```text
EVORA/
├── frontend/                     # Client-side presentation layer
│   ├── index.html                # Entry point / Landing portal
│   ├── pages/                    # Sub-pages and views
│   │   ├── login.html            # User authentication
│   │   ├── signup.html           # Employee registration (creates pending account)
│   │   ├── forgot-password.html  # Password recovery request
│   │   ├── employee/             # Protected Employee Portal
│   │   │   ├── dashboard.html    # Employee overview & attendance status
│   │   │   ├── profile.html      # Personal profile view/update
│   │   │   ├── attendance.html   # Daily check-in/out & history
│   │   │   └── leaves.html       # Leave application & tracking
│   │   └── admin/                # Protected Administrator Portal
│   │       ├── dashboard.html    # Admin metrics, charts & quick actions
│   │       ├── employees.html    # Employee directory & approval queue
│   │       ├── departments.html  # Department management
│   │       └── leave-attendance.html # Leave review & attendance monitoring
│   ├── assets/                   # Static media and design assets
│   │   ├── css/                  # Global and component stylesheets
│   │   ├── js/                   # Shared scripts and API handlers
│   │   └── images/               # Logos, icons, and illustrations
│   └── components/               # Reusable HTML snippets (headers, sidebars, modals)
│
├── backend/                      # Server-side API endpoints & logic
│   ├── config/                   # Database connection and environment loaders
│   ├── includes/                 # Reusable helpers (CSRF, session guards, responses)
│   ├── auth/                     # Authentication endpoints (login, signup, logout)
│   ├── admin/                    # Administrative CRUD endpoints
│   ├── employee/                 # Employee specific endpoints
│   ├── dashboard/                # Aggregated metrics & chart data endpoints
│   └── notifications/            # In-app notification dispatcher
│
├── database/                     # Database assets & migrations
│   ├── evora_hrms.sql            # Base schema definitions and table seeds
│   └── seed_admin.php            # CLI/Browser secure admin provisioning script
│
├── docs/                         # Engineering and architecture documentation
│   ├── project-overview.md       # High-level architecture and requirements
│   └── database-design.md        # Relational schema and entity specifications
│
├── .env.example                  # Environment configuration template
├── .gitignore                    # Version control ignore rules
└── README.md                     # Project documentation
```

---

## ⚙️ Prerequisites & Setup Guide

### 1. Requirements
- **Web Server:** Apache (via XAMPP, WAMP, or standalone)
- **PHP:** PHP 8.0 or higher with PDO and OpenSSL extensions enabled
- **Database:** MySQL 5.7+ or MariaDB 10.4+
- **Browser:** Any modern standards-compliant browser (Chrome, Firefox, Edge, Safari)

### 2. Local Installation (XAMPP Example)
1. Clone or place the project folder inside your web server root:
   - For XAMPP on Windows: `C:\xampp\htdocs\EVORA`
2. Start the **Apache** and **MySQL** services in the XAMPP Control Panel.
3. Open **phpMyAdmin** (`http://localhost/phpmyadmin/`).
4. Import the schema:
   - Create a new database named `evora_hrms` (utf8mb4_unicode_ci collation).
   - Import `database/evora_hrms.sql`.
5. Configure Environment:
   - Copy `.env.example` to `.env` in the root directory.
   - Adjust database credentials if your MySQL password is not blank.
6. Seed the Administrator Account:
   - Run the seeder via terminal:
     ```bash
     php database/seed_admin.php
     ```
   - Or open `http://localhost/EVORA/database/seed_admin.php` in your browser.
7. Launch Application:
   - Navigate to `http://localhost/EVORA/frontend/` to view the portal.

---

## 🔒 Security Best Practices Implemented

- **Prepared SQL Statements:** All queries use PHP Data Objects (PDO) with parameter binding to prevent SQL injection.
- **Strong Hashing:** Secure password storage using `password_hash()` and validation via `password_verify()`.
- **State & Session Security:** Strict PHP sessions with session fixation prevention (`session_regenerate_id()`) and role validation.
- **CSRF Tokens:** State-changing requests validate a unique server-generated CSRF token.
- **Input Sanitization:** Dual-layer validation (client-side HTML5/JS + server-side PHP filters).
- **Credential Protection:** Credentials stored outside version control via `.env`.