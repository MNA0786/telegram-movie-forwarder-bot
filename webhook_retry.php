<?php
// webhook_retry.php - Webhook Retry System

function processWithRetry($update, $max_retries = 3) {
    $attempt = 0;
    $last_error = null;
    
    while ($attempt < $max_retries) {
        try {
            $result = processUpdate($update);
            if ($result !== false) {
                return $result;
            }
        } catch (Exception $e) {
            $last_error = $e->getMessage();
        }
        
        $attempt++;
        if ($attempt < $max_retries) {
            $wait = pow(2, $attempt);
            sleep($wait);
        }
    }
    
    error_log("Webhook failed after $max_retries attempts: " . ($last_error ?? 'Unknown error'));
    return false;
}

function processUpdate($update) {
    global $MAINTENANCE_MODE, $db, $autoIndexer, $telegram;
    
    if ($MAINTENANCE_MODE) {
        return false;
    }
    
    // Channel Post Handler
    if (isset($update['channel_post'])) {
        $message = $update['channel_post'];
        $chat_id = $message['chat']['id'];
        
        $our_channels = [
            MAIN_CHANNEL_ID, SERIAL_CHANNEL_ID, THEATER_CHANNEL_ID,
            BACKUP_CHANNEL_ID, PRIVATE_CHANNEL_1, PRIVATE_CHANNEL_2
        ];
        
        if (in_array($chat_id, $our_channels)) {
            $autoIndexer->indexPost($message, $chat_id);
            
            // Also append to CSV
            $movie_name = '';
            if (isset($message['caption'])) $movie_name = $message['caption'];
            elseif (isset($message['text'])) $movie_name = $message['text'];
            elseif (isset($message['document']['file_name'])) $movie_name = $message['document']['file_name'];
            
            if (!empty(trim($movie_name))) {
                $handle = fopen(CSV_FILE, "a");
                fputcsv($handle, [trim($movie_name), $message['message_id'], $chat_id]);
                fclose($handle);
            }
        }
    }
    
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
                'last_name' => $message['from']['last_name'] ?? '',
                'username' => $message['from']['username'] ?? '',
                'joined' => date('Y-m-d H:i:s'),
                'last_active' => date('Y-m-d H:i:s')
            ];
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
        } else {
            $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
        }
        
        // Show typing for non-commands
        if (!empty($text) && strpos($text, '/') !== 0) {
            $telegram->sendTypingAction($chat_id, 'typing');
            usleep(500000);
        }
        
        // Handle commands
        if (strpos($text, '/') === 0) {
            $cmd = explode(' ', $text)[0];
            
            if ($cmd == '/start') {
                $telegram->sendTypingAction($chat_id, 'typing');
                usleep(400000);
                
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
                $welcome .= "• 🎬 @EntertainmentTadka786\n";
                $welcome .= "• 📺 @Entertainment_Tadka_Serial_786\n";
                $welcome .= "• 🎭 @threater_print_movies\n";
                $welcome .= "• 💾 @ETBackup\n\n";
                $welcome .= "💬 <b>Request Group:</b> @EntertainmentTadka7860\n";
                $welcome .= "🤖 <b>Bot:</b> @EntertainmentTadka2Bot";
                
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '📢 Join Channel', 'url' => 'https://t.me/EntertainmentTadka786']],
                        [['text' => '💬 Request Group', 'url' => 'https://t.me/EntertainmentTadka7860']]
                    ]
                ];
                sendMessage($chat_id, $welcome, $keyboard);
            }
            elseif ($cmd == '/help') {
                $telegram->sendTypingAction($chat_id, 'typing');
                usleep(300000);
                
                $help = "🤖 <b>Bot Commands</b>\n\n";
                $help .= "/start - Welcome message\n";
                $help .= "/help - This help menu\n";
                $help .= "/channels - Our channels list\n\n";
                $help .= "🔍 <b>Just type any movie name to search!</b>";
                sendMessage($chat_id, $help);
            }
            elseif ($cmd == '/channels') {
                $telegram->sendTypingAction($chat_id, 'typing');
                usleep(300000);
                
                $msg = "📢 <b>Our Channels</b>\n\n";
                $msg .= "🎬 @EntertainmentTadka786\n";
                $msg .= "📺 @Entertainment_Tadka_Serial_786\n";
                $msg .= "🎭 @threater_print_movies\n";
                $msg .= "💾 @ETBackup\n\n";
                $msg .= "💬 <b>Request Group:</b> @EntertainmentTadka7860";
                sendMessage($chat_id, $msg);
            }
            elseif ($cmd == '/admin' && isAdmin($user_id)) {
                admin_panel($chat_id);
            }
            elseif ($cmd == '/stats' && isAdmin($user_id)) {
                $telegram->sendTypingAction($chat_id, 'typing');
                usleep(600000);
                
                $stats = get_stats();
                $users_data = json_decode(file_get_contents(USERS_FILE), true);
                $total_movies = $db->getTotalMovieCount();
                
                $msg = "📊 <b>Bot Statistics</b>\n\n";
                $msg .= "🎬 Total Movies: " . $total_movies . "\n";
                $msg .= "👥 Total Users: " . count($users_data['users'] ?? []) . "\n";
                $msg .= "🔍 Total Searches: " . ($stats['total_searches'] ?? 0) . "\n";
                $msg .= "📤 Total Forwards: " . ($stats['total_forwards'] ?? 0) . "\n";
                $msg .= "🕒 Last Updated: " . ($stats['last_updated'] ?? date('Y-m-d H:i:s')) . "\n";
                sendMessage($chat_id, $msg);
            }
            elseif ($cmd == '/indexstats' && isAdmin($user_id)) {
                $telegram->sendTypingAction($chat_id, 'typing');
                usleep(500000);
                
                $stats = $autoIndexer->getStats();
                $msg = "📊 <b>Auto Indexing Stats</b>\n\n";
                $msg .= "🎬 Total Movies: " . $stats['total_movies'] . "\n\n";
                $msg .= "<b>Channel Breakdown:</b>\n";
                foreach ($stats['channel_breakdown'] as $channel => $count) {
                    $name = $channel == MAIN_CHANNEL_ID ? 'Main Channel' : 
                           ($channel == SERIAL_CHANNEL_ID ? 'Serial Channel' :
                           ($channel == THEATER_CHANNEL_ID ? 'Theater Print' :
                           ($channel == BACKUP_CHANNEL_ID ? 'Backup' : 'Private')));
                    $msg .= "• $name: $count movies\n";
                }
                sendMessage($chat_id, $msg);
            }
        }
        elseif (!empty($text) && strlen($text) > 2) {
            // Search with typing
            $search_result = $db->searchMovies($text);
            
            if (!empty($search_result['movies'])) {
                $msg = "";
                if ($search_result['was_corrected']) {
                    $msg .= "🔍 Did you mean: <b>" . ucwords($search_result['corrected_query']) . "</b>?\n\n";
                }
                $msg .= "🎬 <b>Found " . count($search_result['movies']) . " results:</b>\n\n";
                
                $keyboard = ['inline_keyboard' => []];
                $shown = 0;
                foreach ($search_result['movies'] as $movie) {
                    if ($shown >= 15) break;
                    $name = htmlspecialchars(substr($movie['movie_name'], 0, 60));
                    $keyboard['inline_keyboard'][] = [['text' => "🎬 " . $name, 'callback_data' => "send_" . $movie['message_id'] . "_" . $movie['channel_id']]];
                    $shown++;
                }
                $keyboard['inline_keyboard'][] = [['text' => '📢 Join Channel', 'url' => 'https://t.me/EntertainmentTadka786']];
                
                sendMessage($chat_id, $msg, $keyboard);
                update_stats('total_searches', 1);
            }
            elseif (!empty($search_result['similar_movies'])) {
                $msg = "😔 No exact match for '<b>$text</b>'.\n\n";
                $msg .= "💡 <b>Similar movies you might like:</b>\n\n";
                
                $keyboard = ['inline_keyboard' => []];
                foreach ($search_result['similar_movies'] as $movie) {
                    $name = htmlspecialchars(substr($movie['movie_name'], 0, 50));
                    $keyboard['inline_keyboard'][] = [['text' => "🎬 " . $name, 'callback_data' => "send_" . $movie['message_id'] . "_" . $movie['channel_id']]];
                }
                $keyboard['inline_keyboard'][] = [['text' => '📢 Join Channel', 'url' => 'https://t.me/EntertainmentTadka786']];
                
                sendMessage($chat_id, $msg, $keyboard);
            }
            else {
                $trending = $db->getTrendingMovies(5);
                $msg = "😔 No results found for '<b>$text</b>'.\n\n";
                $msg .= "📝 <b>Try:</b>\n";
                $msg .= "• Checking spelling\n";
                $msg .= "• Using fewer words\n";
                $msg .= "• Request in @EntertainmentTadka7860\n\n";
                
                if (!empty($trending)) {
                    $msg .= "🔥 <b>Trending now:</b>\n";
                    foreach ($trending as $trend) {
                        $msg .= "• $trend\n";
                    }
                }
                sendMessage($chat_id, $msg);
                update_stats('total_searches', 1);
            }
        }
    }
    
    // Callback Query Handler
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
                update_stats('total_forwards', 1);
            } else {
                answerCallbackQuery($query['id'], "❌ Invalid", true);
            }
        }
        elseif ($data == 'admin_panel' && isAdmin($query['from']['id'])) {
            admin_panel($chat_id, $query['message']['message_id']);
            answerCallbackQuery($query['id']);
        }
        elseif ($data == 'admin_stats' && isAdmin($query['from']['id'])) {
            admin_stats_panel($chat_id, $query['message']['message_id']);
            answerCallbackQuery($query['id']);
        }
        elseif ($data == 'admin_users' && isAdmin($query['from']['id'])) {
            admin_users_panel($chat_id, $query['message']['message_id']);
            answerCallbackQuery($query['id']);
        }
        elseif ($data == 'admin_backup' && isAdmin($query['from']['id'])) {
            admin_backup_panel($chat_id, $query['message']['message_id']);
            answerCallbackQuery($query['id']);
        }
        elseif ($data == 'admin_check_csv' && isAdmin($query['from']['id'])) {
            admin_check_csv_panel($chat_id, $query['message']['message_id']);
            answerCallbackQuery($query['id']);
        }
        else {
            answerCallbackQuery($query['id'], "❌ Invalid option", true);
        }
    }
    
    return true;
}
?>