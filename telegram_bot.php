<?php
require 'vendor/autoload.php';

$botToken = "7303663425:AAHh1K1nhoVMQx_gIbvI4s0jDEm35hh8Q88";
$apiURL = "https://api.telegram.org/bot$botToken/";

use GuzzleHttp\Client;

// Configuración de la base de datos
$dbHost = 'localhost';
$dbPort = '5432';
$dbName = 'telegram_bot';
$dbUser = 'postgres';
$dbPass = 'andrescamilo4';

try {
    $pdo = new PDO("pgsql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error connecting to the database: " . $e->getMessage());
}

function getUpdates($offset = 0) {
    global $apiURL;
    $client = new Client();
    $url = $apiURL . "getUpdates?offset=$offset";
    $response = $client->request('GET', $url);
    return json_decode($response->getBody(), true);
}

function sendMessage($chatId, $message) {
    global $apiURL;
    $client = new Client();
    $url = $apiURL . "sendMessage";
    $response = $client->request('POST', $url, [
        'form_params' => [
            'chat_id' => $chatId,
            'text' => $message
        ]
    ]);

    $statusCode = $response->getStatusCode();
    if ($statusCode != 200) {
        error_log("Error sending message to $chatId: HTTP $statusCode");
    }
}

function getLastUpdateId($pdo) {
    $stmt = $pdo->query("SELECT update_id FROM last_update_id ORDER BY id DESC LIMIT 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['update_id'] : 0;
}

function setLastUpdateId($pdo, $updateId) {
    $stmt = $pdo->prepare("INSERT INTO last_update_id (update_id) VALUES (:update_id)");
    $stmt->execute(['update_id' => $updateId]);
}

function saveMessage($pdo, $chatId, $messageType, $messageContent = null, $fileId = null) {
    $stmt = $pdo->prepare("INSERT INTO messages (chat_id, message_type, message_content, file_id) VALUES (:chat_id, :message_type, :message_content, :file_id)");
    $stmt->execute([
        'chat_id' => $chatId,
        'message_type' => $messageType,
        'message_content' => $messageContent,
        'file_id' => $fileId
    ]);
}

$lastUpdateId = getLastUpdateId($pdo);

while (true) {
    $updates = getUpdates($lastUpdateId + 1);
    if (isset($updates['ok']) && $updates['ok'] === true && isset($updates['result'])) {
        foreach ($updates['result'] as $update) {
            $chatId = $update['message']['chat']['id'];
            $responseMessage = "Your message has been received.\n";

            if (isset($update['message']['text'])) {
                $messageType = 'text';
                $messageContent = $update['message']['text'];
                $responseMessage .= "Message type: $messageType\n";
                $responseMessage .= "Message content: $messageContent";
                saveMessage($pdo, $chatId, $messageType, $messageContent);
            } elseif (isset($update['message']['photo'])) {
                $messageType = 'photo';
                $photo = end($update['message']['photo']);
                $fileId = $photo['file_id'];
                $responseMessage .= "Message type: $messageType\n";
                $responseMessage .= "File ID: $fileId";
                saveMessage($pdo, $chatId, $messageType, null, $fileId);
            } elseif (isset($update['message']['sticker'])) {
                $messageType = 'sticker';
                $fileId = $update['message']['sticker']['file_id'];
                $responseMessage .= "Message type: $messageType\n";
                $responseMessage .= "File ID: $fileId";
                saveMessage($pdo, $chatId, $messageType, null, $fileId);
            } else {
                $messageType = 'unknown';
                $responseMessage .= "Message type: $messageType";
                saveMessage($pdo, $chatId, $messageType);
            }

            sendMessage($chatId, $responseMessage);
            $lastUpdateId = $update['update_id'];
            setLastUpdateId($pdo, $lastUpdateId);
        }
    } else {
        error_log("Error fetching updates: " . print_r($updates, true));
    }
    sleep(1); 
}
?>
