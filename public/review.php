<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/lib/reviews.php';

$reviewsAi = __DIR__ . '/../common/lib/reviews_ai.php';
if (is_file($reviewsAi)) {
    require_once $reviewsAi;
}

header('Content-Type: text/html; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$id    = '';
$token = '';

if ($method === 'POST') {
    $id    = trim((string)($_POST['id'] ?? ''));
    $token = trim((string)($_POST['token'] ?? ''));
} else {
    $id    = trim((string)($_GET['id'] ?? ''));
    $token = trim((string)($_GET['token'] ?? ''));
}

$error    = '';
$message  = '';
$showForm = true;
$existing = null;

// Simple HTML escaper
$h = static function (?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

// Basic validation of id/token presence
if ($id === '' || $token === '') {
    $error    = 'This review link is invalid. Please contact the host if you think this is a mistake.';
    $showForm = false;
    render_review_page($h, $id, $token, $existing, $error, $message, $showForm);
    exit;
}

// Verify token & expiry (obstoječa helper funkcija v reviews.php)
$expiresTs = null;
if (!cm_review_verify_token($id, $token, $expiresTs)) {
    $error    = 'This review link is invalid or has expired. If you would still like to share your experience, please contact the host.';
    $showForm = false;
    render_review_page($h, $id, $token, $existing, $error, $message, $showForm);
    exit;
}

if ($method === 'POST') {
    // Handle create / update
    $ratingRaw = $_POST['rating'] ?? '';
    $rating    = (int)$ratingRaw;
    $text      = trim((string)($_POST['text'] ?? ''));
    $display   = trim((string)($_POST['display_name'] ?? ''));
    $publicAllowed = isset($_POST['public_allowed']) && $_POST['public_allowed'] === '1';

    if ($rating < 1 || $rating > 5) {
        $error = 'Please select a rating between 1 and 5 stars.';
    }

    if (mb_strlen($text) > 2000) {
        $text = mb_substr($text, 0, 2000);
    }

    if ($display === '') {
        $display = 'Guest';
    }

    if ($error === '') {
        // Optional reservation meta (unit, from, to…)
        $meta = cm_review_load_basic_reservation_meta($id) ?? [];

        // AI + heuristic analiza (centralni engine v reviews_ai.php)
    if (function_exists('cm_review_analyze_text')) {
        $analysis = cm_review_analyze_text($rating, $text);
    } else {
        $analysis = [
        'status'       => 'approved',
        'is_flagged'   => false,
        'flag_reason'  => '',
        'risk_score'   => 0,
        'toxicity'     => 0,
        'ai_category'  => '',
        'ai_decision'  => 'no_ai_module',
        'processed_at' => date('c'),
        'sentiment'    => 0,
        ];
    }

        // Zgradimo finalni zapis reviewja
        $fields = array_merge($meta, [
            'rating'         => $rating,
            'text'           => $text,
            'display_name'   => $display,
            'public_allowed' => $publicAllowed,
            'visible'        => ($analysis['status'] === 'approved'),

            // AI polja
            'status'        => $analysis['status'],
            'is_flagged'    => $analysis['is_flagged'],
            'flag_reason'   => $analysis['flag_reason'],
            'risk_score'    => $analysis['risk_score'],
            'toxicity'      => $analysis['toxicity'],
            'ai_category'   => $analysis['ai_category'],
            'ai_decision'   => $analysis['ai_decision'],
            'processed_at'  => $analysis['processed_at'],
            'sentiment'     => $analysis['sentiment'] ?? 0,

            // čas oddaje
            'timestamp'     => time(),

            // za kasnejši e-mail helper (ni občutljiv podatek)
            'reservation_id' => $id,
        ]);

        // Shranimo / posodobimo review za rezervacijo
        cm_review_save_for_reservation($id, $fields);

        // Če je AI dal review v karanteno → pošljemo prijazno obvestilo (če helper obstaja)
        if (
            ($fields['status'] ?? '') === 'quarantine' &&
            function_exists('cm_review_notify_guest_quarantine')
        ) {
            cm_review_notify_guest_quarantine($fields);
        }

        // Posodobimo meta podatke rezervacije (stub)
        cm_review_update_reservation_review_meta($id, $rating, $publicAllowed);

        // Rebuild landing-page JSON za index.html (public vidijo samo approved)
        cm_review_rebuild_public_files();

        $message = 'Thank you for your feedback. Your review has been saved.';

        $existing = [
            'rating'         => $rating,
            'text'           => $text,
            'display_name'   => $display,
            'public_allowed' => $publicAllowed,
        ];
    }
} else {
    // GET: pre-fill form if review already exists (edit mode)
    $existing = cm_review_get_for_reservation($id);
}

render_review_page($h, $id, $token, $existing, $error, $message, $showForm);

/**
 * Render HTML page with review form or messages.
 *
 * @param callable                 $h        HTML escaper
 * @param array<string,mixed>|null $existing Existing review data
 */
function render_review_page(
    callable $h,
    string $id,
    string $token,
    ?array $existing,
    string $error,
    string $message,
    bool $showForm
): void {
    $rating        = (int)($existing['rating'] ?? 0);
    $text          = (string)($existing['text'] ?? '');
    $displayName   = (string)($existing['display_name'] ?? '');
    $publicAllowed = array_key_exists('public_allowed', $existing ?? [])
        ? (bool)$existing['public_allowed']
        : true;

    $title = 'Rate your stay';
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= $h($title) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root{
      color-scheme: dark;
      --bg:#050608;
      --panel:#101218;
      --panel-2:#161824;
      --border:#242637;
      --accent:#ffb347;
      --accent-soft:#3a3320;
      --fg:#f5f5f8;
      --muted:#a0a3b0;
      --danger:#ff6b6b;
    }
    *{box-sizing:border-box;}
    body{
      margin:0;
      font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif;
      background:radial-gradient(circle at top,#141727 0,#050608 55%);
      color:var(--fg);
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:18px 12px;
    }
    .wrap{
      width:100%;
      max-width:540px;
      background:linear-gradient(135deg,#121525,#050608);
      border-radius:18px;
      border:1px solid var(--border);
      box-shadow:0 20px 60px rgba(0,0,0,.7);
      padding:18px 18px 16px;
    }
    h1{
      margin:0 0 .35rem;
      font-size:1.3rem;
    }
    p{
      margin:.25rem 0 .4rem;
      font-size:.95rem;
    }
    .muted{color:var(--muted);font-size:.9rem;}
    .badge{
      display:inline-flex;
      align-items:center;
      gap:.3rem;
      border-radius:999px;
      border:1px solid var(--border);
      padding:.18rem .6rem;
      font-size:.78rem;
      margin-bottom:.35rem;
      color:var(--muted);
    }
    .badge-star{
      color:var(--accent);
      font-size:.9rem;
    }
    .error{
      margin:.4rem 0;
      padding:.4rem .55rem;
      border-radius:10px;
      border:1px solid var(--danger);
      background:rgba(255,107,107,.08);
      color:var(--danger);
      font-size:.9rem;
    }
    .success{
      margin:.4rem 0;
      padding:.4rem .55rem;
      border-radius:10px;
      border:1px solid rgba(120,207,120,.9);
      background:rgba(120,207,120,.08);
      color:#c9f7c9;
      font-size:.9rem;
    }
    form{
      margin-top:.4rem;
    }
    .field{
      margin:.45rem 0 .6rem;
    }
    .label{
      display:block;
      font-size:.9rem;
      margin-bottom:.18rem;
    }
    .rating-options{
      display:flex;
      gap:.4rem;
      align-items:center;
      flex-wrap:wrap;
    }
    .rating-pill{
      position:relative;
    }
    .rating-pill input{
      position:absolute;
      inset:0;
      opacity:0;
      cursor:pointer;
    }
    .rating-pill span{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:34px;
      padding:.18rem .4rem;
      border-radius:999px;
      border:1px solid var(--border);
      font-size:.9rem;
      color:var(--muted);
      background:rgba(0,0,0,.2);
      transition:all .12s ease;
    }
    .rating-pill input:checked + span{
      border-color:var(--accent);
      background:var(--accent-soft);
      color:#fff;
    }
    textarea,
    input[type="text"]{
      width:100%;
      border-radius:10px;
      border:1px solid var(--border);
      padding:.35rem .45rem;
      font-size:.92rem;
      background:var(--panel);
      color:var(--fg);
      resize:vertical;
      min-height:70px;
    }
    input[type="text"]{
      min-height:auto;
      height:2.1rem;
    }
    textarea:focus,
    input[type="text"]:focus{
      outline:none;
      border-color:var(--accent);
      box-shadow:0 0 0 1px rgba(255,179,71,.4);
    }
    .small{
      font-size:.82rem;
      color:var(--muted);
      margin-top:.15rem;
    }
    .checkbox-row{
      display:flex;
      align-items:flex-start;
      gap:.4rem;
      margin-top:.4rem;
      font-size:.86rem;
      color:var(--muted);
    }
    .checkbox-row input[type="checkbox"]{
      margin-top:.15rem;
    }
    .actions{
      margin-top:.7rem;
      display:flex;
      justify-content:flex-end;
    }
    button[type="submit"]{
      border:none;
      border-radius:999px;
      padding:.35rem .9rem;
      font-size:.92rem;
      cursor:pointer;
      background:var(--accent);
      color:#111;
      font-weight:500;
      box-shadow:0 8px 20px rgba(0,0,0,.55);
      transition:transform .12s ease, box-shadow .12s ease, background .12s ease;
    }
    button[type="submit"]:hover{
      transform:translateY(-1px);
      box-shadow:0 12px 26px rgba(0,0,0,.7);
      background:#ffcb70;
    }
    button[type="submit"]:active{
      transform:translateY(0);
      box-shadow:0 6px 18px rgba(0,0,0,.55);
    }
    .footer-note{
      margin-top:.7rem;
      font-size:.8rem;
      color:var(--muted);
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="badge">
      <span class="badge-star">★</span>
      <span>Short, honest feedback from your stay</span>
    </div>

    <h1><?= $h($title) ?></h1>
    <p class="muted">
      Please rate your overall experience and (optionally) add a short comment.
      You can adjust your review later using the same link, as long as it remains valid.
    </p>

    <?php if ($error !== ''): ?>
      <div class="error"><?= $h($error) ?></div>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
      <div class="success"><?= $h($message) ?></div>
    <?php endif; ?>

    <?php if ($showForm): ?>
    <form method="post" novalidate>
      <input type="hidden" name="id" value="<?= $h($id) ?>">
      <input type="hidden" name="token" value="<?= $h($token) ?>">

      <div class="field">
        <span class="label">Overall rating</span>
        <div class="rating-options">
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <label class="rating-pill">
              <input type="radio" name="rating" value="<?= $i ?>" <?= $rating === $i ? 'checked' : '' ?>>
              <span><?= $i ?>★</span>
            </label>
          <?php endfor; ?>
        </div>
        <div class="small">1 = poor, 5 = excellent</div>
      </div>

      <div class="field">
        <label class="label" for="review-text">Your comment (optional)</label>
        <textarea id="review-text" name="text" maxlength="2000"><?= $h($text) ?></textarea>
        <div class="small">You can mention what you liked most or anything we could improve.</div>
      </div>

      <div class="field">
        <label class="label" for="display-name">Name to display (optional)</label>
        <input type="text" id="display-name" name="display_name"
               value="<?= $h($displayName) ?>" maxlength="80">
        <div class="small">Example: "Maja P." or "Family Novak". If empty, we will show "Guest".</div>
      </div>

      <div class="checkbox-row">
        <input type="checkbox" id="public-allowed" name="public_allowed" value="1"
               <?= $publicAllowed ? 'checked' : '' ?>>
        <label for="public-allowed">
          I agree that this review (rating and comment) may be shown anonymously on the apartment website.
        </label>
      </div>

      <div class="actions">
        <button type="submit" name="submit">Save review</button>
      </div>

      <p class="footer-note">
        If you prefer to send private feedback only, you can uncheck the sharing option above.
        Your host will still see your comment, but it will not be displayed publicly.
      </p>
    </form>
    <?php endif; ?>
  </div>
</body>
</html>
<?php
}
