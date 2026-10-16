# Project Overview — EVORA HRMS

## 1. Executive Summary
**EVORA HRMS** is a lightweight, responsive, and secure Human Resource Management System developed as a college-level Web Development demonstration project. It highlights standard web development principles using core technologies—HTML5, CSS3, Vanilla JavaScript, Core PHP, and MySQL—without reliance on third-party frameworks.

The system addresses fundamental workforce management challenges: employee onboarding, administrative verification, department organization, daily attendance tracking, leave requests, and institutional reporting.

---

## 2. Core Project Objectives
- **Demonstrate Core Web Fundamentals:** Clean semantic HTML, modular CSS styling, reactive vanilla DOM scripting, and secure PHP server-side handling.
- **Enforce Role-Based Governance:** Distinct workspaces for System Administrators and Staff Members with secure server-side session authentication.
- **Gated Onboarding:** A verification pipeline where newly registered employees must receive administrative approval before system access is granted.
- **Data-Driven Insights:** Provide clear, interactive metric cards and charts (using Chart.js) for employee attendance and department distributions.
- **Maintainable Codebase:** Clean separation of concerns between client-side views (`frontend/`), server endpoints (`backend/`), and data storage (`database/`).

---

## 3. Explicit Inclusions & Exclusions

### ✅ Included Modules
1. **Authentication & Authorization:**
   - Secure login for Admin and approved Employees.
   - Public Employee registration with automatic `pending` status assignment.
   - Password recovery workflow.
   - Server-enforced session timeout and CSRF token validation.
2. **Administrator Portal:**
   - Overview dashboard with quick counts (Total Employees, Pending Approvals, Present Today, Active Leaves) and Chart.js analytics.
   - Employee Management (Approve/Reject registrations, edit employee details, toggle status).
   - Department Management (Create, read, update, delete department records).
   - Attendance & Leave Oversight (Review daily attendance records, approve or reject leave submissions).
3. **Employee Portal:**
   - Personal dashboard showing attendance status, recent notifications, and remaining leave balances.
   - Attendance management (Daily clock-in and clock-out with status indicators: Present, Late, Half-Day).
   - Leave Management (Submit leave requests with date range and reason; track historical request statuses).
   - Profile Management (View and update contact details).
4. **Notification System:**
   - In-app notification center alerting employees of registration approval, leave decisions, and system announcements.

### ❌ Excluded Modules (Out of Scope by Design)
- **Payroll & Compensation Management:** Excluded to maintain focus on core HR workflows.
- **Document Management & File Upload Archives:** Excluded to keep local setup straightforward and lightweight.

---

## 4. Architectural Design & Communication Flow

### Client-Server Decoupling
The architecture decouples the HTML/CSS/JS frontend views from the Core PHP backend:
- **Presentation Layer (`frontend/`):** Static HTML templates styled with a unified CSS token system.
- **Asynchronous Communication Layer (`Fetch API / AJAX`):** JavaScript handles form submissions, button interactions, and table pagination without full-page reloads.
- **API Endpoints (`backend/`):** Core PHP scripts process HTTP requests (`POST`, `GET`), perform authorization checks, query MySQL via PDO prepared statements, and return standard JSON responses:
  ```json
  {
    "success": true,
    "message": "Operation completed successfully",
    "data": { ... }
  }
  ```

---

## 5. Security & Academic Integrity Guidelines
1. **No Admin Self-Registration:** The public signup form strictly registers users as `employee` with status `pending`. Admin accounts are created strictly via CLI/backend seeder.
2. **Prepared SQL Statements:** Zero concatenation in SQL queries. All database operations strictly employ PDO parameter binding.
3. **Password Security:** Use `password_hash($password, PASSWORD_BCRYPT)` and `password_verify($password, $hash)`.
4. **Session Hardening:**
   - Sessions utilize `HttpOnly` and `SameSite=Lax` cookie flags.
   - Session identifiers are refreshed via `session_regenerate_id(true)` upon login.
5. **Cross-Site Request Forgery (CSRF):** Unique cryptographic tokens are generated per session and validated on mutating requests (`POST`/`PUT`/`DELETE`).
6. **Credential Privacy:** Database passwords and sensitive environment keys reside exclusively in `.env` (excluded by `.gitignore`).

---

## 6. Color Scheme & UI Philosophy
The design adopts an organic, executive visual identity:
- **Cream Tones (`#FBF9F5`, `#F4F0E8`):** Clean, warm background surfaces reducing eye fatigue.
- **Sage Green (`#8A9A86`, `#A3B19B`):** Restful secondary branding, borders, badges, and focus rings.
- **Forest Green (`#2D4739`, `#1F3327`):** Authoritative primary action buttons, main navigation, and typography.
