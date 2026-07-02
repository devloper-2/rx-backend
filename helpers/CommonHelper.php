<?php

declare(strict_types=1);

/**
 * CommonHelper — General-purpose static utility methods.
 */
class CommonHelper
{
    // ── String utilities ─────────────────────────────────────────────────────
    public static function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function generateApiToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isValidMobile(string $mobile): bool
    {
        return (bool) preg_match('/^\+[0-9]{8,15}$/', $mobile);
    }

    public static function cleanNulls(array $data): array
    {
        return array_filter($data, fn($v) => $v !== null && $v !== '');
    }

    public static function generateSlugWithId(string $text, int $id): string
    {
        return self::slugify($text) . '-' . $id;
    }

    public static function generateOtp(int $length = 6): string
    {
        $max = (int) str_pad('9', $length, '9');
        $min = (int) str_pad('1', $length, '0');
        return str_pad((string) random_int($min, $max), $length, '0', STR_PAD_LEFT);
    }

    public static function slugify(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\w\s-]/u', '', $text);
        $text = preg_replace('/[\s_]+/', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, '-');
    }

    public static function truncate(string $text, int $length = 100, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $length) return $text;
        return mb_substr($text, 0, $length - mb_strlen($suffix)) . $suffix;
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $masked = substr($local, 0, 2) . str_repeat('*', max(0, strlen($local) - 2));
        return $masked . '@' . $domain;
    }

    public static function maskPhone(string $phone): string
    {
        $clean = preg_replace('/\D/', '', $phone);

        if (strlen($clean) <= 4) return $phone;

        return '+' . substr($clean, 0, 2) .
               str_repeat('*', max(0, strlen($clean) - 4)) .
            substr($clean, -2);
    }

    // ── Array utilities ──────────────────────────────────────────────────────
    /** Remove keys from an array */
    public static function except(array $data, array $keys): array
    {
        return array_diff_key($data, array_flip($keys));
    }

    /** Keep only specified keys */
    public static function only(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }

    /** Flatten a nested array */
    public static function flatten(array $arr, string $prefix = ''): array
    {
        $result = [];
        foreach ($arr as $k => $v) {
            $fullKey = $prefix ? "{$prefix}.{$k}" : $k;
            if (is_array($v)) {
                $result += self::flatten($v, $fullKey);
            } else {
                $result[$fullKey] = $v;
            }
        }
        return $result;
    }

    /** Safe dot-notation array getter */
    public static function arrayGet(array $arr, string $key, mixed $default = null): mixed
    {
        foreach (explode('.', $key) as $segment) {
            if (!is_array($arr) || !array_key_exists($segment, $arr)) return $default;
            $arr = $arr[$segment];
        }
        return $arr;
    }

    // ── Date & time utilities ────────────────────────────────────────────────
    public static function now(string $format = 'Y-m-d H:i:s'): string
    {
        return date($format);
    }

    public static function formatDate(string $date, string $format = 'd M Y'): string
    {
        $ts = strtotime($date);
        return $ts ? date($format, $ts) : '';
    }

    public static function timeAgo(string $datetime): string
    {
        $diff = time() - strtotime($datetime);
        if ($diff < 60)     return 'just now';
        if ($diff < 3600)   return (int)($diff / 60)   . ' minutes ago';
        if ($diff < 86400)  return (int)($diff / 3600)  . ' hours ago';
        if ($diff < 604800) return (int)($diff / 86400) . ' days ago';
        return date('d M Y', strtotime($datetime));
    }

    // ── Security utilities ───────────────────────────────────────────────────
    public static function hashPassword(string $password): string
    {
        //return password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 512, 'time_cost' => 1, 'threads' => 1]);
        //['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 3]);

        return password_hash($password, PASSWORD_BCRYPT, [
            'cost' => 10
        ]);
    }

    public static function verifyOtp(string $inputOtp, string $storedOtp): bool
    {
        return hash_equals($storedOtp, $inputOtp);
    }   

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function sanitizeString(string $value): string
    {
        return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function sanitizeArray(array $data): array
    {
        array_walk_recursive($data, function (&$val): void {
            if (is_string($val)) {
                $val = self::sanitizeString($val);
            }
        });
        return $data;
    }

    // ── HTTP utilities ───────────────────────────────────────────────────────
    public static function getClientIp(): string
    {
        $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    public static function isValidEmail(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public static function isValidUrl(string $url): bool
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL);
    }

    // ── Number / format ──────────────────────────────────────────────────────
    public static function bytesToHuman(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public static function generateToken(int $length = 40): string
    {
        return bin2hex(random_bytes($length));
    }

    // ── Pagination helper (for manual use outside Database class) ────────────
    public static function paginationMeta(int $total, int $page, int $limit): array
    {
        $page  = max(1, $page);
        $limit = max(1, $limit);
        $totalPages = (int) ceil($total / $limit);
        return [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => $totalPages,
            'has_next'    => $page < $totalPages,
            'has_prev'    => $page > 1,
        ];
    }
}
