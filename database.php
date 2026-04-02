<?php
// database.php - SQLite Database Class

class Database {
    private $db;
    
    public function __construct() {
        $this->db = new SQLite3(__DIR__ . '/movies.db');
        $this->createTables();
    }
    
    private function createTables() {
        // Movies table
        $this->db->exec("CREATE TABLE IF NOT EXISTS movies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            movie_name TEXT NOT NULL,
            message_id TEXT NOT NULL,
            channel_id TEXT NOT NULL,
            search_keywords TEXT,
            year TEXT,
            season INTEGER,
            episode INTEGER,
            indexed_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Create indexes
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_movie_name ON movies(movie_name)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_channel_id ON movies(channel_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_search_keywords ON movies(search_keywords)");
        
        // Search stats table
        $this->db->exec("CREATE TABLE IF NOT EXISTS search_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            search_term TEXT NOT NULL,
            search_count INTEGER DEFAULT 1,
            last_searched DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Webhook retry log table
        $this->db->exec("CREATE TABLE IF NOT EXISTS webhook_retry_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            update_data TEXT,
            retry_count INTEGER DEFAULT 0,
            status TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }
    
    public function isDuplicate($movie_name, $message_id) {
        $stmt = $this->db->prepare("SELECT id FROM movies WHERE LOWER(movie_name) = LOWER(:name) OR message_id = :msg_id LIMIT 1");
        $stmt->bindValue(':name', $movie_name, SQLITE3_TEXT);
        $stmt->bindValue(':msg_id', $message_id, SQLITE3_TEXT);
        $result = $stmt->execute();
        return $result->fetchArray() !== false;
    }
    
    public function addMovie($movie_name, $message_id, $channel_id, $year = '', $season = null, $episode = null) {
        if ($this->isDuplicate($movie_name, $message_id)) {
            return false;
        }
        
        $keywords = $this->generateKeywords($movie_name);
        $indexed_at = date('Y-m-d H:i:s');
        
        $stmt = $this->db->prepare("INSERT INTO movies (movie_name, message_id, channel_id, search_keywords, year, season, episode, indexed_at) 
                                    VALUES (:name, :msg_id, :channel_id, :keywords, :year, :season, :episode, :indexed_at)");
        $stmt->bindValue(':name', $movie_name, SQLITE3_TEXT);
        $stmt->bindValue(':msg_id', $message_id, SQLITE3_TEXT);
        $stmt->bindValue(':channel_id', $channel_id, SQLITE3_TEXT);
        $stmt->bindValue(':keywords', $keywords, SQLITE3_TEXT);
        $stmt->bindValue(':year', $year, SQLITE3_TEXT);
        $stmt->bindValue(':season', $season, $season ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt->bindValue(':episode', $episode, $episode ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt->bindValue(':indexed_at', $indexed_at, SQLITE3_TEXT);
        
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
        
        if (empty($conditions)) {
            return [];
        }
        
        $sql = "SELECT DISTINCT movie_name, message_id, channel_id 
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
    
    public function getTrendingMovies($limit = 10) {
        $result = $this->db->query("SELECT search_term, search_count 
                                    FROM search_stats 
                                    ORDER BY search_count DESC, last_searched DESC 
                                    LIMIT $limit");
        $trending = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $trending[] = ucwords($row['search_term']);
        }
        return $trending;
    }
    
    public function searchMovies($query) {
        $query = strtolower(trim($query));
        $corrected = $this->autoCorrect($query);
        $search_term = $corrected ? $corrected : $query;
        
        $this->updateSearchStats($search_term);
        
        $stmt = $this->db->prepare("SELECT movie_name, message_id, channel_id 
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
        $result = $this->db->query("SELECT movie_name, message_id, channel_id FROM movies LIMIT $limit OFFSET $offset");
        $movies = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $movies[] = $row;
        }
        return $movies;
    }
    
    public function getTotalMovieCount() {
        $result = $this->db->querySingle("SELECT COUNT(*) as total FROM movies");
        return $result;
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
    
    public function getChannelStats() {
        $result = $this->db->query("SELECT channel_id, COUNT(*) as count FROM movies GROUP BY channel_id");
        $stats = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $stats[$row['channel_id']] = $row['count'];
        }
        return $stats;
    }
}
?>