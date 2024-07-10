<?php
require 'TelegramBot.php';
require 'Database.php';
require 'BotHandler.php';

$botToken = "7303663425:AAHh1K1nhoVMQx_gIbvI4s0jDEm35hh8Q88";
$dbHost = 'localhost';
$dbPort = '5432';
$dbName = 'telegram_bot';
$dbUser = 'postgres';
$dbPass = 'andrescamilo4';

$telegramBot = new TelegramBot($botToken);
$database = new Database($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
$botHandler = new BotHandler($telegramBot, $database);

$lastUpdateId = $database->getLastUpdateId();

while (true) {
    $updates = $telegramBot->getUpdates($lastUpdateId + 1);
    if (isset($updates['ok']) && $updates['ok'] === true && isset($updates['result'])) {
        foreach ($updates['result'] as $update) {
            $botHandler->handleUpdate($update);
            $lastUpdateId = $update['update_id'];
        }
    } else {
        error_log("Error fetching updates: " . print_r($updates, true));
    }
    sleep(1);
}
?>
