<?php
/**
 * ✅ خودآزمونِ توابع خالصِ stt.php
 * ------------------------------------------------------------------
 * این اسکریپت خودِ توابع واقعی stt.php را صدا می‌زند (نه یک کپی از آنها)
 * و شکل‌های مختلف پاسخ ارائه‌دهنده‌ها را به آنها می‌دهد.
 *
 * اجرا:   php tools/stt_selftest.php
 * خروجی:  PASS/FAIL برای هر مورد + کد خروج ۱ در صورت هر شکست
 */

require_once __DIR__ . '/../stt.php';

$pass = 0; $fail = 0;
function check($label, $got, $expected) {
    global $pass, $fail;
    $ok = ($got === $expected);
    if ($ok) { $pass++; } else { $fail++; }
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) {
        echo '        انتظار: ' . var_export($expected, true) . "\n";
        echo '        دریافت: ' . var_export($got, true) . "\n";
    }
}

echo "=== stt_extract_text — نرمال‌سازی شکل‌های مختلف پاسخ ===\n";

// ۱) Hugging Face ASR: آرایه  ← همان باگی که باعث می‌شد متن دور ریخته شود
check('HF array [{"text":"سلام"}]',
      stt_extract_text(json_decode('[{"text":"سلام"}]', true)),
      'سلام');

// ۲) OpenAI / Groq: آبجکت
check('OpenAI object {"text":"سلام"}',
      stt_extract_text(json_decode('{"text":"سلام"}', true)),
      'سلام');

// ۳) آبجکت با فاصلهٔ اضافی → trim شود
check('trim شدن فاصله‌ها',
      stt_extract_text(['text' => "   سلام دنیا  \n"]),
      'سلام دنیا');

// ۴) Whisper با خروجی تکه‌تکه
check('chunks به هم چسبانده شوند',
      stt_extract_text(json_decode('{"chunks":[{"text":"یک "},{"text":"آپارتمان"}]}', true)),
      'یک آپارتمان');

// ۵) تودرتو
check('شکل تودرتو output.text',
      stt_extract_text(['output' => ['text' => 'متن تودرتو']]),
      'متن تودرتو');

// ۶) فقط خطا → باید رشتهٔ خالی بدهد نه خطا
check('پاسخ فقط خطا → متن خالی',
      stt_extract_text(['error' => 'Model is loading']),
      '');

// ۷) ورودی نامعتبر
check('ورودی null → متن خالی', stt_extract_text(null), '');
check('ورودی رشته → متن خالی', stt_extract_text('not-json'), '');
check('آرایهٔ خالی → متن خالی', stt_extract_text([]), '');

echo "\n=== stt_extract_error — پیام خطای خوانا ===\n";
check('error رشته‌ای', stt_extract_error(['error' => 'Model not found'], 404), 'Model not found');
check('error تودرتو با message',
      stt_extract_error(['error' => ['message' => 'Invalid token']], 401), 'Invalid token');
check('بدون فیلد خطا → پیام پیش‌فرض با کد وضعیت',
      stt_extract_error(['foo' => 'bar'], 503), 'پاسخ ناموفق از موتور تبدیل صوت (HTTP 503).');

echo "\n=== stt_mime_for — نگاشت پسوند به MIME ===\n";
check('wav',  stt_mime_for('voice_command.wav'),  'audio/wav');
check('webm', stt_mime_for('voice_command.webm'), 'audio/webm');
check('mp3',  stt_mime_for('a.MP3'),              'audio/mpeg');
check('flac', stt_mime_for('a.flac'),             'audio/flac');
check('پسوند ناشناخته', stt_mime_for('evil.php'), 'application/octet-stream');
check('بدون پسوند',     stt_mime_for('noext'),    'application/octet-stream');

echo "\n=== stt_validate_upload — اعتبارسنجی ===\n";
check('فایل موجود نیست',
      stt_validate_upload(null)['error'], 'فایل صوتی در درخواست وجود ندارد.');
check('خطای UPLOAD_ERR_NO_FILE',
      stt_validate_upload(['error' => UPLOAD_ERR_NO_FILE, 'name' => 'a.wav', 'size' => 10, 'tmp_name' => '/tmp/x'])['ok'],
      false);
check('حجم بیش از سقف',
      stt_validate_upload(['error' => UPLOAD_ERR_OK, 'name' => 'a.wav', 'size' => STT_MAX_BYTES + 1, 'tmp_name' => '/tmp/x'])['ok'],
      false);
check('حجم صفر',
      stt_validate_upload(['error' => UPLOAD_ERR_OK, 'name' => 'a.wav', 'size' => 0, 'tmp_name' => '/tmp/x'])['ok'],
      false);
check('پسوند غیرمجاز (php)',
      stt_validate_upload(['error' => UPLOAD_ERR_OK, 'name' => 'shell.php', 'size' => 100, 'tmp_name' => '/tmp/x'])['ok'],
      false);

printf("\n=================================================\nنتیجه: %d PASS / %d FAIL\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
