<?php
require '../vendor/autoload.php';

$botToken = "7303663425:AAHh1K1nhoVMQx_gIbvI4s0jDEm35hh8Q88";
$apiURL = "https://api.telegram.org/bot$botToken/";

use GuzzleHttp\Client;

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

$lastUpdateId = 0;
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
            } elseif (isset($update['message']['photo'])) {
                $messageType = 'photo';
                $photo = end($update['message']['photo']);
                $fileId = $photo['file_id'];
                $responseMessage .= "Message type: $messageType\n";
                $responseMessage .= "File ID: $fileId";
            } elseif (isset($update['message']['sticker'])) {
                $messageType = 'sticker';
                $fileId = $update['message']['sticker']['file_id'];
                $responseMessage .= "Message type: $messageType\n";
                $responseMessage .= "File ID: $fileId";
            } else {
                $messageType = 'unknown';
                $responseMessage .= "Message type: $messageType";
            }

            sendMessage($chatId, $responseMessage);
            $lastUpdateId = $update['update_id'];
        }
    } else {
        error_log("Error fetching updates: " . print_r($updates, true));
    }
    sleep(1); 
}
?>
