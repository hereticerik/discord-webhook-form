<?php
declare(strict_types=1);

/**
 * Web form -> Discord webhook (JSON embed)
 *
 * Written By HereticErik - https://github.com/hereticerik
 * For educational/demonstration purposes, use with caution and secure this page if you use it for something.
 *
 * Requirements: PHP 7.4+ (or preferably above) with the cURL extension.   
 */

const WEBHOOK_FALLBACK = 'https://discord.com/api/webhooks/xxx/zzz';
const SUBMIT_COOLDOWN_SECONDS = 600; // cooldown in seconds, 600 is 10 minutes

$webhookUrl = getenv('DISCORD_WEBHOOK_URL') ?: WEBHOOK_FALLBACK;

$services = [
    'web_hosting'   => 'Web hosting',
    'cloud_storage' => 'Cloud storage',
    'vps_hosting'   => 'VPS hosting',
];

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clean(?string $value, int $max): string
{
    $value = trim((string) $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr($value, 0, $max);
}

/** Strip scheme, path, port and "www." so "https://www.Example.com/page" -> "example.com". */
function normalizeDomain(string $input): string
{
    $input = strtolower(trim($input));
    $input = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $input) ?? '';
    $input = preg_replace('#[/?\#:].*$#', '', $input) ?? '';
    return preg_replace('#^www\.#', '', $input) ?? '';
}

function sendToDiscord(string $webhookUrl, array $payload): bool
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    // Discord returns 204 No Content on success (200 if ?wait=true is used).
    return $status === 200 || $status === 204;
}

$errors = [];
$old = ['service' => '', 'discord' => '', 'email' => '', 'domain' => ''];
$success = false;

if (!empty($_SESSION['flash_success'])) {
    $success = true;
    unset($_SESSION['flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['service'] = clean($_POST['service'] ?? '', 32);
    $old['discord'] = clean($_POST['discord'] ?? '', 64);
    $old['email']   = clean($_POST['email'] ?? '', 254);
    $old['domain']  = clean($_POST['domain'] ?? '', 253);

    // Security checks
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors['form'] = 'Your session expired. Reload this registration page and try again.';
    } elseif (!empty($_POST['website'])) {
        // Honeypot field filled in: pretend it worked, send nothing.
        $_SESSION['flash_success'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
        exit;
    } elseif (time() - (int) ($_SESSION['last_submit'] ?? 0) < SUBMIT_COOLDOWN_SECONDS) {
        $errors['form'] = 'Please wait 10 minutes before submitting again.';
    }

    // Field validation
    if (!isset($services[$old['service']])) {
        $errors['service'] = 'Choose the service you want to sign up for.';
    }

    $discord = ltrim($old['discord'], '@');
    if ($discord === '') {
        $errors['discord'] = 'Enter your Discord username.';
    } elseif (!preg_match('/^[A-Za-z0-9_.]{2,32}(#\d{4})?$/', $discord)) {
        $errors['discord'] = 'Use 2 to 32 letters, numbers, underscores, or periods (no spaces).';
    }

    if ($old['email'] === '') {
        $errors['email'] = 'Enter your email address.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address, like name@example.com.';
    }

    $domain = '';
    if ($old['domain'] !== '') {
        $domain = normalizeDomain($old['domain']);
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            $errors['domain'] = 'Enter a domain like example.com, or leave this blank.';
        }
    }

    if (!$errors) {
        $_SESSION['last_submit'] = time();

        $payload = [
            'username'         => 'Web Form Registration',
            // Stops @everyone / @role / @user pings from anything the visitor typed.
            'allowed_mentions' => ['parse' => []],
            'embeds'           => [[
                'title'     => 'Registration Form Submission',
                'color'     => 0x702189,
                'fields'    => [
                    ['name' => 'Service',          'value' => $services[$old['service']],        'inline' => true],
                    ['name' => 'Discord username', 'value' => $discord,                          'inline' => true],
                    ['name' => 'Email',            'value' => $old['email'],                     'inline' => false],
                    ['name' => 'Domain',           'value' => $domain !== '' ? $domain : 'None provided', 'inline' => false],
                ],
                'footer'    => ['text' => 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')],
                'timestamp' => gmdate('c'),
            ]],
        ];

        if (sendToDiscord($webhookUrl, $payload)) {
            $_SESSION['flash_success'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303); // avoids duplicate posts on refresh
            exit;
        }

        unset($_SESSION['last_submit']);
        $errors['form'] = 'We could not send your request. Try again in a minute.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Registration Form</title>
<style>
    /* web page style/color can be adjusted here */
    :root {
        --bg: #181818;
        --surface: #1e1e1e;
        --ink: #ffffff;
        --muted: #d19fd0;
        --line: #622a6b;
        --accent: #842dab;
        --accent-ink: #ffffff;
        --error: #b3261e;
        --ok: #1e6b45;
        --ok-bg: #e3f2ea;
        --err-bg: #fbe9e7;
    }
    @media (prefers-color-scheme: dark) {
        :root {
        --bg: #181818;
        --surface: #1e1e1e;
        --ink: #ffffff;
        --muted: #d19fd0;
        --line: #622a6b;
        --accent: #842dab;
        --accent-ink: #ffffff;
        --error: #b3261e;
        --ok: #33003f;
        --ok-bg: #cd87e3;
        --err-bg: #a2a2a2;
        }
    }
    * { box-sizing: border-box; }
    html { background: var(--bg); }
    body {
        margin: 0;
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: 24px 16px;
        font-family: "Avenir Next", "Segoe UI", "Helvetica Neue", Arial, sans-serif;
        color: var(--ink);
        line-height: 1.5;
    }
    main {
        width: 100%;
        max-width: 480px;
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 10px;
        padding: 32px 28px;
    }
    h1 { margin: 0 0 4px; font-size: 1.6rem; line-height: 1.2; letter-spacing: -0.01em; }
    .lede { margin: 0 0 24px; color: var(--muted); }
    .field { margin-bottom: 20px; }
    label { display: block; margin-bottom: 6px; font-weight: 600; }
    .hint { display: block; margin-top: 6px; font-size: 0.875rem; color: var(--muted); }
    .optional { font-weight: 400; color: var(--muted); }
    input[type="text"], input[type="email"], select {
        width: 100%;
        padding: 11px 12px;
        font: inherit;
        color: var(--ink);
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 6px;
    }
    input:focus-visible, select:focus-visible, button:focus-visible {
        outline: 3px solid var(--accent);
        outline-offset: 2px;
    }
    .has-error input, .has-error select { border-color: var(--error); }
    .error { margin-top: 6px; font-size: 0.875rem; color: var(--error); }
    button {
        width: 100%;
        padding: 13px 16px;
        font: inherit;
        font-weight: 600;
        color: var(--accent-ink);
        background: var(--accent);
        border: 0;
        border-radius: 6px;
        cursor: pointer;
    }
    button:hover { filter: brightness(1.08); }
    .banner { margin-bottom: 20px; padding: 12px 14px; border-radius: 6px; font-size: 0.95rem; }
    .banner.ok { color: var(--ok); background: var(--ok-bg); }
    .banner.err { color: var(--error); background: var(--err-bg); }
    .trap { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
</style>
</head>
<body>
<main>
    <h1>Register for a free hosted service</h1>
    <p class="lede">Tell us what you need and how to reach you, we'll set you up and then follow up on discord.</p>

    <?php if ($success): ?>
        <div class="banner ok" role="status">Registration request sent. We'll be in touch soon.</div>
    <?php endif; ?>

    <?php if (isset($errors['form'])): ?>
        <div class="banner err" role="alert"><?= e($errors['form']) ?></div>
    <?php endif; ?>

    <form method="post" action="" novalidate>
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

        <div class="trap" aria-hidden="true">
            <label for="website">Leave this field empty</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <div class="field<?= isset($errors['service']) ? ' has-error' : '' ?>">
            <label for="service">Service</label>
            <select id="service" name="service" required aria-describedby="service-error">
                <option value="" disabled<?= $old['service'] === '' ? ' selected' : '' ?>>Choose a service</option>
                <?php foreach ($services as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $old['service'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['service'])): ?><div class="error" id="service-error"><?= e($errors['service']) ?></div><?php endif; ?>
        </div>

        <div class="field<?= isset($errors['discord']) ? ' has-error' : '' ?>">
            <label for="discord">Discord username</label>
            <input type="text" id="discord" name="discord" required maxlength="64"
                   autocomplete="off" spellcheck="false" placeholder="yourname"
                   value="<?= e($old['discord']) ?>" aria-describedby="discord-error">
            <?php if (isset($errors['discord'])): ?><div class="error" id="discord-error"><?= e($errors['discord']) ?></div><?php endif; ?>
        </div>

        <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" required maxlength="254"
                   autocomplete="email" placeholder="name@example.com"
                   value="<?= e($old['email']) ?>" aria-describedby="email-error">
            <?php if (isset($errors['email'])): ?><div class="error" id="email-error"><?= e($errors['email']) ?></div><?php endif; ?>
        </div>

        <div class="field<?= isset($errors['domain']) ? ' has-error' : '' ?>">
            <label for="domain">Domain name <span class="optional">(optional)</span></label>
            <input type="text" id="domain" name="domain" maxlength="253"
                   autocomplete="off" spellcheck="false" inputmode="url" placeholder="example.com"
                   value="<?= e($old['domain']) ?>" aria-describedby="domain-hint domain-error">
            <span class="hint" id="domain-hint">Already have a domain or subdomain that you want to use? Enter it here.</span>
            <?php if (isset($errors['domain'])): ?><div class="error" id="domain-error"><?= e($errors['domain']) ?></div><?php endif; ?>
        </div>

        <button type="submit">Submit Registration Request</button>
    </form>
</main>
</body>
</html>
