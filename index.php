<?php
// ==================== LOAD ENVIRONMENT (Manual - No Composer) ====================

function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        return false;
    }
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            if (!isset($_ENV[$key]) && !getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
    return true;
}

// Load .env file
loadEnv(__DIR__ . '/.env');

// ==================== CONFIGURATION ====================
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: $_ENV['BOT_TOKEN'] ?? '');
define('BOT_ID', getenv('BOT_ID') ?: $_ENV['BOT_ID'] ?? '');
define('BOT_USERNAME', getenv('BOT_USERNAME') ?: $_ENV['BOT_USERNAME'] ?? '');

// Public Channels
define('MAIN_CHANNEL_ID', getenv('MAIN_CHANNEL_ID') ?: $_ENV['MAIN_CHANNEL_ID'] ?? '');
define('MAIN_CHANNEL_USERNAME', getenv('MAIN_CHANNEL_USERNAME') ?: $_ENV['MAIN_CHANNEL_USERNAME'] ?? '');
define('SERIAL_CHANNEL_ID', getenv('SERIAL_CHANNEL_ID') ?: $_ENV['SERIAL_CHANNEL_ID'] ?? '');
define('SERIAL_CHANNEL_USERNAME', getenv('SERIAL_CHANNEL_USERNAME') ?: $_ENV['SERIAL_CHANNEL_USERNAME'] ?? '');
define('THEATER_CHANNEL_ID', getenv('THEATER_CHANNEL_ID') ?: $_ENV['THEATER_CHANNEL_ID'] ?? '');
define('THEATER_CHANNEL_USERNAME', getenv('THEATER_CHANNEL_USERNAME') ?: $_ENV['THEATER_CHANNEL_USERNAME'] ?? '');
define('BACKUP_CHANNEL_ID', getenv('BACKUP_CHANNEL_ID') ?: $_ENV['BACKUP_CHANNEL_ID'] ?? '');
define('BACKUP_CHANNEL_USERNAME', getenv('BACKUP_CHANNEL_USERNAME') ?: $_ENV['BACKUP_CHANNEL_USERNAME'] ?? '');

// Private Channels
define('PRIVATE_CHANNEL_1', getenv('PRIVATE_CHANNEL_1') ?: $_ENV['PRIVATE_CHANNEL_1'] ?? '');
define('PRIVATE_CHANNEL_2', getenv('PRIVATE_CHANNEL_2') ?: $_ENV['PRIVATE_CHANNEL_2'] ?? '');

// Request Group
define('REQUEST_GROUP_ID', getenv('REQUEST_GROUP_ID') ?: $_ENV['REQUEST_GROUP_ID'] ?? '');
define('REQUEST_GROUP_USERNAME', getenv('REQUEST_GROUP_USERNAME') ?: $_ENV['REQUEST_GROUP_USERNAME'] ?? '');

// Files
define('CSV_FILE', 'movies.csv');
define('USERS_FILE', 'users.json');
define('STATS_FILE', 'bot_stats.json');
define('BACKUP_DIR', 'backups/');
define('ADMIN_IDS_FILE', 'admin_ids.json');

// Settings
define('CACHE_EXPIRY', intval(getenv('CACHE_EXPIRY') ?: $_ENV['CACHE_EXPIRY'] ?? 300));
define('ITEMS_PER_PAGE', intval(getenv('ITEMS_PER_PAGE') ?: $_ENV['ITEMS_PER_PAGE'] ?? 5));

// Maintenance Mode
$MAINTENANCE_MODE = filter_var(getenv('MAINTENANCE_MODE') ?: $_ENV['MAINTENANCE_MODE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

// Error Reporting
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// ==================== FILE INITIALIZATION ====================
if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode(['users' => [], 'total_requests' => 0]));
    @chmod(USERS_FILE, 0666);
}

if (!file_exists(STATS_FILE)) {
    file_put_contents(STATS_FILE, json_encode([
        'total_movies' => 0, 'total_users' => 0, 'total_searches' => 0, 
        'total_forwards' => 0, 'last_updated' => date('Y-m-d H:i:s')
    ]));
    @chmod(STATS_FILE, 0666);
}

if (!file_exists(CSV_FILE)) {
    file_put_contents(CSV_FILE, "movie_name,message_id,channel_id\n");
    @chmod(CSV_FILE, 0666);
}

if (!file_exists(ADMIN_IDS_FILE)) {
    file_put_contents(ADMIN_IDS_FILE, json_encode(['admins' => ['1080317415']]));
    @chmod(ADMIN_IDS_FILE, 0644);
}

// ==================== SIMPLE TELEGRAM FUNCTIONS ====================
function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = 'HTML') {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $data = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => $parse_mode];
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];
    $context = stream_context_create($options);
    return json_decode(@file_get_contents($url, false, $context), true);
}

function forwardMessage($chat_id, $from_chat_id, $message_id) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/forwardMessage";
    $data = ['chat_id' => $chat_id, 'from_chat_id' => $from_chat_id, 'message_id' => $message_id];
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];
    $context = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

function answerCallbackQuery($callback_query_id, $text = null, $show_alert = false) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery";
    $data = ['callback_query_id' => $callback_query_id];
    if ($text) $data['text'] = $text;
    if ($show_alert) $data['show_alert'] = true;
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];
    $context = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

// ==================== SIMPLE SEARCH FUNCTION ====================
function search_movie($query) {
    if (!file_exists(CSV_FILE)) return [];
    
    $query = strtolower(trim($query));
    $results = [];
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle); // Skip header
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3) {
                $movie_name = strtolower($row[0]);
                if (strpos($movie_name, $query) !== false) {
                    $results[] = [
                        'name' => $row[0],
                        'message_id' => $row[1],
                        'channel_id' => $row[2]
                    ];
                }
            }
        }
        fclose($handle);
    }
    
    return $results;
}

// ==================== WEBHOOK HANDLER ====================
$update = json_decode(file_get_contents('php://input'), true);

if ($update && !$MAINTENANCE_MODE) {
    // Message Handler
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = trim($message['text'] ?? '');
        
        // Save user
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        if (!isset($users_data['users'][$user_id])) {
            $users_data['users'][$user_id] = [
                'first_name' => $message['from']['first_name'] ?? '',
                'username' => $message['from']['username'] ?? '',
                'joined' => date('Y-m-d H:i:s')
            ];
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
        }
        
        // Handle commands
        if (strpos($text, '/') === 0) {
            $cmd = explode(' ', $text)[0];
            
            if ($cmd == '/start') {
                $welcome = "🎬 <b>Welcome to Entertainment Tadka Bot!</b>\n\n";
                $welcome .= "🔍 Simply type any movie name to search.\n\n";
                $welcome .= "📝 Examples:\n";
                $welcome .= "• Mandala Murders\n";
                $welcome .= "• Zebra 2024\n";
                $welcome .= "• Show Time 2025\n\n";
                $welcome .= "📢 Channel: @EntertainmentTadka786";
                
                sendMessage($chat_id, $welcome);
            }
            elseif ($cmd == '/help') {
                $help = "🤖 <b>Bot Commands</b>\n\n";
                $help .= "/start - Welcome\n";
                $help .= "/help - This menu\n\n";
                $help .= "Just type any movie name to search!";
                sendMessage($chat_id, $help);
            }
            elseif ($cmd == '/channels') {
                $msg = "📢 <b>Our Channels</b>\n\n";
                $msg .= "🎬 @EntertainmentTadka786\n";
                $msg .= "📺 @Entertainment_Tadka_Serial_786\n";
                $msg .= "🎭 @threater_print_movies\n";
                $msg .= "💾 @ETBackup";
                sendMessage($chat_id, $msg);
            }
        }
        elseif (!empty($text) && strlen($text) > 2) {
            // Search for movie
            $results = search_movie($text);
            
            if (count($results) > 0) {
                $msg = "🔍 <b>Found " . count($results) . " results:</b>\n\n";
                $keyboard = ['inline_keyboard' => []];
                $shown = 0;
                
                foreach ($results as $result) {
                    if ($shown >= 10) break;
                    $name = htmlspecialchars(substr($result['name'], 0, 50));
                    $keyboard['inline_keyboard'][] = [['text' => "🎬 " . $name, 'callback_data' => "send_" . $result['message_id'] . "_" . $result['channel_id']]];
                    $shown++;
                }
                
                sendMessage($chat_id, $msg, $keyboard);
            } else {
                $msg = "😔 No results found for '<b>$text</b>'.\n\n";
                $msg .= "💬 Request: @EntertainmentTadka7860";
                sendMessage($chat_id, $msg);
            }
        }
    }
    
    // Callback Handler
    if (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $chat_id = $query['message']['chat']['id'];
        $data = $query['data'];
        
        if (strpos($data, 'send_') === 0) {
            $parts = explode('_', $data);
            $msg_id = $parts[1] ?? '';
            $channel_id = $parts[2] ?? MAIN_CHANNEL_ID;
            
            if (!empty($msg_id) && is_numeric($msg_id)) {
                forwardMessage($chat_id, $channel_id, $msg_id);
                answerCallbackQuery($query['id'], "✅ Movie sent!");
            } else {
                answerCallbackQuery($query['id'], "❌ Invalid", true);
            }
        }
    }
}

// ==================== WEBHOOK SETUP PAGE ====================
if (isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $webhook_url = str_replace('?setwebhook=1', '', $webhook_url);
    
    echo "<h1>🤖 Bot Webhook Setup</h1>";
    echo "<p><strong>Set webhook by visiting:</strong></p>";
    echo "<code>https://api.telegram.org/bot" . BOT_TOKEN . "/setWebhook?url=" . urlencode($webhook_url) . "</code>";
    exit;
}

// ==================== STATUS PAGE ====================
if (!isset($update) || !$update) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_movies = 0;
    if (file_exists(CSV_FILE)) {
        $lines = file(CSV_FILE);
        $total_movies = count($lines) - 1;
    }
    
    echo "<!DOCTYPE html>";
    echo "<html><head><title>Entertainment Tadka Bot</title>";
    echo "<style>body{font-family:Arial;background:#1a1a2e;color:#fff;padding:20px;}";
    echo ".container{max-width:800px;margin:0 auto;background:#16213e;border-radius:10px;padding:20px;}";
    echo "h1{color:#e94560;} .stat{background:#0f3460;padding:10px;margin:10px 0;border-radius:5px;}";
    echo "button{background:#e94560;border:none;padding:10px 20px;color:#fff;border-radius:5px;cursor:pointer;}</style>";
    echo "</head><body>";
    echo "<div class='container'>";
    echo "<h1>🎬 Entertainment Tadka Bot</h1>";
    echo "<p><strong>Status:</strong> ✅ Running</p>";
    echo "<p><strong>Bot:</strong> " . BOT_USERNAME . "</p>";
    
    echo "<div class='stat'>";
    echo "<h2>📊 Statistics</h2>";
    echo "<p>🎬 Movies: <strong>$total_movies</strong></p>";
    echo "<p>👥 Users: <strong>" . count($users_data['users'] ?? []) . "</strong></p>";
    echo "</div>";
    
    echo "<div class='stat'>";
    echo "<h2>🚀 Setup Webhook</h2>";
    echo "<p><a href='?setwebhook=1'><button>🔗 Setup Webhook</button></a></p>";
    echo "</div>";
    
    echo "<p><small>Last Updated: " . date('Y-m-d H:i:s') . "</small></p>";
    echo "</div></body></html>";
}
?>
