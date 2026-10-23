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
