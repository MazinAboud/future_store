<?php
// Admin and employee logins were merged into one form (see staff/login.php).
// This file is kept only so any old bookmark/link to /admin/login.php still
// works — it just forwards to the unified team login.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (has_role('admin')) redirect('/admin/index.php');
redirect('/staff/login.php');
