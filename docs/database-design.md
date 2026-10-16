# Database Design & Schema Specification — EVORA HRMS

## 1. Database Specifications
- **Database Name:** `evora_hrms`
- **RDBMS Engine:** MySQL 5.7+ / MariaDB 10.4+ (InnoDB Engine)
- **Character Set:** `utf8mb4`
- **Collation:** `utf8mb4_unicode_ci`

---

## 2. Entity-Relationship Overview

```text
[ departments ]
      │ 1
      │
      │ N
   [ users ] ────────┬──────── 1
      │ 1            │
      │              │ N
      │ N            └─────────── [ notifications ]
[ attendance ]       │
                     │ 1
                     │
                     │ N
                 [ leaves ]
```

### Key Relationships
- One **Department** contains many **Users** (`users.department_id -> departments.id`).
- One **User** has many **Attendance** records (`attendance.user_id -> users.id`).
- One **User** can submit many **Leave** requests (`leaves.user_id -> users.id`).
- One **User** can receive many in-app **Notifications** (`notifications.user_id -> users.id`).
- A **Leave** review links to the reviewing Admin (`leaves.reviewed_by -> users.id`).

---

## 3. Data Dictionary & Table Definitions

### Table 1: `departments`
Stores academic/corporate organizational units.

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PRIMARY KEY | Unique department identifier |
| `name` | VARCHAR(100) | NO | UNIQUE | Department name (e.g. Engineering, HR) |
| `code` | VARCHAR(20) | NO | UNIQUE | Short code (e.g. ENG, HR, MKT) |
| `description` | TEXT | YES | NULL | Overview of department responsibilities |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Record creation timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Last modified timestamp |

---

### Table 2: `users`
Stores all account credentials, role designations, and approval statuses.

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PRIMARY KEY | Unique user ID |
| `full_name` | VARCHAR(120) | NO | — | User's full legal name |
| `email` | VARCHAR(150) | NO | UNIQUE | Unique login email |
| `password_hash` | VARCHAR(255) | NO | — | Bcrypt password hash |
| `role` | ENUM('admin', 'employee') | NO | 'employee' | Account authorization role |
| `status` | ENUM('pending', 'approved', 'rejected') | NO | 'pending' | Administrative approval state |
| `department_id` | INT UNSIGNED | YES | NULL | Foreign key referencing `departments.id` |
| `designation` | VARCHAR(100) | YES | NULL | Professional title (e.g., Software Associate) |
| `phone` | VARCHAR(20) | YES | NULL | Contact phone number |
| `joined_date` | DATE | YES | NULL | Official joining date |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Timestamp when user registered |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | Last profile update |

**Foreign Key Constraints:**
- `fk_users_department`: `FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL`

---

### Table 3: `attendance`
Maintains daily attendance check-in and check-out logs.

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PRIMARY KEY | Attendance record ID |
| `user_id` | INT UNSIGNED | NO | — | Reference to `users.id` |
| `date` | DATE | NO | — | Date of attendance log |
| `check_in_time` | TIME | YES | NULL | Time employee checked in |
| `check_out_time` | TIME | YES | NULL | Time employee checked out |
| `status` | ENUM('present', 'late', 'half_day', 'absent') | NO | 'present' | Daily status calculation |
| `notes` | VARCHAR(255) | YES | NULL | Employee note or late reason |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Record timestamp |

**Indexes & Constraints:**
- `UNIQUE KEY uq_user_date (user_id, date)`: Prevents multiple check-in records for the same employee on the same date.
- `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`

---

### Table 4: `leaves`
Tracks employee leave applications, dates, and administrative approvals.

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PRIMARY KEY | Unique leave request ID |
| `user_id` | INT UNSIGNED | NO | — | Applicant reference to `users.id` |
| `leave_type` | ENUM('casual', 'sick', 'paid', 'unpaid') | NO | 'casual' | Category of leave |
| `start_date` | DATE | NO | — | First day of leave |
| `end_date` | DATE | NO | — | Last day of leave |
| `reason` | TEXT | NO | — | Employee explanation |
| `status` | ENUM('pending', 'approved', 'rejected') | NO | 'pending' | Current workflow state |
| `admin_remarks` | TEXT | YES | NULL | Admin feedback on approval/rejection |
| `reviewed_by` | INT UNSIGNED | YES | NULL | Admin ID who reviewed request |
| `reviewed_at` | DATETIME | YES | NULL | Decision timestamp |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Application submission timestamp |

**Foreign Key Constraints:**
- `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- `FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL`

---

### Table 5: `notifications`
Stores system alerts and status change messages.

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | NO | PRIMARY KEY | Notification ID |
| `user_id` | INT UNSIGNED | NO | — | Recipient reference to `users.id` |
| `title` | VARCHAR(150) | NO | — | Brief notification headline |
| `message` | TEXT | NO | — | Detailed notification body |
| `is_read` | TINYINT(1) | NO | 0 | Read flag (0 = unread, 1 = read) |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Creation timestamp |

**Foreign Key Constraints:**
- `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`

---

## 4. Default Seed Strategy
1. **Initial Departments:**
   - Human Resources (`HR`)
   - Engineering & IT (`ENG`)
   - Marketing & Communications (`MKT`)
   - Finance & Accounts (`FIN`)
   - Operations (`OPS`)
2. **Initial Administrator Account:**
   - Created via `database/seed_admin.php` with verified `status = 'approved'` and `role = 'admin'`.
