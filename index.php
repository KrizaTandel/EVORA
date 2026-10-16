<?php
/**
 * EVORA HRMS — Application Entry Point
 * 
 * Automatically redirects requests hitting the root directory
 * to the frontend portal (supports both PHP built-in server and XAMPP).
 */
header('Location: frontend/index.html');
exit;
