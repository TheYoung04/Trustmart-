<?php
// ============================================================
// includes/mail_helper.php
// Shared helpers for out-of-band delivery of account recovery /
// activation links, and for deciding when it is safe to surface
// those demo links directly in an HTTP (JSON) response.
//
// Security rationale (Critical Fix #1):
//   A password-reset / activation token must NEVER be returned to a
//   remote, anonymous caller. The primary delivery channel is email.
//   Returning the link in the API response is a *local demo* convenience
//   only, and is additionally gated to requests originating from the
//   machine itself (loopback), so a public deployment is safe even if
//   TRUSTMART_DEV_MODE is accidentally left enabled.
// ============================================================

if (!function_exists('trustmart_is_loopback_request')) {
    /**
     * True only when the HTTP request arrived from the local machine
     * (127.0.0.0/8 or ::1). REMOTE_ADDR is set by the web server and
     * cannot be spoofed by the client at the TCP level.
     */
    function trustmart_is_loopback_request(): bool {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($ip === '') {
            return false;
        }

        // Normalise IPv4-mapped IPv6 such as ::ffff:127.0.0.1
        if (stripos($ip, '::ffff:') === 0) {
            $ip = substr($ip, 7);
        }

        if ($ip === 'localhost') {
            return true;
        }
        // IPv6 loopback
        if ($ip === '::1' || $ip === '0:0:0:0:0:0:0:1') {
            return true;
        }
        // IPv4 loopback block 127.0.0.0/8
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $first = (int)explode('.', $ip)[0];
            return $first === 127;
        }

        return false;
    }
}

if (!function_exists('trustmart_allow_demo_links')) {
    /**
     * Whether the activation / reset link may be included in the JSON
     * response body. Requires BOTH explicit development mode AND a
     * loopback (local machine) request.
     */
    function trustmart_allow_demo_links(): bool {
        return (defined('TRUSTMART_DEV_MODE') && TRUSTMART_DEV_MODE === true)
            && trustmart_is_loopback_request();
    }
}

if (!function_exists('trustmart_absolute_link')) {
    /**
     * Build an absolute, clickable link for email delivery. The incoming
     * $relative path already contains the full path from the web root
     * (e.g. /Trustmart/auth/...), so only the ORIGIN (scheme + host +
     * port) is prepended. The origin is taken from a configured base URL
     * and never derived from the untrusted HTTP Host header. Falls back to
     * the relative link when no base URL is configured (local/demo use).
     */
    function trustmart_absolute_link(string $relative): string {
        $base = trim((string)env('TRUSTMART_BASE_URL', env('PAYMENT_BASE_URL', '')));
        if ($base === '') {
            return $relative;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host']   ?? '';
        if ($host === '') {
            return $relative;
        }
        $origin = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $origin . $relative;
    }
}

if (!function_exists('trustmart_send_email')) {
    /**
     * Send a plain-text email via PHP's configured mail transport.
     * Returns true on success. Failures are logged, never surfaced.
     */
    function trustmart_send_email(string $to, string $subject, string $body): bool {
        // Recipient hardening: valid address, no header-injection characters.
        if (
            !filter_var($to, FILTER_VALIDATE_EMAIL)
            || str_contains($to, "\r") || str_contains($to, "\n")
            || str_contains($subject, "\r") || str_contains($subject, "\n")
        ) {
            return false;
        }

        $from = (string)env('MAIL_FROM', 'no-reply@trustmart.local');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $from = 'no-reply@trustmart.local';
        }

        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $from,
            'Reply-To: ' . $from,
            'X-Mailer: TrustMart',
        ]);

        $sent = @mail($to, $subject, $body, $headers);
        if (!$sent) {
            error_log('[TrustMart Mail] Failed to send message to ' . $to);
        }
        return $sent;
    }
}
