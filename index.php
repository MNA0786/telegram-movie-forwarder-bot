<?php
// ==================== LOAD ENVIRONMENT ====================
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// ==================== CONFIGURATION ====================
define('BOT_TOKEN', $_ENV['BOT_TOKEN'] ?? getenv('BOT_TOKEN'));
define('BOT_ID', $_ENV['BOT_ID'] ?? getenv('BOT_ID'));
define('BOT_USERNAME', $_ENV['BOT_USERNAME'] ?? getenv('BOT_USERNAME'));

// Public Channels
define('MAIN_CHANNEL_ID', $_ENV['MAIN_CHANNEL_ID'] ?? getenv('MAIN_CHANNEL_ID'));
define('MAIN_CHANNEL_USERNAME', $_ENV['MAIN_CHANNEL_USERNAME'] ?? getenv('MAIN_CHANNEL_USERNAME'));
define('SERIAL_CHANNEL_ID', $_ENV['SERIAL_CHANNEL_ID'] ?? getenv('SERIAL_CHANNEL_ID'));
define('SERIAL_CHANNEL_USERNAME', $_ENV['SERIAL_CHANNEL_USERNAME'] ?? getenv('SERIAL_CHANNEL_USERNAME'));
define('THEATER_CHANNEL_ID', $_ENV['THEATER_CHANNEL_ID'] ?? getenv('THEATER_CHANNEL_ID'));
define('THEATER_CHANNEL_USERNAME', $_ENV['THEATER_CHANNEL_USERNAME'] ?? getenv('THEATER_CHANNEL_USERNAME'));
define('BACKUP_CHANNEL_ID', $_ENV['BACKUP_CHANNEL_ID'] ?? getenv('BACKUP_CHANNEL_ID'));
define('BACKUP_CHANNEL_USERNAME', $_ENV['BACKUP_CHANNEL_USERNAME'] ?? getenv('BACKUP_CHANNEL_USERNAME'));

// Private Channels
define('PRIVATE_CHANNEL_1', $_ENV['PRIVATE_CHANNEL_1'] ?? getenv('PRIVATE_CHANNEL_1'));
define('PRIVATE_CHANNEL_2', $_ENV['PRIVATE_CHANNEL_2'] ?? getenv('PRIVATE_CHANNEL_2'));

// Request Group
define('REQUEST_GROUP_ID', $_ENV['REQUEST_GROUP_ID'] ?? getenv('REQUEST_GROUP_ID'));
define('REQUEST_GROUP_USERNAME', $_ENV['REQUEST_GROUP_USERNAME'] ?? getenv('REQUEST_GROUP_USERNAME'));

// Files
define('CSV_FILE', 'movies.csv');
define('USERS_FILE', 'users.json');
define('STATS_FILE', 'bot_stats.json');
define('BACKUP_DIR', 'backups/');
define('ADMIN_IDS_FILE', 'admin_ids.json');

// Settings
define('CACHE_EXPIRY', intval($_ENV['CACHE_EXPIRY'] ?? 300));
define('ITEMS_PER_PAGE', intval($_ENV['ITEMS_PER_PAGE'] ?? 5));

// Maintenance Mode
$MAINTENANCE_MODE = filter_var($_ENV['MAINTENANCE_MODE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

// Error Reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// ==================== LOAD DEPENDENCIES ====================
require_once 'database.php';
require_once 'auto_indexer.php';
require_once 'webhook_retry.php';
require_once 'telegram_api.php';

// ==================== INITIALIZATION ====================
$db = new Database();
$autoIndexer = new AutoIndexer($db);
$telegram = new TelegramAPI(BOT_TOKEN);

// File initializations
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
        ['id' => MAIN_CHANNEL_ID, 'username' => MAIN_CHANNEL_USERNAME, 'name' => '🎬 Main Channel'],
        ['id' => SERIAL_CHANNEL_ID, 'username' => SERIAL_CHANNEL_USERNAME, 'name' => '📺 Serial Channel'],
        ['id' => THEATER_CHANNEL_ID, 'username' => THEATER_CHANNEL_USERNAME, 'name' => '🎭 Theater Print'],
        ['id' => BACKUP_CHANNEL_ID, 'username' => BACKUP_CHANNEL_USERNAME, 'name' => '💾 Backup Channel']
    ];
}

function getAdminKeyboard() {
    return [
        'inline_keyboard' => [
            [['text' => '📊 Stats', 'callback_data' => 'admin_stats'], ['text' => '👥 Users', 'callback_data' => 'admin_users']],
            [['text' => '📁 Backup', 'callback_data' => 'admin_backup'], ['text' => '📋 CSV Data', 'callback_data' => 'admin_check_csv']],
            [['text' => '🔙 Exit Panel', 'callback_data' => 'exit_admin']]
        ]
    ];
}

function admin_panel($chat_id, $message_id = null) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    $total_movies = $GLOBALS['db']->getTotalMovieCount();
    
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

function admin_stats_panel($chat_id, $message_id = null) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_movies = $GLOBALS['db']->getTotalMovieCount();
    $channel_stats = $GLOBALS['db']->getChannelStats();
    
    $text = "📊 <b>Detailed Statistics</b>\n\n";
    $text .= "🎬 Total Movies: <b>$total_movies</b>\n";
    $text .= "👥 Total Users: <b>" . count($users_data['users'] ?? []) . "</b>\n";
    $text .= "🔍 Total Searches: <b>" . ($stats['total_searches'] ?? 0) . "</b>\n";
    $text .= "📤 Total Forwards: <b>" . ($stats['total_forwards'] ?? 0) . "</b>\n\n";
    
    $text .= "<b>Channel Breakdown:</b>\n";
    foreach ($channel_stats as $channel => $count) {
        $name = $channel == MAIN_CHANNEL_ID ? 'Main Channel' : 
               ($channel == SERIAL_CHANNEL_ID ? 'Serial Channel' :
               ($channel == THEATER_CHANNEL_ID ? 'Theater Print' :
               ($channel == BACKUP_CHANNEL_ID ? 'Backup' : 'Private')));
        $text .= "• $name: $count movies\n";
    }
    
    $keyboard = ['inline_keyboard' => [[['text' => '🔙 Back to Admin Panel', 'callback_data' => 'admin_panel']]]];
    
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
    $total_pages = ceil($total_users / $per_page);
    $page = max(1, min($page, $total_pages));
    $start = ($page - 1) * $per_page;
    $users_slice = array_slice($users, $start, $per_page, true);
    
    $text = "👥 <b>User Management</b>\n\n";
    $text .= "📊 Total Users: <b>$total_users</b>\n";
    $text .= "📄 Page: <b>$page / $total_pages</b>\n\n";
    $text .= "<b>User List:</b>\n";
    
    $i = $start + 1;
    foreach ($users_slice as $user_id => $user) {
        $name = $user['first_name'] ?? 'Unknown';
        $username = $user['username'] ?? '';
        $text .= "$i. <b>" . htmlspecialchars($name) . "</b>\n";
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
    $keyboard['inline_keyboard'][] = [['text' => '🔙 Back to Admin Panel', 'callback_data' => 'admin_panel']];
    
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
        if (file_exists('movies.db')) $zip->addFile('movies.db', 'movies.db');
        $zip->close();
    }
    
    $text = "📦 <b>Backup Created!</b>\n\n";
    $text .= "✅ File: <code>$backup_file</code>\n";
    $text .= "📅 Date: " . date('Y-m-d H:i:s') . "\n\n";
    $text .= "💾 Size: " . round(filesize($backup_file) / 1024, 2) . " KB";
    
    $keyboard = ['inline_keyboard' => [[['text' => '🔙 Back', 'callback_data' => 'admin_panel']]]];
    
    if ($message_id) {
        editMessage($chat_id, $message_id, $text, $keyboard);
    } else {
        sendMessage($chat_id, $text, $keyboard);
    }
}

function admin_check_csv_panel($chat_id, $message_id = null, $page = 1) {
    $movies = $GLOBALS['db']->getAllMovies(20, ($page - 1) * 20);
    $total = $GLOBALS['db']->getTotalMovieCount();
    $total_pages = ceil($total / 20);
    $page = max(1, min($page, $total_pages));
    
    $text = "📋 <b>CSV Database</b>\n\n";
    $text .= "📊 Total Movies: <b>$total</b>\n";
    $text .= "📄 Page: <b>$page / $total_pages</b>\n\n";
    $text .= "<b>Movie List:</b>\n";
    
    $i = (($page - 1) * 20) + 1;
    foreach ($movies as $movie) {
        $name = htmlspecialchars(substr($movie['movie_name'], 0, 50));
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

// ==================== WEBHOOK HANDLER ====================
$update = json_decode(file_get_contents('php://input'), true);

if ($update && !$MAINTENANCE_MODE) {
    processWithRetry($update);
}

// ==================== WEBHOOK SETUP PAGE ====================
if (php_sapi_name() === 'cli' || isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $webhook_url = str_replace('?setwebhook=1', '', $webhook_url);
    
    $result = sendMessageWithTyping ?? '';
    echo "<h1>🤖 Bot Webhook Setup</h1>";
    echo "<p><strong>Webhook URL:</strong> " . htmlspecialchars($webhook_url) . "</p>";
    echo "<p><strong>Bot:</strong> " . BOT_USERNAME . "</p>";
    exit;
}

// ==================== STATUS PAGE ====================
if (!isset($update) || !$update) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_movies = $db->getTotalMovieCount();
    
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
    echo "<p>🔍 Searches: <strong>" . ($stats['total_searches'] ?? 0) . "</strong></p>";
    echo "</div>";
    
    echo "<div class='stat'>";
    echo "<h2>📢 Channels</h2>";
    foreach (getPublicChannels() as $ch) {
        echo "<p>{$ch['name']}: {$ch['username']}</p>";
    }
    echo "</div>";
    
    echo "<div class='stat'>";
    echo "<h2>🚀 Quick Actions</h2>";
    echo "<p><a href='?setwebhook=1'><button>🔗 Set Webhook</button></a></p>";
    echo "</div>";
    
    echo "<p><small>Last Updated: " . date('Y-m-d H:i:s') . "</small></p>";
    echo "</div></body></html>";
}
?>