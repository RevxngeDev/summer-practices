<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;

class TelegramBot {
    private $apiURL;

    public function __construct($botToken) {
        $this->apiURL = "https://api.telegram.org/bot$botToken/";
    }

    public function getUpdates($offset = 0) {
        $client = new Client();
        $url = $this->apiURL . "getUpdates?offset=$offset";
        $response = $client->request('GET', $url);
        return json_decode($response->getBody(), true);
    }

    public function sendMessage($chatId, $message, $replyMarkup = null) {
        $client = new Client();
        $url = $this->apiURL . "sendMessage";
        $params = [
            'chat_id' => $chatId,
            'text' => $message
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        $response = $client->request('POST', $url, [
            'form_params' => $params
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode != 200) {
            error_log("Error sending message to $chatId: HTTP $statusCode");
        }
    }

    public function answerCallbackQuery($callbackQueryId, $text = null) {
        $client = new Client();
        $url = $this->apiURL . "answerCallbackQuery";
        $params = ['callback_query_id' => $callbackQueryId];
        if ($text) {
            $params['text'] = $text;
        }
        $client->request('POST', $url, ['form_params' => $params]);
    }
}
?>