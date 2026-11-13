<?php
// ============================================================
// includes/csrf.php
// CSRF token generation and validation for TrustMart.
// Included automatically via auth_check.php.
// ============================================================

/**
 * Get or generate the CSRF token for the current session.
 */
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Output a <meta> tag containing the CSRF token.
 * Place this inside <head> on every page that makes fetch() POST calls.
 */
function csrf_meta_tag(): string
{
    return '<meta name="csrf-token" content="' . csrf_token() . '">';
}

/**
 * Output a hidden <input> field with the CSRF token.
 * Use inside traditional <form> elements.
 */
function csrf_input(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
}

/**
 * Inline <script> that patches window.fetch() to auto-include the
 * CSRF token in every POST request. Place after csrf_meta_tag().
 */
function csrf_script(): string
{
    return <<<'JS'
<script>
(function(){
  var _f=window.fetch;
  window.fetch=function(u,o){
    o=o||{};
    if(o.method&&o.method.toUpperCase()==='POST'){
      var t=document.querySelector('meta[name="csrf-token"]');
      if(t){o.headers=o.headers||{};o.headers['X-CSRF-Token']=t.content;}
    }
    return _f.call(this,u,o);
  };
})();
</script>
JS;
}

/**
 * Validate the CSRF token on a POST request.
 * Checks the X-CSRF-Token header first, then the _csrf_token POST field.
 * On failure: sends a 403 JSON error and exits.
 */
function csrf_validate(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? $_POST['_csrf_token']
          ?? '';

    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode([
            'success' => false,
            'message' => 'Token keselamatan tidak sah. Sila muat semula halaman dan cuba lagi.',
        ]));
    }
}
