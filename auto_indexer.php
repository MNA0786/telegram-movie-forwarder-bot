<?php
// auto_indexer.php - Auto Indexing System

class AutoIndexer {
    private $db;
    private $processed_file = 'processed_posts.json';
    
    public function __construct($db) {
        $this->db = $db;
        $this->initProcessedFile();
    }
    
    private function initProcessedFile() {
        if (!file_exists($this->processed_file)) {
            file_put_contents($this->processed_file, json_encode(['processed' => []]));
        }
    }
    
    private function isProcessed($post_id) {
        $data = json_decode(file_get_contents($this->processed_file), true);
        return in_array($post_id, $data['processed']);
    }
    
    private function markProcessed($post_id) {
        $data = json_decode(file_get_contents($this->processed_file), true);
        $data['processed'][] = $post_id;
        if (count($data['processed']) > 10000) {
            $data['processed'] = array_slice($data['processed'], -5000);
        }
        file_put_contents($this->processed_file, json_encode($data));
    }
    
    private function extractMovieName($message) {
        if (isset($message['caption']) && !empty(trim($message['caption']))) {
            $name = trim($message['caption']);
        } elseif (isset($message['text']) && !empty(trim($message['text']))) {
            $name = trim($message['text']);
        } elseif (isset($message['document']['file_name'])) {
            $name = pathinfo($message['document']['file_name'], PATHINFO_FILENAME);
        } else {
            $name = 'Media_' . date('d-m-Y_H-i-s');
        }
        
        // Clean name
        $name = preg_replace('/\.(mp4|mkv|avi|mov|wmv|flv|webm)$/i', '', $name);
        $name = preg_replace('/\b(480p|720p|1080p|2160p|4k|HD|FHD|UHD|HDRip|WebRip|BluRay|x264|x265|HEVC|AAC|Subs|ESubs)\b/i', '', $name);
        $name = preg_replace('/[^\p{L}\p{N}\s\.\-\(\)]/u', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim($name);
        $name = ucwords(strtolower($name));
        
        return $name;
    }
    
    private function extractYear($movie_name) {
        preg_match('/\b(19|20)\d{2}\b/', $movie_name, $matches);
        return $matches[0] ?? '';
    }
    
    private function extractSeasonEpisode($movie_name) {
        $season = null;
        $episode = null;
        
        if (preg_match('/S(\d{1,2})|Season\s+(\d+)/i', $movie_name, $matches)) {
            foreach ($matches as $match) {
                if (is_numeric($match) && $match > 0) {
                    $season = intval($match);
                    break;
                }
            }
        }
        
        if (preg_match('/E(\d{1,2})|Episode\s+(\d+)/i', $movie_name, $matches)) {
            foreach ($matches as $match) {
                if (is_numeric($match) && $match > 0) {
                    $episode = intval($match);
                    break;
                }
            }
        }
        
        return ['season' => $season, 'episode' => $episode];
    }
    
    public function indexPost($message, $channel_id) {
        $post_id = $message['message_id'];
        
        if ($this->isProcessed($post_id)) {
            return ['status' => 'skipped', 'reason' => 'already_processed'];
        }
        
        $movie_name = $this->extractMovieName($message);
        if (empty($movie_name)) {
            return ['status' => 'failed', 'reason' => 'empty_name'];
        }
        
        $year = $this->extractYear($movie_name);
        $se = $this->extractSeasonEpisode($movie_name);
        
        $result = $this->db->addMovie($movie_name, $post_id, $channel_id, $year, $se['season'], $se['episode']);
        
        if ($result) {
            $this->markProcessed($post_id);
            $this->logIndexing($movie_name, $post_id, $channel_id, 'success');
            return ['status' => 'success', 'movie_name' => $movie_name, 'post_id' => $post_id];
        } else {
            $this->logIndexing($movie_name, $post_id, $channel_id, 'failed');
            return ['status' => 'failed', 'reason' => 'duplicate'];
        }
    }
    
    private function logIndexing($name, $post_id, $channel_id, $status) {
        $log_file = 'indexing_log.json';
        $logs = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) : [];
        
        array_unshift($logs, [
            'timestamp' => date('Y-m-d H:i:s'),
            'movie_name' => $name,
            'post_id' => $post_id,
            'channel_id' => $channel_id,
            'status' => $status
        ]);
        
        if (count($logs) > 1000) {
            $logs = array_slice($logs, 0, 1000);
        }
        
        file_put_contents($log_file, json_encode($logs, JSON_PRETTY_PRINT));
    }
    
    public function getStats() {
        return [
            'total_movies' => $this->db->getTotalMovieCount(),
            'channel_breakdown' => $this->db->getChannelStats()
        ];
    }
}
?>