<?php
// telegram_api.php - Telegram API with Typing Indicators

class TelegramAPI {
    private $bot_token;
    
    public function __construct($bot_token) {
        $this->bot_token = $bot_token;
    }
    
    public function sendTypingAction($chat_id, $action = 'typing') {
        $valid_actions = ['typing', 'upload_photo', 'record_video', 'upload_video', 'record_audio', 'upload_audio', 'upload_document', 'find_location'];
        
        if (!in_array($action, $valid_actions)) {
            $action = 'typing';
        }
        
        $url = "https://api.telegram.org/bot{$this->bot_token}/sendChatAction";
        $data = ['chat_id' => $chat_id, 'action' => $action];
        
        return $this->makeRequest($url, $data);
    }
    
    public function sendMessageWithTyping($chat_id, $text, $typing_duration = 2, $reply_markup = null, $parse_mode = 'HTML') {
        $this->sendTypingAction($chat_id, 'typing');
        usleep($typing_duration * 1000000);
        
        $url = "https://api.telegram.org/bot{$this->bot_token}/sendMessage";
        $data = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => $parse_mode];
        if ($reply_markup) {
            $data['reply_markup'] = json_encode($reply_markup);
        }
        
        return $this->makeRequest($url, $data);
    }
    
    private function makeRequest($url, $data) {
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
}

function sendTyping($chat_id, $action = 'typing') {
    global $telegram;
    return $telegram->sendTypingAction($chat_id, $action);
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = 'HTML') {
    global $telegram;
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
?>