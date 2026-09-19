<?php
// Isolated defense deployment only. Never point this at the live database.
define('DB_HOST', 'localhost');
define('DB_NAME', 'DEFENSE_TEST_DATABASE');
define('DB_USER', 'DEFENSE_TEST_USER');
define('DB_PASS', 'CHANGE_TEST_DATABASE_PASSWORD');
// Generate independently with bin2hex(random_bytes(32)); do not commit config.php.
define('APP_SALT', 'CHANGE_TO_AN_INDEPENDENT_RANDOM_SECRET');
define('MASTER_PASSWORD_HASH', 'CHANGE_TO_A_PASSWORD_HASH_GENERATED_ON_THE_TEST_HOST');
define('ALLOWED_ORIGIN', '');
define('TRUST_PROXY_HEADER', false);
define('STT_PROVIDER', 'hf');
define('STT_MODEL', 'openai/whisper-large-v3');
define('STT_HF_TOKEN', '');
define('OPENROUTER_API_KEY', '');
define('OPENROUTER_URL', 'https://openrouter.ai/api/v1/chat/completions');
define('OPENROUTER_MODEL', 'CHANGE_PROVIDER/MODEL');
