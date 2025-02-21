<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/bot_errors.log');

require_once 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

define('TELEGRAM_TOKEN', $_ENV['TELEGRAM_TOKEN']);
define('GROQ_API_KEY', $_ENV['GROQ_API_KEY']);
define('LOG_FILE', 'bot.log');
define('CUSTOM_CARDS_DIR', 'cards/custom/');
define('USER_SESSIONS_DIR', 'users/');

define('AI_MODELS', [
    'qwen-2.5-32b',
    'deepseek-r1-distill-llama-70b',
    'gemma2-9b-it',
    'llama-3.1-8b-instant',
    'llama-3.2-11b-vision-preview',
    'llama-3.2-1b-preview',
    'llama-3.2-3b-preview',
    'llama-3.2-90b-vision-preview',
    'llama-3.3-70b-specdec',
    'llama-3.3-70b-versatile',
    'llama-guard-3-8b',
    'llama3-70b-8192',
    'llama3-8b-8192',
    'mixtral-8x7b-32768',
    'kobold' // Add KoboldAI as a model option
]);

foreach ([CUSTOM_CARDS_DIR, USER_SESSIONS_DIR] as $dir) {
    if (!file_exists($dir)) mkdir($dir, 0755, true);
}

function logMessage($message) {
    file_put_contents(LOG_FILE, "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL, FILE_APPEND);
}

function loadCharacterCards() {
    $cards = [];
    foreach (glob('cards/*.json') as $file) {
        $cardData = json_decode(file_get_contents($file), true);
        $cards[basename($file, '.json')] = $cardData;
    }
    foreach (glob(CUSTOM_CARDS_DIR . '*.json') as $file) {
        $cardData = json_decode(file_get_contents($file), true);
        $cardName = basename($file, '.json');
        $cardName = substr($cardName, strpos($cardName, '_') + 1);
        $cards[$cardName] = $cardData;
    }
    return $cards;
}

function extractCharacterDataFromPNG($imagePath) {
    $file = fopen($imagePath, 'rb');
    if (!$file) {
        error_log("Failed to open PNG file: $imagePath");
        return null;
    }

    $header = fread($file, 8);
    if ($header !== "\x89PNG\x0D\x0A\x1A\x0A") {
        error_log("Invalid PNG header: " . bin2hex($header));
        fclose($file);
        return null;
    }

    $foundChara = false;
    $charaData = null;

    while (!feof($file)) {
        $lengthData = fread($file, 4);
        if (strlen($lengthData) < 4) break;
        $length = unpack('N', $lengthData)[1];
        
        $type = fread($file, 4);
        if (strlen($type) < 4) break;
        
        $data = $length > 0 ? fread($file, $length) : '';
        fread($file, 4);

        if ($type === 'tEXt') {
            $parts = explode("\0", $data, 2);
            if (count($parts) === 2 && $parts[0] === 'chara') {
                $charaData = base64_decode($parts[1]);
                if (json_decode($charaData) === null) {
                    error_log("Invalid JSON in chara chunk");
                    continue;
                }
                $foundChara = true;
                break;
            }
        }
        
        if ($type === 'IEND') break;
    }
    
    fclose($file);
    return $foundChara ? $charaData : null;
}

function handleFileUpload($userId, $fileId, $fileName) {
    try {
        $fileUrl = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/getFile?file_id={$fileId}";
        $fileResponse = json_decode(file_get_contents($fileUrl), true);
        
        if (!isset($fileResponse['result']['file_path'])) {
            error_log("Telegram API error: " . json_encode($fileResponse));
            return ['success' => false, 'message' => 'File access error'];
        }

        $filePath = $fileResponse['result']['file_path'];
        $fileContent = file_get_contents("https://api.telegram.org/file/bot" . TELEGRAM_TOKEN . "/{$filePath}");

        $isImage = pathinfo($fileName, PATHINFO_EXTENSION) === 'png';
        if ($isImage) {
            $tempFile = tempnam(sys_get_temp_dir(), 'char_card');
            file_put_contents($tempFile, $fileContent);
            $jsonData = extractCharacterDataFromPNG($tempFile);
            unlink($tempFile);

            if (!$jsonData) {
                error_log("PNG processing failed");
                return ['success' => false, 'message' => 'Invalid character card image. Send as FILE not PHOTO.'];
            }
            $fileContent = $jsonData;
        }

        $cardData = json_decode($fileContent, true);
        if (!$cardData || !isset($cardData['data']['name'])) {
            error_log("Invalid card data structure");
            return ['success' => false, 'message' => 'Invalid character card format'];
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $cardData['data']['name']);
        $fileName = "{$userId}_{$safeName}.json";
        $fullPath = CUSTOM_CARDS_DIR . $fileName;
        
        if (!file_put_contents($fullPath, $fileContent)) {
            error_log("Failed to save file: $fullPath");
            return ['success' => false, 'message' => 'File save failed'];
        }

        return [
            'success' => true,
            'cardName' => $safeName,
            'cardData' => $cardData,
            'message' => 'Character card processed successfully!'
        ];
    } catch (Exception $e) {
        error_log("File upload error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Processing error'];
    }
}

function sendDocument($chatId, $filePath, $fileName) {
    try {
        $data = [
            'chat_id' => $chatId,
            'document' => new CURLFile($filePath, 'application/json', $fileName)
        ];

        $ch = curl_init("https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/sendDocument");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            error_log("Document send failed: HTTP $httpCode - $response");
        }
        
        curl_close($ch);
    } catch (Exception $e) {
        error_log("Document send error: " . $e->getMessage());
    }
}

function sendToGroqAPI($messageHistory, $model = 'llama-3.3-70b-versatile') {
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => $messageHistory
        ]),
        CURLOPT_RETURNTRANSFER => true
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    // Log Groq API payload and response
    error_log("Groq API Payload: " . json_encode(['model' => $model, 'messages' => $messageHistory]));
    error_log("Groq API Response: " . $response);

    return json_decode($response, true);
}

function sendToKoboldAPI($prompt, $card, $charName) {
    $url = 'https://koboldai-koboldcpp-tiefighter.hf.space/api/extra/generate/stream';
    
    // Custom memory structure
    $memory = "Name: {$card['data']['name']}\n" .
              "Summary: {$card['data']['scenario']}\n" .
              "Personality: {$card['data']['personality']}\n" .
              "First Message: {$card['data']['first_mes']}";

    $payload = [
        'n' => 1,
        'max_context_length' => 4096,
        'max_length' => 240,
        'rep_pen' => 1.07,
        'temperature' => 0.75,
        'top_p' => 0.92,
        'top_k' => 100,
        'top_a' => 0,
        'typical' => 1,
        'tfs' => 1,
        'rep_pen_range' => 360,
        'rep_pen_slope' => 0.7,
        'sampler_order' => [6, 0, 1, 3, 4, 2, 5],
        'memory' => $memory,
        'trim_stop' => true,
        'genkey' => 'KCPP3222',
        'min_p' => 0,
        'dynatemp_range' => 0,
        'dynatemp_exponent' => 1,
        'smoothing_factor' => 0,
        'banned_tokens' => [],
        'render_special' => false,
        'logprobs' => false,
        'presence_penalty' => 0,
        'logit_bias' => [],
        'prompt' => $prompt,
        'quiet' => true,
        'stop_sequence' => ["User:", "\nUser ", "\n{$charName}: "],
        'use_default_badwordsids' => false,
        'bypass_eos' => false
    ];

    // Log KoboldAI API payload
    error_log("KoboldAI API Payload: " . json_encode($payload));

    // Initialize cURL
    $ch = curl_init($url);

    // Variable to store the final response
    $finalResponse = '';

    // Set cURL options
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: text/event-stream' // Required for streaming
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$finalResponse) {
            // Process each chunk of data
            if (strpos($data, 'data:') !== false) {
                $json = substr($data, strpos($data, 'data:') + 6);
                $decoded = json_decode($json, true);
                if (isset($decoded['token'])) {
                    $finalResponse .= $decoded['token'];
                }
            }
            return strlen($data); // Return the length of the data to continue the stream
        }
    ]);

    // Execute the cURL request
    $response = curl_exec($ch);

    // Check for cURL errors
    if (curl_errno($ch)) {
        error_log("KoboldAI API Error: " . curl_error($ch));
        curl_close($ch);
        return null;
    }

    // Close cURL
    curl_close($ch);

    // Log the final response
    error_log("KoboldAI API Final Response: " . $finalResponse);

    return $finalResponse;
}

function createKeyboard($userId = null) {
    $cards = loadCharacterCards();
    $cardNames = array_keys($cards);

    if ($userId) {
        foreach (glob(CUSTOM_CARDS_DIR . "{$userId}_*.json") as $file) {
            $cardName = basename($file, '.json');
            $cardName = substr($cardName, strpos($cardName, '_') + 1);
            $cardNames[] = $cardName;
        }
    }

    $keyboardItems = array_merge($cardNames, ['/stop', 'Upload Custom Card', 'Change AI Model']);
    return [
        'keyboard' => array_chunk($keyboardItems, 2),
        'one_time_keyboard' => true,
        'resize_keyboard' => true
    ];
}

function createModelKeyboard() {
    return [
        'keyboard' => array_chunk(AI_MODELS, 2),
        'one_time_keyboard' => true,
        'resize_keyboard' => true
    ];
}

function personalizeMessage($message, $userName, $charName) {
    return str_replace(
        ['{{user}}', '{{char}}'],
        [$userName, $charName],
        $message
    );
}

function generateSystemPrompt($card, $userName) {
    $basePrompt = $card['data']['system_prompt'];
    return personalizeMessage(
        "You are {$card['data']['name']}. Personality: {$card['data']['personality']}. " .
        "Scenario: {$card['data']['scenario']}. {$basePrompt} " .
        "Always stay in character.",
        $userName,
        $card['data']['name']
    );
}

function sendMessage($chatId, $text, $keyboard = null) {
    $data = ['chat_id' => $chatId, 'text' => $text];
    if ($keyboard) $data['reply_markup'] = json_encode($keyboard);

    $ch = curl_init("https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function handleTelegramUpdate($update) {
    $chatId = $update['message']['chat']['id'];
    $userId = $update['message']['from']['id'];
    $firstName = $update['message']['from']['first_name'];
    $text = $update['message']['text'] ?? '';

    logMessage("User {$firstName} ({$userId}): {$text}");

    if (isset($update['message']['document'])) {
        $doc = $update['message']['document'];
        $result = handleFileUpload($userId, $doc['file_id'], $doc['file_name']);

        if ($result['success']) {
            $fileName = "{$userId}_{$result['cardName']}.json";
            $filePath = CUSTOM_CARDS_DIR . $fileName;
            sendDocument($chatId, $filePath, $fileName);
            sendMessage($chatId, "✅ {$result['message']} New character '{$result['cardName']}' added!");
            sendMessage($chatId, "Choose a character:", createKeyboard($userId));
        } else {
            sendMessage($chatId, "❌ {$result['message']}");
        }
        return;
    }
    elseif (isset($update['message']['photo'])) {
        sendMessage($chatId, "⚠️ Please send PNG files as *FILE* (not photo) to avoid compression.\nUse the 'Attach as File' option.", ['parse_mode' => 'Markdown']);
        return;
    }

    switch ($text) {
        case '/start':
            sendMessage($chatId, "Welcome, {$firstName}! Choose a character:", createKeyboard($userId));
            break;

        case 'Upload Custom Card':
            sendMessage($chatId, "Send me a character card JSON file or PNG (as FILE not PHOTO).", ['remove_keyboard' => true]);
            break;

        case 'Change AI Model':
            sendMessage($chatId, "Select an AI model:", createModelKeyboard());
            break;

        case '/stop':
            $userFile = USER_SESSIONS_DIR . "{$userId}.json";
            if (file_exists($userFile)) {
                unlink($userFile);
                logMessage("Session cleared for {$firstName}");
            }
            sendMessage($chatId, "Session ended, {$firstName}. Use /start to begin again.");
            break;

        default:
            if (in_array($text, AI_MODELS)) {
                $userFile = USER_SESSIONS_DIR . "{$userId}.json";
                if (file_exists($userFile)) {
                    $data = json_decode(file_get_contents($userFile), true);
                    $data['ai_model'] = $text;
                    file_put_contents($userFile, json_encode($data));
                }
                sendMessage($chatId, "AI model set to: {$text}", createKeyboard($userId));
                logMessage("{$firstName} changed AI model to: {$text}");
                return;
            }

            $cards = loadCharacterCards();
            $cardNames = array_keys($cards);

            if (in_array($text, $cardNames)) {
                $selectedCard = $cards[$text];
                $charName = $selectedCard['data']['name'];
                $systemPrompt = generateSystemPrompt($selectedCard, $firstName);
                $firstMessage = personalizeMessage(
                    $selectedCard['data']['first_mes'],
                    $firstName,
                    $charName
                );

                file_put_contents(USER_SESSIONS_DIR . "{$userId}.json", json_encode([
                    'card' => $text,
                    'system_prompt' => $systemPrompt,
                    'char_name' => $charName,
                    'ai_model' => 'kobold', // Set Kobold as the default model
                    'message_history' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'assistant', 'content' => $firstMessage]
                    ]
                ]));

                sendMessage($chatId, $firstMessage);
                logMessage("{$firstName} selected: {$text}");
            } else {
                $userFile = USER_SESSIONS_DIR . "{$userId}.json";
                if (file_exists($userFile)) {
                    $data = json_decode(file_get_contents($userFile), true);

                    // Log the selected model
                    error_log("Selected Model: " . $data['ai_model']);

                    // Append user message to message history
                    $data['message_history'][] = ['role' => 'user', 'content' => $text];

                    // Use KoboldAI if selected, otherwise use Groq API
                    if ($data['ai_model'] === 'kobold') {
                        $selectedCard = $cards[$data['card']]; // Get the selected card
                        $prompt = implode("\n", array_map(function($msg) {
                            return "{$msg['role']}: {$msg['content']}";
                        }, $data['message_history']));
                        $aiResponse = sendToKoboldAPI($prompt, $selectedCard, $data['char_name']); // Pass card data
                        if ($aiResponse === null) {
                            sendMessage($chatId, "⚠️ Sorry, there was an issue generating a response. Please try again.");
                            return;
                        }
                    } else {
                        $response = sendToGroqAPI($data['message_history'], $data['ai_model'] ?? 'llama-3.3-70b-versatile');
                        $aiResponse = $response['choices'][0]['message']['content'];
                    }

                    // Append AI response to message history
                    $data['message_history'][] = ['role' => 'assistant', 'content' => $aiResponse];
                    file_put_contents($userFile, json_encode($data));

                    sendMessage($chatId, $aiResponse);
                } else {
                    sendMessage($chatId, "Please choose a character first, {$firstName}!", createKeyboard($userId));
                }
            }
            break;
    }
}

$update = json_decode(file_get_contents('php://input'), true);
if ($update) handleTelegramUpdate($update);