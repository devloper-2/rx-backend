<?php

declare(strict_types=1);

/**
 * CLEAN API ROUTES (DOCTOR SYSTEM)
 */
            // http://localhost/rx-backend/auth/reset-password
            // pass:: Test@123   and   NewPass@123
// ─────────────────────────────────────────
// AUTH ROUTES 
// ─────────────────────────────────────────

$router->post(
    'auth/register',
    'NewAuth',
    'register',
    ['rate_limit' => ['max' => 500, 'window' => 3600 * 24 * 30]] // 500 per month
);

$router->post(
    'auth/login',
    'NewAuth',
    'login',
    ['rate_limit' => ['max' => 100, 'window' => 3600 * 24 * 60]] // 100 per month 
);

$router->post(
    'auth/verify-otp',
    'NewAuth',
    'verifyOtp'
);

$router->post(
    'auth/forgot-password',
    'NewAuth',
    'forgotPasswordOtp'
);

$router->post(
    'auth/reset-password',
    'NewAuth',
    'resetPasswordOtp'
);

$router->post(
    'auth/resend-otp', 
    'NewAuth', 
    'resendOtp'
);

$router->post(
    'auth/check-email', 
    'NewAuth', 
    'checkEmail'
);

$router->post(
    'auth/check-phone', 
    'NewAuth', 
    'checkPhone'
);


// ─────────────────────────────────────────
// AUTH REQUIRED ROUTES
// ─────────────────────────────────────────

$router->post(
    'auth/logout',
    'NewAuth',
    'logout',
    ['auth' => true]
);

$router->get(
    'auth/me',
    'User',
    'detail',
    ['auth' => true]
);

$router->post(
    'auth/refresh',
    'NewAuth',
    'refresh'
);

// ─────────────────────────────────────────
// PRESCRIPTION ROUTES
// ─────────────────────────────────────────

$router->post(
    'prescription/create',
    'Prescribe',   
    'create',
    ['auth' => true]
);

$router->get(
    'prescription/get/{id}',
    'Prescribe',
    'get',
    ['auth' => true]
);

$router->delete(
    'prescription/delete/{id}',
    'Prescribe',
    'delete',
    ['auth' => true]
);

$router->post(
    'patient/search',
    'Prescribe',
    'searchPatients',
    ['auth'=>true]
);

$router->post(
    'medicine/search',
    'Prescribe',
    'searchMedicines',
    ['auth'=>true]
);

$router->post(
    'symptoms/search',
    'Prescribe',
    'searchSymptoms',
    ['auth'=>true]
);

$router->get(
    'clinic/list',
    'Prescribe',
    'getClinics',
    ['auth'=>true]
);

$router->post(
    'advice/search',
    'Prescribe',
    'searchAdvice',
    ['auth'=>true]
);

$router->put(
    "/prescription/{id}",
    "PrescribeController@update"
);

/*$router->post(
    "/prescription/update/{id}",
    "PrescribeController@update"
);*/

?>