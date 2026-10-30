<?php

declare(strict_types=1);

/**
 * API Routes
 * ----------
 * Pattern: $router->{method}('{path}', 'ControllerName', 'actionMethod', $options)
 *
 * Options:
 *   'auth'       => true   — Require valid JWT token
 *   'rate_limit' => ['max' => N, 'window' => seconds]   (per client IP)
 *
 * Limits are per IP and a clinic's whole staff may share one, so they are
 * deliberately generous; brute force on codes is capped separately by the
 * per-code attempt counter in AuthController.
 */

// ── Auth (public) ────────────────────────────────────────────────────────────
$router->post('auth/register-new',    'Auth', 'register',       ['rate_limit' => ['max' => 20, 'window' => 3600]]);
$router->post('auth/verify-otp',      'Auth', 'verifyOtp',      ['rate_limit' => ['max' => 30, 'window' => 900]]);
$router->post('auth/resend-otp',      'Auth', 'resendOtp',      ['rate_limit' => ['max' => 20, 'window' => 900]]);
$router->post('auth/login-new',       'Auth', 'login',          ['rate_limit' => ['max' => 30, 'window' => 900]]);
$router->post('auth/forgot-password', 'Auth', 'forgotPassword', ['rate_limit' => ['max' => 20, 'window' => 900]]);
$router->post('auth/reset-password',  'Auth', 'resetPassword',  ['rate_limit' => ['max' => 30, 'window' => 900]]);
$router->post('auth/refresh',         'Auth', 'refresh',        ['rate_limit' => ['max' => 60, 'window' => 3600]]);
$router->post('auth/logout',          'Auth', 'logout',         ['rate_limit' => ['max' => 60, 'window' => 3600]]);

// ── Auth (JWT required) ──────────────────────────────────────────────────────
$router->get('auth/me', 'Auth', 'me', ['auth' => true]);

// ── Doctor profile (JWT required) ────────────────────────────────────────────
$router->get('doctor/profile',          'Doctor', 'profile',        ['auth' => true]);
$router->put('doctor/profile',          'Doctor', 'updateProfile',  ['auth' => true]);
$router->put('doctor/change-password',  'Doctor', 'changePassword', ['auth' => true, 'rate_limit' => ['max' => 10, 'window' => 900]]);

// ── Patients (JWT required) ──────────────────────────────────────────────────
$router->get('catalog/conditions', 'Patient', 'conditions', ['auth' => true]);
$router->get('patients',           'Patient', 'list',       ['auth' => true]);
$router->post('patients',          'Patient', 'create',     ['auth' => true]);
$router->get('patients/{id}',      'Patient', 'detail',     ['auth' => true]);
$router->put('patients/{id}',      'Patient', 'update',     ['auth' => true]);
$router->delete('patients/{id}',   'Patient', 'delete',     ['auth' => true]);

$router->get('dashboard/summary', 'Doctor', 'summary', ['auth' => true]);
$router->get('insights', 'Insights', 'index', ['auth' => true]);

// ── Catalog for the prescription editor (JWT required) ───────────────────────
$router->get('catalog/medicines',  'Catalog', 'medicines',    ['auth' => true]);
$router->post('catalog/medicines', 'Catalog', 'addMedicine',  ['auth' => true, 'rate_limit' => ['max' => 60, 'window' => 3600]]);
$router->get('catalog/symptoms',   'Catalog', 'symptoms',     ['auth' => true]);
$router->get('catalog/advice',     'Catalog', 'advice',       ['auth' => true]);
$router->get('catalog/diagnoses',  'Catalog', 'diagnoses',    ['auth' => true]);

// ── Prescriptions (JWT required) ─────────────────────────────────────────────
$router->post('prescriptions',                 'Prescription', 'create',   ['auth' => true]);
$router->get('prescriptions',                  'Prescription', 'list',     ['auth' => true]);
$router->get('prescriptions/{id}',             'Prescription', 'detail',   ['auth' => true]);
$router->put('prescriptions/{id}',             'Prescription', 'update',   ['auth' => true]);
$router->delete('prescriptions/{id}',          'Prescription', 'delete',   ['auth' => true]);
$router->post('prescriptions/{id}/finalize',   'Prescription', 'finalize', ['auth' => true]);
$router->post('prescriptions/{id}/cancel',     'Prescription', 'cancel',   ['auth' => true]);
$router->get('patients/{id}/prescriptions',    'Prescription', 'forPatient', ['auth' => true]);

// ── Clinic (letterhead) ──────────────────────────────────────────────────────
$router->get('doctor/clinic', 'Doctor', 'clinic',       ['auth' => true]);
$router->put('doctor/clinic', 'Doctor', 'updateClinic', ['auth' => true]);

// ── Appointments / today's queue (JWT required) ──────────────────────────────
$router->get('appointments',                'Appointment', 'list',      ['auth' => true]);
$router->post('appointments',               'Appointment', 'create',    ['auth' => true]);
$router->put('appointments/{id}',           'Appointment', 'update',    ['auth' => true]);
$router->post('appointments/{id}/status',   'Appointment', 'setStatus', ['auth' => true]);
$router->get('followups',           'Followup', 'index',   ['auth' => true]);
$router->post('followups/dismiss',  'Followup', 'dismiss', ['auth' => true]);
$router->post('reminders',          'Followup', 'remind',  ['auth' => true]);
$router->get('doctor/hours',            'Clinic', 'hours',         ['auth' => true]);
$router->put('doctor/hours',            'Clinic', 'saveHours',     ['auth' => true]);
$router->post('doctor/holidays',        'Clinic', 'addHoliday',    ['auth' => true]);
$router->delete('doctor/holidays/{id}', 'Clinic', 'deleteHoliday', ['auth' => true]);
$router->get('bills',                   'Bill', 'index',   ['auth' => true]);
$router->get('bills/summary',           'Bill', 'summary', ['auth' => true]);
$router->post('bills',                  'Bill', 'create',  ['auth' => true]);
$router->get('bills/{id}',              'Bill', 'show',    ['auth' => true]);
$router->post('bills/{id}/payment',     'Bill', 'pay',     ['auth' => true]);
$router->post('bills/{id}/cancel',      'Bill', 'cancel',  ['auth' => true]);

// ── Lab & imaging orders (JWT required) ──────────────────────────────────────
$router->get('catalog/lab-tests',                 'Catalog',       'labTests',        ['auth' => true]);
$router->get('orders',                            'Investigation', 'list',            ['auth' => true]);
$router->get('lab-orders/{id}',                   'Investigation', 'labDetail',       ['auth' => true]);
$router->put('lab-orders/{id}/results',           'Investigation', 'saveLabResults',  ['auth' => true]);
$router->post('lab-orders/{id}/status',           'Investigation', 'labStatus',       ['auth' => true]);
$router->post('lab-orders/{id}/file',             'Investigation', 'uploadLabFile',   ['auth' => true, 'rate_limit' => ['max' => 60, 'window' => 3600]]);
$router->get('lab-orders/{id}/file',              'Investigation', 'labFile',         ['auth' => true]);
$router->get('radiology-orders/{id}',             'Investigation', 'radDetail',       ['auth' => true]);
$router->put('radiology-orders/{id}/report',      'Investigation', 'saveRadReport',   ['auth' => true]);
$router->post('radiology-orders/{id}/status',     'Investigation', 'radStatus',       ['auth' => true]);
$router->post('radiology-orders/{id}/file',       'Investigation', 'uploadRadFile',   ['auth' => true, 'rate_limit' => ['max' => 60, 'window' => 3600]]);
$router->get('radiology-orders/{id}/file',        'Investigation', 'radFile',         ['auth' => true]);
