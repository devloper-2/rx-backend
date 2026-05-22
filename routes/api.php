<?php

declare(strict_types=1);

/**
 * API Routes
 * ----------
 * Pattern: $router->{method}('{controller}/{action}', 'ControllerName', 'actionMethod', $options)
 *
 * Options:
 *   'auth'       => true   — Require valid JWT token
 *   'rate_limit' => ['max' => N, 'window' => seconds]
 *
 * URL examples:
 *   POST domain.com/auth/login
 *   GET  domain.com/user/list
 *   GET  domain.com/user/detail/42   (42 = {id} URL param)
 */




// new auth route for testing 
// url will be http://localhost/rx-backend/auth/

//api.php
// for register
$router->post(
    'auth/register-new',
    'NewAuth', 'register',
    ['rate_limit' => ['max' => 90, 'window' => 7 * 24 * 60 * 60]]  // 90 registrations/ week IP
);

// for login
$router->post(
    'auth/login-new',
    'NewAuth', 'login',
    ['rate_limit' => ['max' => 90, 'window' => 7 * 24 * 60 * 60]]  // 90 login attempts/ week IP
);

// for logout
$router->post(
    'auth/logout-new',
    'NewAuth', 'logout'
);

// for forgot password new
$router->post(
    'auth/forgot-password-otp', 
    'NewAuth', 
    'forgotPasswordOtp'
);

// for reset password new
$router->post(
    'auth/reset-password-otp', 
    'NewAuth', 
    'resetPasswordOtp'
);

// ====================== ABOVE ALL API ARE FOR TESTING ======================== //


// ── Auth routes (no JWT required) ────────────────────────────────────────────
$router->post(
    'auth/register',
    'Auth', 'register',
    ['rate_limit' => ['max' => 3, 'window' => 3600]]  // 3 registrations/hr per IP
);

$router->post(
    'auth/login',
    'Auth', 'login',
    ['rate_limit' => ['max' => 5, 'window' => 3600]]  // 5 login attempts/hr per IP
);

$router->post(
    'auth/refresh',
    'Auth', 'refresh',
    ['rate_limit' => ['max' => 10, 'window' => 3600]]
);

// ── Auth routes (JWT required) ────────────────────────────────────────────────
$router->post(
    'auth/logout',
    'Auth', 'logout',
    ['auth' => true]
);

$router->get(
    'auth/me',
    'Auth', 'me',
    ['auth' => true, 'rate_limit' => ['max' => 30, 'window' => 3600]]
);

// ── User routes (JWT required) ────────────────────────────────────────────────
$router->get(
    'user/list',
    'User', 'list',
    ['auth' => true, 'rate_limit' => ['max' => 15, 'window' => 3600]]
);

$router->get(
    'user/detail/{id}',
    'User', 'detail',
    ['auth' => true, 'rate_limit' => ['max' => 30, 'window' => 3600]]
);

$router->put(
    'user/update/{id}',
    'User', 'update',
    ['auth' => true, 'rate_limit' => ['max' => 10, 'window' => 3600]]
);

$router->delete(
    'user/delete/{id}',
    'User', 'delete',
    ['auth' => true, 'rate_limit' => ['max' => 5, 'window' => 3600]]
);

$router->post(
    'user/upload-avatar',
    'User', 'uploadAvatar',
    ['auth' => true, 'rate_limit' => ['max' => 5, 'window' => 3600]]
);

$router->put(
    'user/change-password',
    'User', 'changePassword',
    ['auth' => true, 'rate_limit' => ['max' => 3, 'window' => 3600]]
);
