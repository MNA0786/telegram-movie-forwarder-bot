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

if (!file_exists(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0777, true);
}

// ==================== HELPER FUNCTIONS ====================

function isAdmin($user_id) {
    if (!file_exists(ADMIN_IDS_FILE)) return false;
    $admins = json_decode(file_get_contents(ADMIN_IDS_FILE), true);
    return in_array((string)$user_id, $admins['admins'] ?? []);
}

function update_stats($field, $increment = 1) {
    if (!file_exists(STATS_FILE)) return;
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats[$field] = ($stats[$field] ?? 0) + $increment;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}

function get_stats() {
    if (!file_exists(STATS_FILE)) return [];
    return json_decode(file_get_contents(STATS_FILE), true);
}

function getPublicChannels() {
    return [
        ['id' => MAIN_CHANNEL_ID, 'username' => MAIN_CHANNEL_USERNAME, 'name' => '🎬 Main Channel', 'emoji' => '🎬'],
        ['id' => SERIAL_CHANNEL_ID, 'username' => SERIAL_CHANNEL_USERNAME, 'name' => '📺 Serial Channel', 'emoji' => '📺'],
        ['id' => THEATER_CHANNEL_ID, 'username' => THEATER_CHANNEL_USERNAME, 'name' => '🎭 Theater Print', 'emoji' => '🎭'],
        ['id' => BACKUP_CHANNEL_ID, 'username' => BACKUP_CHANNEL_USERNAME, 'name' => '💾 Backup Channel', 'emoji' => '💾']
    ];
}

// ==================== TELEGRAM API FUNCTIONS ====================

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
    $result = @file_get_contents($url, false, $context);
    return json_decode($result, true);
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

function sendTyping($chat_id) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendChatAction";
    $data = ['chat_id' => $chat_id, 'action' => 'typing'];
    
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

// ==================== CSV FUNCTIONS ====================

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

function append_movie_to_csv($movie_name, $message_id, $channel_id) {
    $handle = fopen(CSV_FILE, "a");
    if ($handle !== FALSE) {
        fputcsv($handle, [trim($movie_name), $message_id, $channel_id]);
        fclose($handle);
        update_stats('total_movies', 1);
        return true;
    }
    return false;
}

function get_total_movies() {
    if (!file_exists(CSV_FILE)) return 0;
    $lines = file(CSV_FILE);
    return count($lines) - 1;
}

// ==================== ADMIN PANEL FUNCTIONS ====================

function getAdminKeyboard() {
    return [
        'inline_keyboard' => [
            [['text' => '📊 Stats', 'callback_data' => 'admin_stats'], ['text' => '👥 Users', 'callback_data' => 'admin_users']],
            [['text' => '📁 Backup', 'callback_data' => 'admin_backup'], ['text' => '📋 CSV Data', 'callback_data' => 'admin_check_csv']],
            [['text' => '🔙 Exit', 'callback_data' => 'exit_admin']]
        ]
    ];
}

function admin_panel($chat_id, $message_id = null) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    $total_movies = get_total_movies();
    
    $text = "🔐 <b>Admin Control Panel</b>\n\n";
    $text .= "📊 <b>Quick Stats:</b>\n";
    $text .= "• Movies: <b>$total_movies</b>\n";
    $text .= "• Users: <b>$total_users</b>\n";
    $text .= "• Searches: <b>" . ($stats['total_searches'] ?? 0) . "</b>\n";
    $text .= "• Forwards: <b>" . ($stats['total_forwards'] ?? 0) . "</b>\n\n";
    $text .= "Use buttons below to manage the bot.";
    
    if ($message_id) {
        editMessage($chat_id, $message_id, $text, getAdminKeyboard());
    } else {
        sendMessage($chat_id, $text, getAdminKeyboard());
    }
}

function editMessage($chat_id, $message_id, $new_text, $reply_markup = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/editMessageText";
    $data = ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $new_text, 'parse_mode' => 'HTML'];
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
    return @file_get_contents($url, false, $context);
}

function admin_stats_panel($chat_id, $message_id = null) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_movies = get_total_movies();
    
    $text = "📊 <b>Detailed Statistics</b>\n\n";
    $text .= "🎬 Total Movies: <b>$total_movies</b>\n";
    $text .= "👥 Total Users: <b>" . count($users_data['users'] ?? []) . "</b>\n";
    $text .= "🔍 Total Searches: <b>" . ($stats['total_searches'] ?? 0) . "</b>\n";
    $text .= "📤 Total Forwards: <b>" . ($stats['total_forwards'] ?? 0) . "</b>\n";
    $text .= "🕒 Last Updated: <b>" . ($stats['last_updated'] ?? date('Y-m-d H:i:s')) . "</b>\n";
    
    $keyboard = ['inline_keyboard' => [[['text' => '🔙 Back', 'callback_data' => 'admin_panel']]]];
    
    if ($message_id) {
        editMessage($chat_id, $message_id, $text, $keyboard);
    } else {
        sendMessage($chat_id, $text, $keyboard);
    }
}

function admin_users_panel($chat_id, $message_id = null, $page = 1) {
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $users = $users_data['users'] ?? [];
    $total_users = count($users);
    $per_page = 10;
    $total_pages = max(1, ceil($total_users / $per_page));
    $page = max(1, min($page, $total_pages));
    $start = ($page - 1) * $per_page;
    $users_slice = array_slice($users, $start, $per_page, true);
    
    $text = "👥 <b>User Management</b>\n\n";
    $text .= "📊 Total Users: <b>$total_users</b>\n";
    $text .= "📄 Page: <b>$page / $total_pages</b>\n\n";
    $text .= "<b>User List:</b>\n";
    
    $i = $start + 1;
    foreach ($users_slice as $user_id => $user) {
        $name = htmlspecialchars($user['first_name'] ?? 'Unknown');
        $username = $user['username'] ?? '';
        $text .= "$i. <b>$name</b>\n";
        $text .= "   🆔 <code>$user_id</code>\n";
        if ($username) $text .= "   @$username\n";
        $text .= "\n";
        $i++;
    }
    
    $keyboard = ['inline_keyboard' => []];
    $nav_row = [];
    if ($page > 1) $nav_row[] = ['text' => '◀️ Prev', 'callback_data' => "admin_users_page_" . ($page - 1)];
    $nav_row[] = ['text' => "📄 $page/$total_pages", 'callback_data' => 'current_page'];
    if ($page < $total_pages) $nav_row[] = ['text' => 'Next ▶️', 'callback_data' => "admin_users_page_" . ($page + 1)];
    $keyboard['inline_keyboard'][] = $nav_row;
    $keyboard['inline_keyboard'][] = [['text' => '🔙 Back', 'callback_data' => 'admin_panel']];
    
    if ($message_id) {
        editMessage($chat_id, $message_id, $text, $keyboard);
    } else {
        sendMessage($chat_id, $text, $keyboard);
    }
}

function admin_backup_panel($chat_id, $message_id = null) {
    if (!file_exists(BACKUP_DIR)) mkdir(BACKUP_DIR, 0777, true);
    
    $backup_file = BACKUP_DIR . 'backup_' . date('Y-m-d_H-i-s') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($backup_file, ZipArchive::CREATE) === TRUE) {
        if (file_exists(CSV_FILE)) $zip->addFile(CSV_FILE, 'movies.csv');
        if (file_exists(USERS_FILE)) $zip->addFile(USERS_FILE, 'users.json');
        if (file_exists(STATS_FILE)) $zip->addFile(STATS_FILE, 'stats.json');
        $zip->close();
    }
    
    $text = "📦 <b>Backup Created!</b>\n\n";
    $text .= "✅ File: <code>$backup_file</code>\n";
    $text .= "📅 Date: " . date('Y-m-d H:i:s') . "\n";
    if (file_exists($backup_file)) {
        $text .= "💾 Size: " . round(filesize($backup_file) / 1024, 2) . " KB\n";
    }
    
    $keyboard = ['inline_keyboard' => [[['text' => '🔙 Back', 'callback_data' => 'admin_panel']]]];
    
    if ($message_id) {
        editMessage($chat_id, $message_id, $text, $keyboard);
    } else {
        sendMessage($chat_id, $text, $keyboard);
    }
}

function admin_check_csv_panel($chat_id, $message_id = null, $page = 1) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "❌ CSV file not found!");
        return;
    }
    
    $movies = [];
    $handle = fopen(CSV_FILE, "r");
    if ($handle !== FALSE) {
        fgetcsv($handle); // Skip header
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3) {
                $movies[] = ['name' => $row[0], 'message_id' => $row[1], 'channel_id' => $row[2]];
            }
        }
        fclose($handle);
    }
    
    $total = count($movies);
    $per_page = 15;
    $total_pages = max(1, ceil($total / $per_page));
    $page = max(1, min($page, $total_pages));
    $start = ($page - 1) * $per_page;
    $slice = array_slice($movies, $start, $per_page);
    
    $text = "📋 <b>CSV Database</b>\n\n";
    $text .= "📊 Total Movies: <b>$total</b>\n";
    $text .= "📄 Page: <b>$page / $total_pages</b>\n\n";
    $text .= "<b>Movie List:</b>\n";
    
    $i = $start + 1;
    foreach ($slice as $movie) {
        $name = htmlspecialchars(substr($movie['name'], 0, 50));
        $text .= "$i. $name\n";
        $text .= "   📝 ID: {$movie['message_id']}\n\n";
        $i++;
    }
    
    $keyboard = ['inline_keyboard' => []];
    $nav_row = [];
    if ($page > 1) $nav_row[] = ['text' => '◀️ Prev', 'callback_data' => "admin_csv_page_" . ($page - 1)];
    $nav_row[] = ['text' => "📄 $page/$total_pages", 'callback_data' => 'current_page'];
    if ($page < $total_pages) $nav_row[] = ['text' => 'Next ▶️', 'callback_data' => "admin_csv_page_" . ($page + 1)];
    $keyboard['inline_keyboard'][] = $nav_row;
    $keyboard['inline_keyboard'][] = [['text' => '🔙 Back', 'callback_data' => 'admin_panel']];
    
    if ($message_id) {
        editMessage($chat_id, $message_id, $text, $keyboard);
    } else {
        sendMessage($chat_id, $text, $keyboard);
    }
}

// ==================== CHANNEL POST HANDLER ====================

function handleChannelPost($message, $chat_id) {
    $message_id = $message['message_id'];
    $movie_name = '';
    
    if (isset($message['caption']) && !empty(trim($message['caption']))) {
        $movie_name = trim($message['caption']);
    } elseif (isset($message['text']) && !empty(trim($message['text']))) {
        $movie_name = trim($message['text']);
    } elseif (isset($message['document']['file_name'])) {
        $movie_name = pathinfo($message['document']['file_name'], PATHINFO_FILENAME);
    } else {
        $movie_name = 'Media_' . date('d-m-Y_H-i-s');
    }
    
    // Clean movie name
    $movie_name = preg_replace('/\.(mp4|mkv|avi|mov|wmv|flv|webm)$/i', '', $movie_name);
    $movie_name = preg_replace('/\s+/', ' ', $movie_name);
    $movie_name = trim($movie_name);
    $movie_name = ucwords(strtolower($movie_name));
    
    if (!empty($movie_name)) {
        return append_movie_to_csv($movie_name, $message_id, $chat_id);
    }
    return false;
}

// ==================== MAIN WEBHOOK HANDLER ====================

$update = json_decode(file_get_contents('php://input'), true);

if ($update && !$MAINTENANCE_MODE) {
    
    // ========== CHANNEL POST HANDLER ==========
    if (isset($update['channel_post'])) {
        $message = $update['channel_post'];
        $chat_id = $message['chat']['id'];
        
        $our_channels = [
            MAIN_CHANNEL_ID, SERIAL_CHANNEL_ID, THEATER_CHANNEL_ID,
            BACKUP_CHANNEL_ID, PRIVATE_CHANNEL_1, PRIVATE_CHANNEL_2
        ];
        
        if (in_array($chat_id, $our_channels)) {
            handleChannelPost($message, $chat_id);
        }
    }
    
    // ========== MESSAGE HANDLER ==========
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = trim($message['text'] ?? '');
        
        // Save user to JSON
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        if (!isset($users_data['users'][$user_id])) {
            $users_data['users'][$user_id] = [
                'first_name' => $message['from']['first_name'] ?? '',
                'last_name' => $message['from']['last_name'] ?? '',
                'username' => $message['from']['username'] ?? '',
                'joined' => date('Y-m-d H:i:s'),
                'last_active' => date('Y-m-d H:i:s')
            ];
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
            update_stats('total_users', 1);
        } else {
            $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
        }
        
        // Show typing indicator for non-command messages
        if (!empty($text) && strpos($text, '/') !== 0 && strlen($text) > 2) {
            sendTyping($chat_id);
            usleep(500000);
        }
        
        // ========== COMMAND HANDLER ==========
        if (strpos($text, '/') === 0) {
            $cmd = explode(' ', $text)[0];
            
            if ($cmd == '/start') {
                $welcome = "🎬 <b>Welcome to Entertainment Tadka Bot!</b>\n\n";
                $welcome .= "🔍 <b>How to use:</b>\n";
                $welcome .= "• Simply type any movie name\n";
                $welcome .= "• I'll search and send it to you\n\n";
                $welcome .= "📝 <b>Examples:</b>\n";
                $welcome .= "• Mandala Murders S1 2025\n";
                $welcome .= "• Zebra 2024\n";
                $welcome .= "• Show Time 2025\n";
                $welcome .= "• Squid Game All Seasons\n";
                $welcome .= "• Now You See Me All Parts\n\n";
                $welcome .= "📢 <b>Our Channels:</b>\n";
                foreach (getPublicChannels() as $ch) {
                    $welcome .= "• {$ch['emoji']} {$ch['username']}\n";
                }
                $welcome .= "\n💬 <b>Request Group:</b> " . REQUEST_GROUP_USERNAME . "\n";
                $welcome .= "🤖 <b>Bot:</b> " . BOT_USERNAME;
                
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '📢 Join Channel', 'url' => 'https://t.me/' . ltrim(MAIN_CHANNEL_USERNAME, '@')]],
                        [['text' => '💬 Request Group', 'url' => 'https://t.me/' . ltrim(REQUEST_GROUP_USERNAME, '@')]]
                    ]
                ];
                sendMessage($chat_id, $welcome, $keyboard);
            }
            elseif ($cmd == '/help') {
                $help = "🤖 <b>Bot Commands</b>\n\n";
                $help .= "/start - Welcome message\n";
                $help .= "/help - This help menu\n";
                $help .= "/channels - Our channels list\n\n";
                $help .= "🔍 <b>Just type any movie name to search!</b>";
                sendMessage($chat_id, $help);
            }
            elseif ($cmd == '/channels') {
                $msg = "📢 <b>Our Channels</b>\n\n";
                foreach (getPublicChannels() as $ch) {
                    $msg .= "{$ch['emoji']} <b>{$ch['name']}</b>\n";
                    $msg .= "🔗 {$ch['username']}\n\n";
                }
                $msg .= "💬 <b>Request Group:</b> " . REQUEST_GROUP_USERNAME . "\n";
                $msg .= "🤖 <b>Bot:</b> " . BOT_USERNAME;
                sendMessage($chat_id, $msg);
            }
            elseif ($cmd == '/admin' && isAdmin($user_id)) {
                sendTyping($chat_id);
                usleep(300000);
                admin_panel($chat_id);
            }
            elseif ($cmd == '/stats' && isAdmin($user_id)) {
                sendTyping($chat_id);
                usleep(500000);
                admin_stats_panel($chat_id);
            }
        }
        // ========== SEARCH HANDLER ==========
        elseif (!empty($text) && strlen($text) > 2) {
            $results = search_movie($text);
            
            if (count($results) > 0) {
                $msg = "🔍 <b>Found " . count($results) . " results for '$text':</b>\n\n";
                $keyboard = ['inline_keyboard' => []];
                $shown = 0;
                
                foreach ($results as $result) {
                    if ($shown >= 15) break;
                    $name = htmlspecialchars(substr($result['name'], 0, 60));
                    $callback_data = "send_" . $result['message_id'] . "_" . $result['channel_id'];
                    $keyboard['inline_keyboard'][] = [['text' => "🎬 " . $name, 'callback_data' => $callback_data]];
                    $shown++;
                }
                
                $keyboard['inline_keyboard'][] = [['text' => '📢 Join Channel', 'url' => 'https://t.me/' . ltrim(MAIN_CHANNEL_USERNAME, '@')]];
                
                sendMessage($chat_id, $msg, $keyboard);
                update_stats('total_searches', 1);
            } else {
                $msg = "😔 <b>No results found for '$text'</b>\n\n";
                $msg .= "📝 <b>Suggestions:</b>\n";
                $msg .= "• Check the spelling\n";
                $msg .= "• Try a different keyword\n";
                $msg .= "• Request the movie in our group\n\n";
                $msg .= "💬 <b>Request Group:</b> " . REQUEST_GROUP_USERNAME;
                sendMessage($chat_id, $msg);
                update_stats('total_searches', 1);
            }
        }
    }
    
    // ========== CALLBACK QUERY HANDLER ==========
    if (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $chat_id = $query['message']['chat']['id'];
        $message_id = $query['message']['message_id'];
        $data = $query['data'];
        $user_id = $query['from']['id'];
        
        // Handle send_ callback
        if (strpos($data, 'send_') === 0) {
            $parts = explode('_', $data);
            $msg_id = $parts[1] ?? '';
            $channel_id = $parts[2] ?? MAIN_CHANNEL_ID;
            
            if (!empty($msg_id) && is_numeric($msg_id)) {
                forwardMessage($chat_id, $channel_id, $msg_id);
                answerCallbackQuery($query['id'], "✅ Movie sent!");
                update_stats('total_forwards', 1);
            } else {
                answerCallbackQuery($query['id'], "❌ Invalid message ID", true);
            }
        }
        // Handle admin panel callbacks
        elseif ($data == 'admin_panel' && isAdmin($user_id)) {
            admin_panel($chat_id, $message_id);
            answerCallbackQuery($query['id']);
        }
        elseif ($data == 'admin_stats' && isAdmin($user_id)) {
            admin_stats_panel($chat_id, $message_id);
            answerCallbackQuery($query['id']);
        }
        elseif ($data == 'admin_users' && isAdmin($user_id)) {
            admin_users_panel($chat_id, $message_id);
            answerCallbackQuery($query['id']);
        }
        elseif (strpos($data, 'admin_users_page_') === 0 && isAdmin($user_id)) {
            $page = intval(str_replace('admin_users_page_', '', $data));
            admin_users_panel($chat_id, $message_id, $page);
            answerCallbackQuery($query['id']);
        }
        elseif ($data == 'admin_backup' && isAdmin($user_id)) {
            admin_backup_panel($chat_id, $message_id);
            answerCallbackQuery($query['id']);
        }
        elseif ($data == 'admin_check_csv' && isAdmin($user_id)) {
            admin_check_csv_panel($chat_id, $message_id);
            answerCallbackQuery($query['id']);
        }
        elseif (strpos($data, 'admin_csv_page_') === 0 && isAdmin($user_id)) {
            $page = intval(str_replace('admin_csv_page_', '', $data));
            admin_check_csv_panel($chat_id, $message_id, $page);
            answerCallbackQuery($query['id']);
        }
        elseif ($data == 'exit_admin' && isAdmin($user_id)) {
            sendMessage($chat_id, "👋 Exited admin panel. Type /admin to return.");
            answerCallbackQuery($query['id']);
        }
        elseif ($data == 'current_page') {
            answerCallbackQuery($query['id'], "You're on this page");
        }
        else {
            answerCallbackQuery($query['id'], "❌ Invalid option", true);
        }
    }
}

// ==================== WEBHOOK SETUP PAGE ====================
if (isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $webhook_url = str_replace('?setwebhook=1', '', $webhook_url);
    
    echo "<h1>🤖 Bot Webhook Setup</h1>";
    echo "<p><strong>Click the link below to set webhook:</strong></p>";
    echo "<p><a href='https://api.telegram.org/bot" . BOT_TOKEN . "/setWebhook?url=" . urlencode($webhook_url) . "' target='_blank'>";
    echo "🔗 Set Webhook Manually</a></p>";
    echo "<hr>";
    echo "<p><strong>Or use this URL:</strong></p>";
    echo "<code>https://api.telegram.org/bot" . BOT_TOKEN . "/setWebhook?url=" . urlencode($webhook_url) . "</code>";
    exit;
}

// ==================== STATUS PAGE ====================
if (!isset($update) || !$update) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_movies = get_total_movies();
    $total_users = count($users_data['users'] ?? []);
    
    echo "<!DOCTYPE html>";
    echo "<html><head><title>Entertainment Tadka Bot</title>";
    echo "<meta charset='UTF-8'>";
    echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
    echo "<style>";
    echo "body{font-family:Arial,sans-serif;background:#1a1a2e;color:#fff;padding:20px;margin:0;}";
    echo ".container{max-width:800px;margin:0 auto;background:#16213e;border-radius:10px;padding:20px;}";
    echo "h1{color:#e94560;margin-top:0;}";
    echo ".stat{background:#0f3460;padding:15px;margin:10px 0;border-radius:5px;}";
    echo ".stat h2{margin:0 0 10px 0;color:#e94560;}";
    echo ".stat p{margin:5px 0;}";
    echo "button{background:#e94560;border:none;padding:10px 20px;color:#fff;border-radius:5px;cursor:pointer;font-size:16px;}";
    echo "button:hover{background:#ff6b81;}";
    echo "a{color:#e94560;text-decoration:none;}";
    echo ".footer{text-align:center;margin-top:20px;font-size:12px;color:#888;}";
    echo "</style>";
    echo "</head><body>";
    echo "<div class='container'>";
    echo "<h1>🎬 Entertainment Tadka Bot</h1>";
    echo "<p><strong>Status:</strong> ✅ Running</p>";
    echo "<p><strong>Bot:</strong> " . BOT_USERNAME . "</p>";
    
    echo "<div class='stat'>";
    echo "<h2>📊 Statistics</h2>";
    echo "<p>🎬 Movies: <strong>$total_movies</strong></p>";
    echo "<p>👥 Users: <strong>$total_users</strong></p>";
    echo "<p>🔍 Searches: <strong>" . ($stats['total_searches'] ?? 0) . "</strong></p>";
    echo "<p>📤 Forwards: <strong>" . ($stats['total_forwards'] ?? 0) . "</strong></p>";
    echo "</div>";
    
    echo "<div class='stat'>";
    echo "<h2>📢 Channels</h2>";
    foreach (getPublicChannels() as $ch) {
        echo "<p>{$ch['emoji']} <strong>{$ch['name']}</strong>: {$ch['username']}</p>";
    }
    echo "<p>💬 <strong>Request Group</strong>: " . REQUEST_GROUP_USERNAME . "</p>";
    echo "</div>";
    
    echo "<div class='stat'>";
    echo "<h2>🚀 Quick Actions</h2>";
    echo "<p><a href='?setwebhook=1'><button>🔗 Setup Webhook</button></a></p>";
    echo "</div>";
    
    echo "<div class='footer'>";
    echo "<p>Last Updated: " . date('Y-m-d H:i:s') . "</p>";
    echo "</div>";
    echo "</div></body></html>";
}
?>
