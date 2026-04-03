<?php
// ==================== LOAD ENVIRONMENT ====================

function loadEnv($filePath) {
    if (!file_exists($filePath)) return false;
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
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
define('REQUEST_GROUP_ID', getenv('REQUEST_GROUP_ID') ?: $_ENV['REQUEST_GROUP_ID'] ?? '');
define('REQUEST_GROUP_USERNAME', getenv('REQUEST_GROUP_USERNAME') ?: $_ENV['REQUEST_GROUP_USERNAME'] ?? '');

define('CSV_FILE', 'movies.csv');
define('USERS_FILE', 'users.json');
define('STATS_FILE', 'bot_stats.json');
define('REQUESTS_FILE', 'requests.json');
define('SEARCH_STATS_FILE', 'search_stats.json');
define('BACKUP_DIR', 'backups/');
define('ADMIN_IDS_FILE', 'admin_ids.json');

define('CACHE_EXPIRY', intval(getenv('CACHE_EXPIRY') ?: $_ENV['CACHE_EXPIRY'] ?? 300));
define('ITEMS_PER_PAGE', intval(getenv('ITEMS_PER_PAGE') ?: $_ENV['ITEMS_PER_PAGE'] ?? 5));

$MAINTENANCE_MODE = filter_var(getenv('MAINTENANCE_MODE') ?: $_ENV['MAINTENANCE_MODE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// ==================== FILE INITIALIZATION ====================
if (!file_exists(USERS_FILE)) file_put_contents(USERS_FILE, json_encode(['users' => [], 'total_requests' => 0]));
if (!file_exists(STATS_FILE)) file_put_contents(STATS_FILE, json_encode(['total_movies' => 0, 'total_users' => 0, 'total_searches' => 0, 'total_forwards' => 0, 'last_updated' => date('Y-m-d H:i:s')]));
if (!file_exists(CSV_FILE)) file_put_contents(CSV_FILE, "movie_name,message_id,channel_id,year,quality\n");
if (!file_exists(ADMIN_IDS_FILE)) file_put_contents(ADMIN_IDS_FILE, json_encode(['admins' => ['1080317415']]));
if (!file_exists(REQUESTS_FILE)) file_put_contents(REQUESTS_FILE, json_encode(['requests' => []]));
if (!file_exists(SEARCH_STATS_FILE)) file_put_contents(SEARCH_STATS_FILE, json_encode(['searches' => []]));
if (!file_exists(BACKUP_DIR)) mkdir(BACKUP_DIR, 0777, true);

// ==================== SQLITE DATABASE ====================
class Database {
    private $db;
    
    public function __construct() {
        $this->db = new SQLite3(__DIR__ . '/movies.db');
        $this->createTables();
    }
    
    private function createTables() {
        $this->db->exec("CREATE TABLE IF NOT EXISTS movies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            movie_name TEXT NOT NULL,
            message_id TEXT NOT NULL,
            channel_id TEXT NOT NULL,
            year TEXT,
            quality TEXT,
            search_keywords TEXT,
            views INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_movie_name ON movies(movie_name)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_search_keywords ON movies(search_keywords)");
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS search_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            search_term TEXT NOT NULL,
            search_count INTEGER DEFAULT 1,
            last_searched DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS movie_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            movie_name TEXT NOT NULL,
            user_id TEXT NOT NULL,
            username TEXT,
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        $this->db->exec("CREATE TABLE IF NOT EXISTS webhook_retry_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            update_data TEXT,
            retry_count INTEGER DEFAULT 0,
            status TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }
    
    public function isDuplicate($movie_name, $message_id) {
        $stmt = $this->db->prepare("SELECT id FROM movies WHERE message_id = :msg_id OR LOWER(movie_name) = LOWER(:name) LIMIT 1");
        $stmt->bindValue(':msg_id', $message_id, SQLITE3_TEXT);
        $stmt->bindValue(':name', $movie_name, SQLITE3_TEXT);
        $result = $stmt->execute();
        return $result->fetchArray() !== false;
    }
    
    public function addMovie($movie_name, $message_id, $channel_id, $year = '', $quality = '') {
        if ($this->isDuplicate($movie_name, $message_id)) return false;
        
        $keywords = $this->generateKeywords($movie_name);
        $stmt = $this->db->prepare("INSERT INTO movies (movie_name, message_id, channel_id, year, quality, search_keywords) 
                                    VALUES (:name, :msg_id, :channel_id, :year, :quality, :keywords)");
        $stmt->bindValue(':name', $movie_name, SQLITE3_TEXT);
        $stmt->bindValue(':msg_id', $message_id, SQLITE3_TEXT);
        $stmt->bindValue(':channel_id', $channel_id, SQLITE3_TEXT);
        $stmt->bindValue(':year', $year, SQLITE3_TEXT);
        $stmt->bindValue(':quality', $quality, SQLITE3_TEXT);
        $stmt->bindValue(':keywords', $keywords, SQLITE3_TEXT);
        
        return $stmt->execute() !== false;
    }
    
    private function generateKeywords($movie_name) {
        $name = strtolower($movie_name);
        $remove_words = ['the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'season', 'part', 'all', 's01', 's02', 's03'];
        $words = preg_split('/[\s\-\.\(\)]+/', $name);
        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array($word, $remove_words)) {
                $keywords[] = $word;
            }
        }
        return implode(' ', array_unique($keywords));
    }
    
    public function autoCorrect($query) {
        $query = strtolower(trim($query));
        $result = $this->db->query("SELECT DISTINCT movie_name FROM movies");
        $movies = [];
        while ($row = $result->fetchArray()) {
            $movies[] = strtolower($row['movie_name']);
        }
        
        $best_match = null;
        $best_score = 0;
        
        foreach ($movies as $movie) {
            similar_text($query, $movie, $percent);
            if ($percent > $best_score && $percent > 55) {
                $best_score = $percent;
                $best_match = $movie;
            }
        }
        return $best_match;
    }
    
    public function getSimilarMovies($movie_name, $limit = 5) {
        $name = strtolower($movie_name);
        $words = explode(' ', $name);
        $conditions = [];
        $params = [];
        
        foreach ($words as $i => $word) {
            if (strlen($word) > 2) {
                $conditions[] = "LOWER(movie_name) LIKE :word$i";
                $params[":word$i"] = "%$word%";
            }
        }
        
        if (empty($conditions)) return [];
        
        $sql = "SELECT movie_name, message_id, channel_id, year, quality 
                FROM movies 
                WHERE (" . implode(' OR ', $conditions) . ")
                AND LOWER(movie_name) != :exact
                LIMIT :limit";
        
        $params[':exact'] = $name;
        $params[':limit'] = $limit;
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        $similar = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $similar[] = $row;
        }
        return $similar;
    }
    
    public function updateSearchStats($search_term) {
        $term = strtolower(trim($search_term));
        $existing = $this->db->querySingle("SELECT id FROM search_stats WHERE search_term = '$term'");
        
        if ($existing) {
            $this->db->exec("UPDATE search_stats SET search_count = search_count + 1, last_searched = CURRENT_TIMESTAMP WHERE search_term = '$term'");
        } else {
            $stmt = $this->db->prepare("INSERT INTO search_stats (search_term, search_count) VALUES (:term, 1)");
            $stmt->bindValue(':term', $term, SQLITE3_TEXT);
            $stmt->execute();
        }
        return true;
    }
    
    public function getMostSearched($limit = 10) {
        $result = $this->db->query("SELECT search_term, search_count FROM search_stats ORDER BY search_count DESC LIMIT $limit");
        $searches = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $searches[] = ['term' => $row['search_term'], 'count' => $row['search_count']];
        }
        return $searches;
    }
    
    public function searchMovies($query) {
        $query = strtolower(trim($query));
        $corrected = $this->autoCorrect($query);
        $search_term = $corrected ? $corrected : $query;
        
        $this->updateSearchStats($search_term);
        
        $stmt = $this->db->prepare("SELECT movie_name, message_id, channel_id, year, quality, views 
                                    FROM movies 
                                    WHERE LOWER(movie_name) LIKE :query 
                                    OR search_keywords LIKE :query2
                                    LIMIT 30");
        $stmt->bindValue(':query', "%$search_term%", SQLITE3_TEXT);
        $stmt->bindValue(':query2', "%$search_term%", SQLITE3_TEXT);
        
        $result = $stmt->execute();
        $movies = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $movies[] = $row;
            $this->db->exec("UPDATE movies SET views = views + 1 WHERE message_id = '{$row['message_id']}'");
        }
        
        return [
            'original_query' => $query,
            'corrected_query' => $corrected,
            'was_corrected' => ($corrected !== null && $corrected !== $query),
            'movies' => $movies,
            'similar_movies' => empty($movies) ? $this->getSimilarMovies($query) : []
        ];
    }
    
    public function getAllMovies($limit = 100, $offset = 0) {
        $result = $this->db->query("SELECT movie_name, message_id, channel_id, year, quality FROM movies LIMIT $limit OFFSET $offset");
        $movies = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $movies[] = $row;
        }
        return $movies;
    }
    
    public function getTotalMovieCount() {
        return $this->db->querySingle("SELECT COUNT(*) FROM movies");
    }
    
    public function mergeMovies($keep_id, $remove_ids) {
        $this->db->exec("BEGIN TRANSACTION");
        try {
            $ids_string = implode(',', array_map('intval', $remove_ids));
            $this->db->exec("DELETE FROM movies WHERE id IN ($ids_string)");
            $this->db->exec("COMMIT");
            return true;
        } catch (Exception $e) {
            $this->db->exec("ROLLBACK");
            return false;
        }
    }
    
    public function addRequest($movie_name, $user_id, $username) {
        $stmt = $this->db->prepare("INSERT INTO movie_requests (movie_name, user_id, username) VALUES (:name, :uid, :uname)");
        $stmt->bindValue(':name', $movie_name, SQLITE3_TEXT);
        $stmt->bindValue(':uid', $user_id, SQLITE3_TEXT);
        $stmt->bindValue(':uname', $username, SQLITE3_TEXT);
        return $stmt->execute() !== false;
    }
    
    public function getPendingRequests($limit = 20) {
        $result = $this->db->query("SELECT * FROM movie_requests WHERE status = 'pending' ORDER BY created_at DESC LIMIT $limit");
        $requests = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $requests[] = $row;
        }
        return $requests;
    }
    
    public function exportToJSON() {
        $movies = [];
        $result = $this->db->query("SELECT * FROM movies");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $movies[] = $row;
        }
        return json_encode(['movies' => $movies, 'export_date' => date('Y-m-d H:i:s')], JSON_PRETTY_PRINT);
    }
}

$db = new Database();

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
        ['id' => MAIN_CHANNEL_ID, 'username' => MAIN_CHANNEL_USERNAME, 'name' => '🎬 Main Channel', 'emoji' => '🎬', 'url' => 'https://t.me/' . ltrim(MAIN_CHANNEL_USERNAME, '@')],
        ['id' => SERIAL_CHANNEL_ID, 'username' => SERIAL_CHANNEL_USERNAME, 'name' => '📺 Serial Channel', 'emoji' => '📺', 'url' => 'https://t.me/' . ltrim(SERIAL_CHANNEL_USERNAME, '@')],
        ['id' => THEATER_CHANNEL_ID, 'username' => THEATER_CHANNEL_USERNAME, 'name' => '🎭 Theater Print', 'emoji' => '🎭', 'url' => 'https://t.me/' . ltrim(THEATER_CHANNEL_USERNAME, '@')],
        ['id' => BACKUP_CHANNEL_ID, 'username' => BACKUP_CHANNEL_USERNAME, 'name' => '💾 Backup Channel', 'emoji' => '💾', 'url' => 'https://t.me/' . ltrim(BACKUP_CHANNEL_USERNAME, '@')]
    ];
}

// ==================== TELEGRAM API FUNCTIONS ====================

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = 'HTML') {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $data = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => $parse_mode];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    
    $options = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($data)]];
    $context = stream_context_create($options);
    return json_decode(@file_get_contents($url, false, $context), true);
}

function forwardMessage($chat_id, $from_chat_id, $message_id) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/forwardMessage";
    $data = ['chat_id' => $chat_id, 'from_chat_id' => $from_chat_id, 'message_id' => $message_id];
    $options = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($data)]];
    $context = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

function answerCallbackQuery($callback_query_id, $text = null, $show_alert = false) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/answerCallbackQuery";
    $data = ['callback_query_id' => $callback_query_id];
    if ($text) $data['text'] = $text;
    if ($show_alert) $data['show_alert'] = true;
    $options = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($data)]];
    $context = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

function sendTyping($chat_id) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendChatAction";
    $data = ['chat_id' => $chat_id, 'action' => 'typing'];
    $options = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($data)]];
    $context = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

function editMessage($chat_id, $message_id, $new_text, $reply_markup = null) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/editMessageText";
    $data = ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $new_text, 'parse_mode' => 'HTML'];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    $options = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($data)]];
    $context = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

// ==================== INLINE KEYBOARDS ====================

// Main Start Keyboard (For All Users)
function getStartKeyboard() {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '🎬 Main Channel', 'url' => 'https://t.me/' . ltrim(MAIN_CHANNEL_USERNAME, '@')]],
            [['text' => '📺 Serial Channel', 'url' => 'https://t.me/' . ltrim(SERIAL_CHANNEL_USERNAME, '@')]],
            [['text' => '🎭 Theater Print', 'url' => 'https://t.me/' . ltrim(THEATER_CHANNEL_USERNAME, '@')]],
            [['text' => '💾 Backup Channel', 'url' => 'https://t.me/' . ltrim(BACKUP_CHANNEL_USERNAME, '@')]],
            [['text' => '💬 Request Group', 'url' => 'https://t.me/' . ltrim(REQUEST_GROUP_USERNAME, '@')]],
            [['text' => '🔍 Search Movie', 'switch_inline_query_current_chat' => '']]
        ]
    ];
    return $keyboard;
}

// Admin Start Keyboard (Extra Buttons for Admin)
function getAdminStartKeyboard() {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '🎬 Main Channel', 'url' => 'https://t.me/' . ltrim(MAIN_CHANNEL_USERNAME, '@')]],
            [['text' => '📺 Serial Channel', 'url' => 'https://t.me/' . ltrim(SERIAL_CHANNEL_USERNAME, '@')]],
            [['text' => '🎭 Theater Print', 'url' => 'https://t.me/' . ltrim(THEATER_CHANNEL_USERNAME, '@')]],
            [['text' => '💾 Backup Channel', 'url' => 'https://t.me/' . ltrim(BACKUP_CHANNEL_USERNAME, '@')]],
            [['text' => '💬 Request Group', 'url' => 'https://t.me/' . ltrim(REQUEST_GROUP_USERNAME, '@')]],
            [['text' => '🔍 Search Movie', 'switch_inline_query_current_chat' => '']],
            [['text' => '🔐 Admin Panel', 'callback_data' => 'admin_panel']]
        ]
    ];
    return $keyboard;
}

// Public Channels Keyboard
function getPublicChannelsKeyboard() {
    $keyboard = ['inline_keyboard' => []];
    foreach (getPublicChannels() as $ch) {
        $keyboard['inline_keyboard'][] = [['text' => "{$ch['emoji']} {$ch['name']}", 'url' => $ch['url']]];
    }
    $keyboard['inline_keyboard'][] = [['text' => '💬 Request Group', 'url' => 'https://t.me/' . ltrim(REQUEST_GROUP_USERNAME, '@')]];
    $keyboard['inline_keyboard'][] = [['text' => '🔙 Back to Menu', 'callback_data' => 'back_to_start']];
    return $keyboard;
}

// Admin Panel Main Keyboard
function getAdminPanelKeyboard() {
    return [
        'inline_keyboard' => [
            [['text' => '📊 Stats', 'callback_data' => 'admin_stats'], ['text' => '👥 Users', 'callback_data' => 'admin_users']],
            [['text' => '📁 Backup', 'callback_data' => 'admin_backup'], ['text' => '📋 CSV Data', 'callback_data' => 'admin_check_csv']],
            [['text' => '📤 Total Uploads', 'callback_data' => 'admin_totaluploads'], ['text' => '🔍 Most Searched', 'callback_data' => 'admin_most_searched']],
            [['text' => '📝 Requests', 'callback_data' => 'admin_requests'], ['text' => '📥 Export Data', 'callback_data' => 'admin_export']],
            [['text' => '🔀 Merge Movies', 'callback_data' => 'admin_merge'], ['text' => '📢 Channels', 'callback_data' => 'admin_show_channels']],
            [['text' => '🔙 Exit', 'callback_data' => 'exit_admin']]
        ]
    ];
}

// Admin Stats Keyboard
function getAdminStatsKeyboard() {
    return [
        'inline_keyboard' => [
            [['text' => '📊 Full Stats', 'callback_data' => 'admin_stats_detailed']],
            [['text' => '📈 Channel Stats', 'callback_data' => 'admin_channel_stats']],
            [['text' => '🔙 Back', 'callback_data' => 'admin_panel']]
        ]
    ];
}

// Admin Users Keyboard
function getAdminUsersKeyboard($page, $total_pages) {
    $keyboard = ['inline_keyboard' => []];
    $nav_row = [];
    if ($page > 1) $nav_row[] = ['text' => '◀️ Prev', 'callback_data' => "admin_users_page_" . ($page - 1)];
    $nav_row[] = ['text' => "📄 $page/$total_pages", 'callback_data' => 'current_page'];
    if ($page < $total_pages) $nav_row[] = ['text' => 'Next ▶️', 'callback_data' => "admin_users_page_" . ($page + 1)];
    $keyboard['inline_keyboard'][] = $nav_row;
    $keyboard['inline_keyboard'][] = [['text' => '🔙 Back', 'callback_data' => 'admin_panel']];
    return $keyboard;
}

// Admin CSV Keyboard
function getAdminCSVKeyboard($page, $total_pages) {
    $keyboard = ['inline_keyboard' => []];
    $nav_row = [];
    if ($page > 1) $nav_row[] = ['text' => '◀️ Prev', 'callback_data' => "admin_csv_page_" . ($page - 1)];
    $nav_row[] = ['text' => "📄 $page/$total_pages", 'callback_data' => 'current_page'];
    if ($page < $total_pages) $nav_row[] = ['text' => 'Next ▶️', 'callback_data' => "admin_csv_page_" . ($page + 1)];
    $keyboard['inline_keyboard'][] = $nav_row;
    $keyboard['inline_keyboard'][] = [['text' => '🔙 Back', 'callback_data' => 'admin_panel']];
    return $keyboard;
}

// TotalUploads Pagination Keyboard
function getTotalUploadsKeyboard($page, $total_pages) {
    $kb = ['inline_keyboard' => []];
    $nav_row = [];
    if ($page > 1) $nav_row[] = ['text' => '⬅️ Previous', 'callback_data' => 'tu_prev_' . ($page - 1)];
    $nav_row[] = ['text' => "📄 $page/$total_pages", 'callback_data' => 'current_page'];
    if ($page < $total_pages) $nav_row[] = ['text' => 'Next ➡️', 'callback_data' => 'tu_next_' . ($page + 1)];
    $kb['inline_keyboard'][] = $nav_row;
    $kb['inline_keyboard'][] = [['text' => '🎬 Send This Page', 'callback_data' => 'tu_view_' . $page], ['text' => '🛑 Stop', 'callback_data' => 'tu_stop']];
    
    if ($total_pages > 5) {
        $jump_row = [];
        if ($page > 1) $jump_row[] = ['text' => '⏮️ First', 'callback_data' => 'tu_prev_1'];
        if ($page < $total_pages) $jump_row[] = ['text' => 'Last ⏭️', 'callback_data' => 'tu_next_' . $total_pages];
        if (!empty($jump_row)) $kb['inline_keyboard'][] = $jump_row;
    }
    return $kb;
}

// Search Results Keyboard
function getSearchResultsKeyboard($results, $main_channel_url) {
    $keyboard = ['inline_keyboard' => []];
    $shown = 0;
    foreach ($results as $result) {
        if ($shown >= 15) break;
        $display = $result['movie_name'];
        if (!empty($result['year'])) $display .= " ({$result['year']})";
        if (!empty($result['quality'])) $display .= " [{$result['quality']}]";
        $keyboard['inline_keyboard'][] = [['text' => "🎬 " . htmlspecialchars(substr($display, 0, 50)), 'callback_data' => "send_{$result['message_id']}_{$result['channel_id']}"]];
        $shown++;
    }
    $keyboard['inline_keyboard'][] = [['text' => '📢 Join Channel', 'url' => $main_channel_url]];
    $keyboard['inline_keyboard'][] = [['text' => '🔍 New Search', 'switch_inline_query_current_chat' => '']];
    return $keyboard;
}

// Similar Movies Keyboard
function getSimilarMoviesKeyboard($similar_movies, $main_channel_url) {
    $keyboard = ['inline_keyboard' => []];
    foreach ($similar_movies as $movie) {
        $display = $movie['movie_name'];
        if (!empty($movie['year'])) $display .= " ({$movie['year']})";
        $keyboard['inline_keyboard'][] = [['text' => "🎬 " . htmlspecialchars(substr($display, 0, 50)), 'callback_data' => "send_{$movie['message_id']}_{$movie['channel_id']}"]];
    }
    $keyboard['inline_keyboard'][] = [['text' => '📢 Join Channel', 'url' => $main_channel_url]];
    return $keyboard;
}

// ==================== CSV FUNCTIONS ====================

function append_movie_to_csv($movie_name, $message_id, $channel_id, $year = '', $quality = '') {
    global $db;
    $db->addMovie($movie_name, $message_id, $channel_id, $year, $quality);
    
    $handle = fopen(CSV_FILE, "a");
    if ($handle !== FALSE) {
        fputcsv($handle, [trim($movie_name), $message_id, $channel_id, $year, $quality]);
        fclose($handle);
        update_stats('total_movies', 1);
        return true;
    }
    return false;
}

function get_all_movies_from_csv() {
    global $db;
    return $db->getAllMovies(1000, 0);
}

function search_movie($query) {
    global $db;
    return $db->searchMovies($query);
}

function get_total_movies() {
    global $db;
    return $db->getTotalMovieCount();
}

function paginate_movies($all_movies, $page) {
    $total = count($all_movies);
    if ($total === 0) return ['total' => 0, 'total_pages' => 1, 'page' => 1, 'slice' => []];
    
    $total_pages = (int)ceil($total / ITEMS_PER_PAGE);
    $page = max(1, min($page, $total_pages));
    $start = ($page - 1) * ITEMS_PER_PAGE;
    
    return ['total' => $total, 'total_pages' => $total_pages, 'page' => $page, 'slice' => array_slice($all_movies, $start, ITEMS_PER_PAGE)];
}

function forward_page_movies($chat_id, $page_movies) {
    $total = count($page_movies);
    if ($total === 0) return;
    
    foreach ($page_movies as $movie) {
        forwardMessage($chat_id, $movie['channel_id'], $movie['message_id']);
        usleep(500000);
    }
}

function totalupload_controller($chat_id, $page = 1) {
    $all_movies = get_all_movies_from_csv();
    if (empty($all_movies)) {
        sendMessage($chat_id, "📭 Koi movies nahi mili!");
        return;
    }
    
    $pg = paginate_movies($all_movies, (int)$page);
    forward_page_movies($chat_id, $pg['slice']);
    
    $title = "🎬 <b>Total Uploads</b>\n\n📊 Total Movies: <b>{$pg['total']}</b>\n📄 Page: <b>{$pg['page']}/{$pg['total_pages']}</b>\n\n📋 <b>Movies:</b>\n";
    $i = 1;
    foreach ($pg['slice'] as $movie) {
        $title .= "$i. {$movie['movie_name']}" . (!empty($movie['year']) ? " ({$movie['year']})" : "") . (!empty($movie['quality']) ? " [{$movie['quality']}]" : "") . "\n";
        $i++;
    }
    sendMessage($chat_id, $title, getTotalUploadsKeyboard($pg['page'], $pg['total_pages']), 'HTML');
}

// ==================== ADMIN PANEL FUNCTIONS ====================

function admin_panel($chat_id, $message_id = null) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_movies = get_total_movies();
    
    $text = "🔐 <b>Admin Control Panel</b>\n\n📊 Movies: <b>$total_movies</b>\n👥 Users: <b>" . count($users_data['users'] ?? []) . "</b>\n🔍 Searches: <b>" . ($stats['total_searches'] ?? 0) . "</b>\n📤 Forwards: <b>" . ($stats['total_forwards'] ?? 0) . "</b>";
    
    if ($message_id) editMessage($chat_id, $message_id, $text, getAdminPanelKeyboard());
    else sendMessage($chat_id, $text, getAdminPanelKeyboard());
}

function admin_stats_panel($chat_id, $message_id = null) {
    global $db;
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_movies = get_total_movies();
    $most_searched = $db->getMostSearched(5);
    
    $text = "📊 <b>Detailed Statistics</b>\n\n🎬 Movies: <b>$total_movies</b>\n👥 Users: <b>" . count($users_data['users'] ?? []) . "</b>\n🔍 Searches: <b>" . ($stats['total_searches'] ?? 0) . "</b>\n📤 Forwards: <b>" . ($stats['total_forwards'] ?? 0) . "</b>\n\n🔥 <b>Most Searched:</b>\n";
    foreach ($most_searched as $s) $text .= "• {$s['term']} ({$s['count']} times)\n";
    
    if ($message_id) editMessage($chat_id, $message_id, $text, getAdminStatsKeyboard());
    else sendMessage($chat_id, $text, getAdminStatsKeyboard());
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
    
    $text = "👥 <b>Users</b>\n\n📊 Total: <b>$total_users</b>\n📄 Page: <b>$page/$total_pages</b>\n\n";
    $i = $start + 1;
    foreach ($users_slice as $user_id => $user) {
        $text .= "$i. <b>" . htmlspecialchars($user['first_name'] ?? 'Unknown') . "</b>\n   🆔 <code>$user_id</code>\n";
        if (!empty($user['username'])) $text .= "   @{$user['username']}\n";
        $text .= "\n";
        $i++;
    }
    
    if ($message_id) editMessage($chat_id, $message_id, $text, getAdminUsersKeyboard($page, $total_pages));
    else sendMessage($chat_id, $text, getAdminUsersKeyboard($page, $total_pages));
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
    
    $text = "📦 <b>Backup Created!</b>\n\n✅ File: <code>$backup_file</code>\n📅 " . date('Y-m-d H:i:s') . "\n💾 Size: " . round(filesize($backup_file) / 1024, 2) . " KB";
    $keyboard = ['inline_keyboard' => [[['text' => '🔙 Back', 'callback_data' => 'admin_panel']]]];
    if ($message_id) editMessage($chat_id, $message_id, $text, $keyboard);
    else sendMessage($chat_id, $text, $keyboard);
}

function admin_check_csv_panel($chat_id, $message_id = null, $page = 1) {
    global $db;
    $movies = $db->getAllMovies(20, ($page - 1) * 20);
    $total = $db->getTotalMovieCount();
    $total_pages = max(1, ceil($total / 20));
    $page = max(1, min($page, $total_pages));
    
    $text = "📋 <b>Movies Database</b>\n\n📊 Total: <b>$total</b>\n📄 Page: <b>$page/$total_pages</b>\n\n";
    $i = ($page - 1) * 20 + 1;
    foreach ($movies as $movie) {
        $text .= "$i. " . htmlspecialchars(substr($movie['movie_name'], 0, 40));
        if (!empty($movie['year'])) $text .= " ({$movie['year']})";
        if (!empty($movie['quality'])) $text .= " [{$movie['quality']}]";
        $text .= "\n   📝 ID: {$movie['message_id']}\n\n";
        $i++;
    }
    
    if ($message_id) editMessage($chat_id, $message_id, $text, getAdminCSVKeyboard($page, $total_pages));
    else sendMessage($chat_id, $text, getAdminCSVKeyboard($page, $total_pages));
}

function admin_most_searched_panel($chat_id, $message_id = null) {
    global $db;
    $most_searched = $db->getMostSearched(20);
    $text = "🔥 <b>Most Searched Movies</b>\n\n";
    $i = 1;
    foreach ($most_searched as $s) {
        $text .= "$i. <b>" . ucwords($s['term']) . "</b> - {$s['count']} searches\n";
        $i++;
    }
    $keyboard = ['inline_keyboard' => [[['text' => '🔙 Back', 'callback_data' => 'admin_panel']]]];
    if ($message_id) editMessage($chat_id, $message_id, $text, $keyboard);
    else sendMessage($chat_id, $text, $keyboard);
}

function admin_requests_panel($chat_id, $message_id = null) {
    global $db;
    $requests = $db->getPendingRequests(20);
    $text = "📝 <b>Movie Requests</b>\n\n";
    if (empty($requests)) {
        $text .= "No pending requests!";
    } else {
        foreach ($requests as $req) {
            $text .= "🎬 <b>" . htmlspecialchars($req['movie_name']) . "</b>\n";
            $text .= "👤 User: <code>{$req['user_id']}</code>\n";
            if (!empty($req['username'])) $text .= "📢 @{$req['username']}\n";
            $text .= "📅 " . date('d-m-Y H:i', strtotime($req['created_at'])) . "\n\n";
        }
    }
    $keyboard = ['inline_keyboard' => [[['text' => '🔙 Back', 'callback_data' => 'admin_panel']]]];
    if ($message_id) editMessage($chat_id, $message_id, $text, $keyboard);
    else sendMessage($chat_id, $text, $keyboard);
}

function admin_export_panel($chat_id, $message_id = null) {
    global $db;
    $export_data = $db->exportToJSON();
    $filename = 'export_' . date('Y-m-d_H-i-s') . '.json';
    file_put_contents($filename, $export_data);
    
    $text = "📥 <b>Export Ready!</b>\n\nFile: <code>$filename</code>\n📅 " . date('Y-m-d H:i:s');
    $keyboard = ['inline_keyboard' => [[['text' => '🔙 Back', 'callback_data' => 'admin_panel']]]];
    if ($message_id) editMessage($chat_id, $message_id, $text, $keyboard);
    else sendMessage($chat_id, $text, $keyboard);
}

function admin_merge_panel($chat_id, $message_id = null) {
    $text = "🔀 <b>Merge Movies</b>\n\nSend command:\n<code>/mergemovies KEEP_ID REMOVE_IDS</code>\n\nExample:\n<code>/mergemovies 1 2,3,4,5</code>";
    $keyboard = ['inline_keyboard' => [[['text' => '🔙 Back', 'callback_data' => 'admin_panel']]]];
    if ($message_id) editMessage($chat_id, $message_id, $text, $keyboard);
    else sendMessage($chat_id, $text, $keyboard);
}

function admin_show_channels_panel($chat_id, $message_id = null) {
    $text = "📢 <b>Our Channels</b>\n\n";
    foreach (getPublicChannels() as $ch) {
        $text .= "{$ch['emoji']} <b>{$ch['name']}</b>\n";
        $text .= "🔗 {$ch['username']}\n";
        $text .= "🆔 <code>{$ch['id']}</code>\n\n";
    }
    $text .= "💬 <b>Request Group:</b> " . REQUEST_GROUP_USERNAME . "\n";
    $text .= "🆔 <code>" . REQUEST_GROUP_ID . "</code>\n\n";
    $text .= "🤖 <b>Bot:</b> " . BOT_USERNAME;
    
    if ($message_id) editMessage($chat_id, $message_id, $text, getAdminPanelKeyboard());
    else sendMessage($chat_id, $text, getAdminPanelKeyboard());
}

function back_to_start_menu($chat_id, $message_id = null, $user_id) {
    $welcome = "🎬 <b>Welcome to Entertainment Tadka Bot!</b>\n\n🔍 Type any movie name to search.\n\n📝 Examples:\n• Mandala Murders\n• Zebra 2024\n• Show Time 2025";
    
    if (isAdmin($user_id)) {
        $keyboard = getAdminStartKeyboard();
        $welcome .= "\n\n🔐 <b>Admin Access Granted!</b>";
    } else {
        $keyboard = getStartKeyboard();
    }
    
    if ($message_id) editMessage($chat_id, $message_id, $welcome, $keyboard);
    else sendMessage($chat_id, $welcome, $keyboard);
}

// ==================== WEBHOOK RETRY SYSTEM ====================

function processWithRetry($update, $max_retries = 3) {
    $attempt = 0;
    while ($attempt < $max_retries) {
        try {
            return processUpdate($update);
        } catch (Exception $e) {
            $attempt++;
            if ($attempt < $max_retries) sleep(pow(2, $attempt));
        }
    }
    return false;
}

// ==================== CHANNEL POST HANDLER ====================

function handleChannelPost($message, $chat_id) {
    $message_id = $message['message_id'];
    $movie_name = '';
    
    if (isset($message['caption']) && !empty(trim($message['caption']))) $movie_name = trim($message['caption']);
    elseif (isset($message['text']) && !empty(trim($message['text']))) $movie_name = trim($message['text']);
    elseif (isset($message['document']['file_name'])) $movie_name = pathinfo($message['document']['file_name'], PATHINFO_FILENAME);
    else $movie_name = 'Media_' . date('d-m-Y_H-i-s');
    
    $movie_name = preg_replace('/\.(mp4|mkv|avi|mov|wmv|flv|webm)$/i', '', $movie_name);
    
    $year = '';
    if (preg_match('/\b(19|20)\d{2}\b/', $movie_name, $matches)) $year = $matches[0];
    
    $quality = '';
    if (preg_match('/\b(480p|720p|1080p|2160p|4K|HD|FHD|UHD)\b/i', $movie_name, $matches)) $quality = strtoupper($matches[0]);
    
    $movie_name = preg_replace('/\b(19|20)\d{2}\b/', '', $movie_name);
    $movie_name = preg_replace('/\b(480p|720p|1080p|2160p|4K|HD|FHD|UHD|HDRip|WebRip|BluRay|x264|x265|HEVC|AAC|Subs|ESubs)\b/i', '', $movie_name);
    $movie_name = preg_replace('/\s+/', ' ', $movie_name);
    $movie_name = trim($movie_name);
    $movie_name = ucwords(strtolower($movie_name));
    
    if (!empty($movie_name)) return append_movie_to_csv($movie_name, $message_id, $chat_id, $year, $quality);
    return false;
}

// ==================== INLINE SEARCH HANDLER ====================

function handleInlineQuery($inline_query) {
    $query_id = $inline_query['id'];
    $query = $inline_query['query'] ?? '';
    
    if (strlen($query) < 2) {
        $results = [['type' => 'article', 'id' => '1', 'title' => '🔍 Type at least 2 characters', 'input_message_content' => ['message_text' => 'Type at least 2 characters to search movies']]];
        $results_json = json_encode($results);
        $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/answerInlineQuery";
        $data = ['inline_query_id' => $query_id, 'results' => $results_json];
        $options = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($data)]];
        $context = stream_context_create($options);
        @file_get_contents($url, false, $context);
        return;
    }
    
    $search_result = search_movie($query);
    $results = [];
    $i = 1;
    
    foreach ($search_result['movies'] as $movie) {
        $display = $movie['movie_name'];
        if (!empty($movie['year'])) $display .= " ({$movie['year']})";
        if (!empty($movie['quality'])) $display .= " [{$movie['quality']}]";
        
        $results[] = [
            'type' => 'article',
            'id' => (string)$i,
            'title' => $display,
            'description' => "Click to get this movie",
            'input_message_content' => ['message_text' => "🎬 {$display}"],
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '📥 Get Movie', 'callback_data' => "send_{$movie['message_id']}_{$movie['channel_id']}"]],
                    [['text' => '📢 Join Channel', 'url' => 'https://t.me/' . ltrim(MAIN_CHANNEL_USERNAME, '@')]]
                ]
            ]
        ];
        $i++;
        if ($i > 20) break;
    }
    
    if (empty($results)) {
        $results[] = [
            'type' => 'article',
            'id' => '1',
            'title' => '😔 No results found',
            'description' => "Try different keywords",
            'input_message_content' => ['message_text' => "No results found for '$query'\nTry: @EntertainmentTadka7860"]
        ];
    }
    
    $results_json = json_encode($results);
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/answerInlineQuery";
    $data = ['inline_query_id' => $query_id, 'results' => $results_json, 'cache_time' => 300];
    $options = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($data)]];
    $context = stream_context_create($options);
    @file_get_contents($url, false, $context);
}

// ==================== MAIN UPDATE PROCESSOR ====================

function processUpdate($update) {
    global $MAINTENANCE_MODE, $db;
    
    if ($MAINTENANCE_MODE) return false;
    
    // Channel Post
    if (isset($update['channel_post'])) {
        $message = $update['channel_post'];
        $chat_id = $message['chat']['id'];
        $our_channels = [MAIN_CHANNEL_ID, SERIAL_CHANNEL_ID, THEATER_CHANNEL_ID, BACKUP_CHANNEL_ID, PRIVATE_CHANNEL_1, PRIVATE_CHANNEL_2];
        if (in_array($chat_id, $our_channels)) handleChannelPost($message, $chat_id);
    }
    
    // Inline Query
    if (isset($update['inline_query'])) {
        handleInlineQuery($update['inline_query']);
    }
    
    // Message
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
        
        // Typing indicator
        if (!empty($text) && strpos($text, '/') !== 0 && strlen($text) > 2) {
            sendTyping($chat_id);
            usleep(300000);
        }
        
        // Commands
        if (strpos($text, '/') === 0) {
            $cmd = explode(' ', $text)[0];
            $parts = explode(' ', $text);
            
            if ($cmd == '/start') {
                $welcome = "🎬 <b>Welcome to Entertainment Tadka Bot!</b>\n\n🔍 Type any movie name to search.\n\n📝 Examples:\n• Mandala Murders\n• Zebra 2024\n• Show Time 2025";
                
                if (isAdmin($user_id)) {
                    $welcome .= "\n\n🔐 <b>Admin Access Granted!</b>";
                    sendMessage($chat_id, $welcome, getAdminStartKeyboard());
                } else {
                    sendMessage($chat_id, $welcome, getStartKeyboard());
                }
            }
            elseif ($cmd == '/help') {
                $help = "🤖 <b>Bot Commands</b>\n\n/start - Welcome\n/help - This menu\n/channels - Our channels\n/totaluploads - All movies\n\n🔍 Type any movie name to search!\n\n💡 <b>Inline Search:</b>\n@" . BOT_USERNAME . " movie_name";
                sendMessage($chat_id, $help);
            }
            elseif ($cmd == '/channels') {
                sendMessage($chat_id, "📢 <b>Our Channels</b>", getPublicChannelsKeyboard());
            }
            elseif ($cmd == '/totaluploads' || $cmd == '/totalupload') {
                totalupload_controller($chat_id, 1);
            }
            elseif ($cmd == '/admin' && isAdmin($user_id)) {
                admin_panel($chat_id);
            }
            elseif ($cmd == '/stats' && isAdmin($user_id)) {
                admin_stats_panel($chat_id);
            }
            elseif ($cmd == '/mergemovies' && isAdmin($user_id) && isset($parts[1]) && isset($parts[2])) {
                $keep_id = intval($parts[1]);
                $remove_ids = array_map('intval', explode(',', $parts[2]));
                if ($db->mergeMovies($keep_id, $remove_ids)) sendMessage($chat_id, "✅ Movies merged!");
                else sendMessage($chat_id, "❌ Merge failed!");
            }
            elseif (strpos($cmd, '/get_') === 0) {
                $msg_id = str_replace('/get_', '', $cmd);
                $result = $db->searchMovies($msg_id);
                if (!empty($result['movies'])) {
                    $movie = $result['movies'][0];
                    forwardMessage($chat_id, $movie['channel_id'], $movie['message_id']);
                } else {
                    sendMessage($chat_id, "❌ Movie not found!");
                }
            }
            elseif (strlen($text) > 3 && !in_array($cmd, ['/start', '/help', '/channels', '/totaluploads', '/totalupload', '/admin', '/stats', '/mergemovies'])) {
                $db->addRequest($text, $user_id, $message['from']['username'] ?? '');
                sendMessage($chat_id, "📝 Request submitted! We'll add it soon.\n📢 Join @EntertainmentTadka786 for updates.");
            }
        }
        // Search
        elseif (!empty($text) && strlen($text) > 2) {
            $search_result = search_movie($text);
            
            if (!empty($search_result['movies'])) {
                $msg = "";
                if ($search_result['was_corrected']) $msg .= "🔍 Did you mean: <b>" . ucwords($search_result['corrected_query']) . "</b>?\n\n";
                $msg .= "🎬 <b>Found " . count($search_result['movies']) . " results:</b>\n\n";
                
                sendMessage($chat_id, $msg, getSearchResultsKeyboard($search_result['movies'], 'https://t.me/' . ltrim(MAIN_CHANNEL_USERNAME, '@')));
                update_stats('total_searches', 1);
            }
            elseif (!empty($search_result['similar_movies'])) {
                $msg = "😔 No exact match for '<b>$text</b>'.\n\n💡 <b>Similar movies:</b>\n\n";
                sendMessage($chat_id, $msg, getSimilarMoviesKeyboard($search_result['similar_movies'], 'https://t.me/' . ltrim(MAIN_CHANNEL_USERNAME, '@')));
            }
            else {
                $db->addRequest($text, $user_id, $message['from']['username'] ?? '');
                $msg = "😔 No results found for '<b>$text</b>'.\n\n📝 Request submitted! We'll add it soon.\n💬 Request Group: " . REQUEST_GROUP_USERNAME;
                
                $most_searched = $db->getMostSearched(5);
                if (!empty($most_searched)) {
                    $msg .= "\n\n🔥 <b>Trending:</b>\n";
                    foreach ($most_searched as $s) $msg .= "• " . ucwords($s['term']) . "\n";
                }
                sendMessage($chat_id, $msg);
                update_stats('total_searches', 1);
            }
        }
    }
    
    // Callback Query
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
            } else answerCallbackQuery($query['id'], "❌ Invalid", true);
        }
        // TotalUploads Pagination
        elseif (strpos($data, 'tu_prev_') === 0) { totalupload_controller($chat_id, (int)str_replace('tu_prev_', '', $data)); answerCallbackQuery($query['id']); }
        elseif (strpos($data, 'tu_next_') === 0) { totalupload_controller($chat_id, (int)str_replace('tu_next_', '', $data)); answerCallbackQuery($query['id']); }
        elseif (strpos($data, 'tu_view_') === 0) {
            $page = (int)str_replace('tu_view_', '', $data);
            $all_movies = get_all_movies_from_csv();
            $pg = paginate_movies($all_movies, $page);
            forward_page_movies($chat_id, $pg['slice']);
            answerCallbackQuery($query['id'], "Re-sent");
        }
        elseif ($data === 'tu_stop') { sendMessage($chat_id, "✅ Stopped. Type /totaluploads to restart."); answerCallbackQuery($query['id']); }
        // Admin Panel Callbacks
        elseif ($data == 'admin_panel' && isAdmin($user_id)) { admin_panel($chat_id, $message_id); answerCallbackQuery($query['id']); }
        elseif ($data == 'admin_stats' && isAdmin($user_id)) { admin_stats_panel($chat_id, $message_id); answerCallbackQuery($query['id']); }
        elseif ($data == 'admin_users' && isAdmin($user_id)) { admin_users_panel($chat_id, $message_id); answerCallbackQuery($query['id']); }
        elseif (strpos($data, 'admin_users_page_') === 0 && isAdmin($user_id)) { admin_users_panel($chat_id, $message_id, (int)str_replace('admin_users_page_', '', $data)); answerCallbackQuery($query['id']); }
        elseif ($data == 'admin_backup' && isAdmin($user_id)) { admin_backup_panel($chat_id, $message_id); answerCallbackQuery($query['id']); }
        elseif ($data == 'admin_check_csv' && isAdmin($user_id)) { admin_check_csv_panel($chat_id, $message_id); answerCallbackQuery($query['id']); }
        elseif (strpos($data, 'admin_csv_page_') === 0 && isAdmin($user_id)) { admin_check_csv_panel($chat_id, $message_id, (int)str_replace('admin_csv_page_', '', $data)); answerCallbackQuery($query['id']); }
        elseif ($data == 'admin_totaluploads' && isAdmin($user_id)) { totalupload_controller($chat_id, 1); answerCallbackQuery($query['id']); }
        elseif ($data == 'admin_most_searched' && isAdmin($user_id)) { admin_most_searched_panel($chat_id, $message_id); answerCallbackQuery($query['id']); }
        elseif ($data == 'admin_requests' && isAdmin($user_id)) { admin_requests_panel($chat_id, $message_id); answerCallbackQuery($query['id']); }
        elseif ($data == 'admin_export' && isAdmin($user_id)) { admin_export_panel($chat_id, $message_id); answerCallbackQuery($query['id']); }
        elseif ($data == 'admin_merge' && isAdmin($user_id)) { admin_merge_panel($chat_id, $message_id); answerCallbackQuery($query['id']); }
        elseif ($data == 'admin_show_channels' && isAdmin($user_id)) { admin_show_channels_panel($chat_id, $message_id); answerCallbackQuery($query['id']); }
        elseif ($data == 'admin_stats_detailed' && isAdmin($user_id)) { admin_stats_panel($chat_id, $message_id); answerCallbackQuery($query['id']); }
        elseif ($data == 'admin_channel_stats' && isAdmin($user_id)) { admin_show_channels_panel($chat_id, $message_id); answerCallbackQuery($query['id']); }
        elseif ($data == 'exit_admin' && isAdmin($user_id)) { 
            back_to_start_menu($chat_id, $message_id, $user_id);
            answerCallbackQuery($query['id']); 
        }
        elseif ($data == 'back_to_start' && isAdmin($user_id)) { 
            back_to_start_menu($chat_id, $message_id, $user_id);
            answerCallbackQuery($query['id']); 
        }
        elseif ($data == 'current_page') { answerCallbackQuery($query['id'], "You're on this page"); }
        else answerCallbackQuery($query['id'], "❌ Invalid", true);
    }
    
    return true;
}

// ==================== WEBHOOK HANDLER ====================
$update = json_decode(file_get_contents('php://input'), true);
if ($update && !$MAINTENANCE_MODE) processWithRetry($update);

// ==================== WEBHOOK SETUP ====================
if (isset($_GET['setwebhook'])) {
    $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $webhook_url = str_replace('?setwebhook=1', '', $webhook_url);
    echo "<h1>🤖 Bot Webhook Setup</h1>";
    echo "<p><a href='https://api.telegram.org/bot" . BOT_TOKEN . "/setWebhook?url=" . urlencode($webhook_url) . "' target='_blank'>🔗 Click to Set Webhook</a></p>";
    exit;
}

// ==================== STATUS PAGE ====================
if (!isset($update) || !$update) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_movies = get_total_movies();
    echo "<!DOCTYPE html><html><head><title>Entertainment Tadka Bot</title><style>body{font-family:Arial;background:#1a1a2e;color:#fff;padding:20px;}.container{max-width:800px;margin:0 auto;background:#16213e;border-radius:10px;padding:20px;}h1{color:#e94560;}</style></head><body>";
    echo "<div class='container'><h1>🎬 Entertainment Tadka Bot</h1><p><strong>Status:</strong> ✅ Running</p><p><strong>Bot:</strong> " . BOT_USERNAME . "</p>";
    echo "<p>🎬 Movies: <strong>$total_movies</strong></p><p>👥 Users: <strong>" . count($users_data['users'] ?? []) . "</strong></p>";
    echo "<p>🔍 Searches: <strong>" . ($stats['total_searches'] ?? 0) . "</strong></p><p>📤 Forwards: <strong>" . ($stats['total_forwards'] ?? 0) . "</strong></p>";
    
    echo "<h3>📢 Public Channels</h3>";
    foreach (getPublicChannels() as $ch) {
        echo "<p>{$ch['emoji']} <strong>{$ch['name']}</strong>: {$ch['username']}</p>";
    }
    
    echo "<p><a href='?setwebhook=1'><button>🔗 Setup Webhook</button></a></p>";
    echo "<p><small>Last Updated: " . date('Y-m-d H:i:s') . "</small></p></div></body></html>";
}
?>
