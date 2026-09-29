<?php
/**
 * MANABIYA Bースタディ 講師採用ページ 応募受付（Xserver 用）
 *
 * 設置先（例）: https://beducate.jp/recruit-api/apply.php
 * 動作:
 *   1) 応募フォーム（recruit.beducate.jp）からの POST(multipart) を受け取る
 *   2) 入力と添付（履歴書・職務経歴書）をサーバー側で検証する
 *   3) 塾（ADMIN_TO）へ通知メール（添付付き）を送る
 *   4) 応募者へ確認メールを送る
 *   5) JSON で結果を返す  { ok: true|false, confirm: true|false, error?: "..." }
 *
 * 設定は下の「設定」だけ書き換えてください。
 */

/* ════════════════ 設定 ════════════════ */
const ADMIN_TO      = 'manabiya.b-study@outlook.jp';           // 応募通知の送信先（塾）
const MAIL_FROM     = 'recruit@beducate.jp';                   // 送信元（Xserver に作成したメールアカウント）
const MAIL_FROM_NAME = 'MANABIYA Bースタディ 採用担当';
const REPLY_TO      = 'manabiya.b-study@outlook.jp';           // 応募者が返信したときの宛先
const ALLOWED_ORIGINS = ['https://recruit.beducate.jp'];       // このページからの送信だけ許可
const MAX_FILE_BYTES  = 5 * 1024 * 1024;                       // 添付 1 ファイルの上限（5MB）
const RATE_PER_IP_PER_HOUR = 6;                                // 同一IPからの上限（1時間あたり）
const RATE_PER_EMAIL_PER_DAY = 3;                              // 同一メールアドレスの上限（1日あたり）
const MIN_ELAPSED_MS  = 3000;                                  // ページ表示から送信までの最短時間（ボット対策）
const TEL_DISPLAY     = '0858-27-1991';
/* ══════════════════════════════════════ */

mb_language('Japanese');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

function respond(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── CORS：許可したオリジンのみ ── */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    if (!in_array($origin, ALLOWED_ORIGINS, true)) respond(403, ['ok' => false, 'error' => 'forbidden']);
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(405, ['ok' => false, 'error' => 'method']);

/* ── 入力ヘルパー ── */
function s(string $key, int $max = 200): string {
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) return '';
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}
function oneLine(string $v): string { return trim(preg_replace('/\s+/u', ' ', $v) ?? ''); }
function list_of(string $key, array $allowed): string {
    $v = $_POST[$key] ?? [];
    if (!is_array($v)) $v = [$v];
    $out = [];
    foreach ($v as $x) if (is_string($x) && in_array($x, $allowed, true)) $out[$x] = true;
    return $out ? implode('、', array_keys($out)) : '未選択';
}

/* ── ボット対策：ハニーポット／表示直後の送信は「成功」を装って破棄 ── */
$elapsed = (int)($_POST['elapsed'] ?? 0);
if (s('hp_url') !== '' || $elapsed < MIN_ELAPSED_MS) {
    respond(200, ['ok' => true, 'confirm' => true]);
}

/* ── レート制限（IP／メール） ── */
function rate_hit(string $key, int $limit, int $windowSec): bool {
    $file = sys_get_temp_dir() . '/bstudy_rate_' . hash('sha256', $key);
    $now = time(); $hits = [];
    if (is_file($file)) {
        $j = json_decode((string)@file_get_contents($file), true);
        if (is_array($j)) $hits = array_values(array_filter($j, fn($t) => is_int($t) && $t > $now - $windowSec));
    }
    if (count($hits) >= $limit) return true;
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return false;
}

/* ── 検証 ── */
$name      = oneLine(s('name', 100));
$furigana  = oneLine(s('furigana', 100));
$age       = oneLine(s('age', 30));
$status    = oneLine(s('status', 50));
$email     = mb_convert_kana(s('email', 254), 'as');
$email     = preg_replace('/\s+/', '', $email) ?? '';
$phone     = mb_convert_kana(s('phone', 30), 'ns');
$phone     = preg_replace('/[‐-―−ー－]/u', '-', preg_replace('/\s+/', '', $phone) ?? '') ?? '';
$motivation = s('motivation', 3000);
$agree     = s('agree', 5) === '1';

$errors = [];
if ($name === '') $errors[] = 'name';
if ($furigana === '') $errors[] = 'furigana';
if ($age === '') $errors[] = 'age';
if ($status === '') $errors[] = 'status';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n,;<>]/', $email)) $errors[] = 'email';
if (!preg_match('/^0\d{1,4}-?\d{1,4}-?\d{3,4}$/', $phone)) $errors[] = 'phone';
if (!$agree) $errors[] = 'agree';

$exts = ['pdf' => ['application/pdf'],
         'doc' => ['application/msword', 'application/octet-stream'],
         'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
         'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png']];
$labels = ['resume' => '履歴書', 'cv' => '職務経歴書'];
$attachments = [];
foreach ($labels as $key => $label) {
    $f = $_FILES[$key] ?? null;
    if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $errors[] = $key; continue; }
    if ($f['size'] <= 0 || $f['size'] > MAX_FILE_BYTES) { $errors[] = $key . '_size'; continue; }
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!isset($exts[$ext])) { $errors[] = $key . '_type'; continue; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    if (!in_array($mime, $exts[$ext], true)) { $errors[] = $key . '_type'; continue; }
    $data = file_get_contents($f['tmp_name']);
    if ($data === false) { $errors[] = $key; continue; }
    $safe = preg_replace('/[^\p{L}\p{N}._\-]/u', '_', pathinfo((string)$f['name'], PATHINFO_FILENAME)) ?? 'file';
    $safe = mb_substr(trim($safe, '._'), 0, 60) ?: 'file';
    $attachments[] = ['label' => $label, 'filename' => $label . '_' . $safe . '.' . $ext, 'mime' => $mime, 'data' => $data];
}
if ($errors) respond(422, ['ok' => false, 'error' => 'validation', 'fields' => $errors]);

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (rate_hit('ip:' . $ip, RATE_PER_IP_PER_HOUR, 3600) || rate_hit('mail:' . strtolower($email), RATE_PER_EMAIL_PER_DAY, 86400)) {
    respond(429, ['ok' => false, 'error' => 'rate']);
}

$days     = list_of('days', ['月', '火', '水', '木', '金', '土', '日']);
$subjects = list_of('subjects', ['国語', '数学', '英語', '理科', '社会']);
$wwork    = list_of('wwork', ['あり（メインは別にある）', 'なし（こちらがメイン）']);
$sentAt   = date('Y/n/j H:i');
if ($motivation === '') $motivation = '（未記入）';

/* ── メール組み立て ── */
function enc_header(string $v): string { return mb_encode_mimeheader($v, 'UTF-8', 'B', "\r\n"); }
function from_header(): string { return enc_header(MAIL_FROM_NAME) . ' <' . MAIL_FROM . '>'; }
function build_mail(string $to, string $subject, string $body, array $headersExtra, array $attachments = []): bool {
    $boundary = 'bstudy_' . bin2hex(random_bytes(12));
    $h = [
        'From: ' . from_header(),
        'MIME-Version: 1.0',
        'Message-ID: <' . bin2hex(random_bytes(10)) . '@beducate.jp>',
        'X-Mailer: bstudy-apply',
    ];
    foreach ($headersExtra as $x) $h[] = $x;
    $text = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body));
    if ($attachments) {
        $h[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $msg = "--$boundary\r\n" . $text;
        foreach ($attachments as $a) {
            $fn = enc_header($a['filename']);
            $msg .= "--$boundary\r\nContent-Type: {$a['mime']}; name=\"$fn\"\r\nContent-Transfer-Encoding: base64\r\n"
                  . "Content-Disposition: attachment; filename=\"$fn\"\r\n\r\n" . chunk_split(base64_encode($a['data']));
        }
        $msg .= "--$boundary--\r\n";
    } else {
        $h[] = 'Content-Type: text/plain; charset=UTF-8';
        $h[] = 'Content-Transfer-Encoding: base64';
        $msg = chunk_split(base64_encode($body));
    }
    return mail($to, enc_header($subject), $msg, implode("\r\n", $h), '-f' . MAIL_FROM);
}

$adminBody = "新しい応募が届きました。\n\n"
  . "お名前　：{$name}（{$furigana}）\n年齢　　：{$age}\n現在状況：{$status}\n"
  . "メール　：{$email}\n電話　　：{$phone}\n希望曜日：{$days}\n希望科目：{$subjects}\nWワーク：{$wwork}\n\n"
  . "【志望動機・ご質問】\n{$motivation}\n\n"
  . "【添付書類】\n" . implode("\n", array_map(fn($a) => '・' . $a['filename'] . '（' . round(strlen($a['data']) / 1024) . 'KB）', $attachments)) . "\n\n"
  . "応募日時：{$sentAt}\n（このメールに返信すると、応募者へ返信できます）\n";
$adminOk = build_mail(ADMIN_TO, "【講師応募】{$name}様よりご応募がありました", $adminBody,
    ['Reply-To: ' . enc_header($name) . ' <' . $email . '>'], $attachments);
if (!$adminOk) {
    error_log('[apply.php] admin mail failed for ' . $email);
    respond(500, ['ok' => false, 'error' => 'mail']);
}

$userBody = "{$name} 様\n\nこの度は MANABIYA Bースタディ の塾講師募集へご応募いただき、誠にありがとうございます。\n"
  . "以下の内容で受け付けました。\n\n"
  . "お名前　：{$name}\nメール　：{$email}\n電話　　：{$phone}\n希望科目：{$subjects}\n"
  . "提出書類：履歴書・職務経歴書（添付を受け取りました）\n\n"
  . "担当者より2〜3営業日以内にご連絡いたします。\n"
  . "しばらく経っても連絡がない場合は、迷惑メールフォルダをご確認のうえ、お問い合わせください。\n\n"
  . "━━━━━━━━━━━━━━━━━━━━━━━━\n"
  . "MANABIYA Bースタディ（株式会社beducate）\n"
  . "TEL：" . TEL_DISPLAY . "\nmail：" . REPLY_TO . "\n〒682-0016 鳥取県倉吉市海田西町2丁目95\nhttps://recruit.beducate.jp/\n"
  . "━━━━━━━━━━━━━━━━━━━━━━━━\n※このメールは自動送信です。\n";
$userOk = build_mail($email, '【MANABIYA Bースタディ】ご応募ありがとうございます', $userBody,
    ['Reply-To: ' . REPLY_TO, 'Auto-Submitted: auto-generated']);
if (!$userOk) error_log('[apply.php] confirmation mail failed for ' . $email);

respond(200, ['ok' => true, 'confirm' => (bool)$userOk]);
