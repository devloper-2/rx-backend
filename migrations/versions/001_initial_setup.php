<?php

/**
 * Migration: 001_initial_setup
 * Creates: users, refresh_tokens, rate_limits tables
 */

return [

    'up' => function (PDO $pdo): void {

        // ── users ────────────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `users` (
                `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name`          VARCHAR(100) NOT NULL,
                `email`         VARCHAR(191) NOT NULL UNIQUE,
                `password`      VARCHAR(255) NOT NULL,
                `phone`         VARCHAR(20)  DEFAULT NULL,
                `role`          ENUM('admin','user','moderator') NOT NULL DEFAULT 'user',
                `status`        ENUM('active','inactive','banned') NOT NULL DEFAULT 'active',
                `avatar`        VARCHAR(500) DEFAULT NULL,
                `last_login_at` DATETIME     DEFAULT NULL,
                `created_at`    DATETIME     NOT NULL,
                `updated_at`    DATETIME     NOT NULL,
                INDEX `idx_users_email`  (`email`),
                INDEX `idx_users_status` (`status`),
                INDEX `idx_users_role`   (`role`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // ── refresh_tokens ───────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `refresh_tokens` (
                `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id`    INT UNSIGNED NOT NULL,
                `token`      CHAR(64)     NOT NULL UNIQUE,
                `revoked`    TINYINT(1)   NOT NULL DEFAULT 0,
                `expires_at` DATETIME     NOT NULL,
                `created_at` DATETIME     NOT NULL,
                INDEX `idx_rt_user_id` (`user_id`),
                INDEX `idx_rt_token`   (`token`),
                CONSTRAINT `fk_rt_user` FOREIGN KEY (`user_id`)
                    REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // ── rate_limits ──────────────────────────────────────────────────────
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `rate_limits` (
                `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `key`          CHAR(32)     NOT NULL UNIQUE,
                `endpoint`     VARCHAR(255) NOT NULL,
                `ip`           VARCHAR(45)  NOT NULL,
                `hits`         INT UNSIGNED NOT NULL DEFAULT 1,
                `window_start` DATETIME     NOT NULL,
                `updated_at`   DATETIME     NOT NULL,
                INDEX `idx_rl_key`        (`key`),
                INDEX `idx_rl_updated_at` (`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // ── Seed: default admin user ─────────────────────────────────────────
        // Password: Admin@1234
        $adminPassword = password_hash('Admin@1234', PASSWORD_ARGON2ID);
        $now           = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO `users` (name, email, password, role, status, created_at, updated_at)
            VALUES (:name, :email, :password, 'admin', 'active', :created_at, :updated_at)
        ");
        $stmt->execute([
            ':name'       => 'Super Admin',
            ':email'      => 'admin@example.com',
            ':password'   => $adminPassword,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    },

    'down' => function (PDO $pdo): void {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $pdo->exec("DROP TABLE IF EXISTS `rate_limits`;");
        $pdo->exec("DROP TABLE IF EXISTS `refresh_tokens`;");
        $pdo->exec("DROP TABLE IF EXISTS `users`;");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    },
];
