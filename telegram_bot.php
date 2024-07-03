<?php
$botToken = "7303663425:AAHh1K1nhoVMQx_gIbvI4s0jDEm35hh8Q88";
$apiURL = "https://api.telegram.org/bot$botToken/";

function getUpdates($offset = 0) {
    global $apiURL;
    $url = $apiURL . "getUpdates?offset=$offset";
    $response = file_get_contents($url);
    return json_decode($response, true);
}

function sendMessage($chatId, $message) {
    global $apiURL;
    $url = $apiURL . "sendMessage";
    $postData = [
        'chat_id' => $chatId,
        'text' => $message
    ];
    $options = [
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($postData),
        ],
    ];
    $context = stream_context_create($options);
    file_get_contents($url, false, $context);
}

$lastUpdateId = 0;
while (true) {
    $updates = getUpdates($lastUpdateId + 1);
    foreach ($updates['result'] as $update) {
        $chatId = $update['message']['chat']['id'];
        $responseMessage = "Your message has been recived.\n";

        if (isset($update['message']['text'])) {
            $messageType = 'text';
            $messageContent = $update['message']['text'];
            $responseMessage .= "Type of message: $messageType\n";
            $responseMessage .= "Content of message: $messageContent";
        } elseif (isset($update['message']['photo'])) {
            $messageType = 'photo';
            $photo = end($update['message']['photo']); 
            $fileId = $photo['file_id'];
            $responseMessage .= "Type of message: $messageType\n";
            $responseMessage .= "File ID: $fileId";
        } elseif (isset($update['message']['sticker'])) {
            $messageType = 'sticker';
            $fileId = $update['message']['sticker']['file_id'];
            $responseMessage .= "Type of message: $messageType\n";
            $responseMessage .= "File ID: $fileId";
        } else {
            $messageType = 'unknown';
            $responseMessage .= "Type of message: $messageType";
        }

        sendMessage($chatId, $responseMessage);
        $lastUpdateId = $update['update_id'];
    }
    sleep(1); 
}
?>
