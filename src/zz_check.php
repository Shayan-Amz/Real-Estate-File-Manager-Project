<?php
/**
 * 🧪 حداقل تست اجرای PHP روی هاست — بدون config، بدون دیتابیس، بدون هیچ وابستگی.
 * اگر این فایل خالی/۵۰۰ بدهد یعنی مشکل از اجرای PHP است؛ اگر "ZZ-PHP-OK" بدهد
 * یعنی PHP سالم است و مشکل، کش قدیمی مرورگر (سرویس‌ورکر) است.
 */
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
echo 'ZZ-PHP-OK ' . PHP_VERSION . ' (' . PHP_SAPI . ')' . "\n";
