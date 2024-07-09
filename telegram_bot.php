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

function sendMessage($chatId, $message, $replyMarkup = null) {
    global $apiURL;
    $client = new Client();
    $url = $apiURL . "sendMessage";
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

function getLastUpdateId($pdo) {
    $stmt = $pdo->query("SELECT update_id FROM last_update_id ORDER BY id DESC LIMIT 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['update_id'] : 0;
}

function setLastUpdateId($pdo, $updateId) {
    $stmt = $pdo->prepare("INSERT INTO last_update_id (update_id) VALUES (:update_id)");
    $stmt->execute(['update_id' => $updateId]);
}

function setUserState($pdo, $chatId, $state, $data = null) {
    $stmt = $pdo->prepare("INSERT INTO user_state (chat_id, state, data) VALUES (:chat_id, :state, :data)
                           ON CONFLICT (chat_id) DO UPDATE SET state = EXCLUDED.state, data = EXCLUDED.data");
    $stmt->execute([
        'chat_id' => $chatId,
        'state' => $state,
        'data' => json_encode($data)
    ]);
}

function getUserState($pdo, $chatId) {
    $stmt = $pdo->prepare("SELECT state, data FROM user_state WHERE chat_id = :chat_id");
    $stmt->execute(['chat_id' => $chatId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? ['state' => $result['state'], 'data' => json_decode($result['data'], true)] : ['state' => null, 'data' => null];
}

function clearUserState($pdo, $chatId) {
    $stmt = $pdo->prepare("DELETE FROM user_state WHERE chat_id = :chat_id");
    $stmt->execute(['chat_id' => $chatId]);
}

function saveTest($pdo, $data) {
    $stmt = $pdo->prepare("INSERT INTO tests (test_name) VALUES (:test_name) RETURNING test_id");
    $stmt->execute(['test_name' => $data['test_name']]);
    $testId = $stmt->fetch(PDO::FETCH_ASSOC)['test_id'];

    foreach ($data['questions'] as $question) {
        $stmt = $pdo->prepare("INSERT INTO questions (test_id, question_text, correct_answer) VALUES (:test_id, :question_text, :correct_answer) RETURNING question_id");
        $stmt->execute([
            'test_id' => $testId,
            'question_text' => $question['question'],
            'correct_answer' => $question['correct']
        ]);
        $questionId = $stmt->fetch(PDO::FETCH_ASSOC)['question_id'];

        foreach ($question['answers'] as $answer) {
            $stmt = $pdo->prepare("INSERT INTO answers (question_id, answer_text) VALUES (:question_id, :answer_text)");
            $stmt->execute(['question_id' => $questionId, 'answer_text' => $answer]);
        }
    }
}

$lastUpdateId = getLastUpdateId($pdo);

while (true) {
    $updates = getUpdates($lastUpdateId + 1);
    if (isset($updates['ok']) && $updates['ok'] === true && isset($updates['result'])) {
        foreach ($updates['result'] as $update) {
            $chatId = $update['message']['chat']['id'];
            $text = $update['message']['text'] ?? '';

            $userState = getUserState($pdo, $chatId);

            switch ($userState['state']) {
                case 'creating_test':
                    if ($text === 'Confirm Name') {
                        sendMessage($chatId, "Please enter the first question:");
                        setUserState($pdo, $chatId, 'entering_question', $userState['data']);
                    } else {
                        $data = $userState['data'] ?? [];
                        $data['test_name'] = $text;
                        sendMessage($chatId, "Test name set to \"$text\". Click \"Confirm Name\" to proceed.", [
                            'keyboard' => [[['text' => 'Confirm Name']]],
                            'resize_keyboard' => true,
                            'one_time_keyboard' => true
                        ]);
                        setUserState($pdo, $chatId, 'creating_test', $data);
                    }
                    break;

                case 'entering_question':
                    if ($text === 'Confirm Question') {
                        sendMessage($chatId, "Please enter the four answer options separated by commas:");
                        setUserState($pdo, $chatId, 'entering_answers', $userState['data']);
                    } else {
                        $data = $userState['data'] ?? [];
                        $data['questions'][] = ['question' => $text, 'answers' => []];
                        sendMessage($chatId, "Question set to \"$text\". Click \"Confirm Question\" to proceed.", [
                            'keyboard' => [[['text' => 'Confirm Question']]],
                            'resize_keyboard' => true,
                            'one_time_keyboard' => true
                        ]);
                        setUserState($pdo, $chatId, 'entering_question', $data);
                    }
                    break;

                case 'entering_answers':
                    if ($text === 'Confirm Answers') {
                        sendMessage($chatId, "Please indicate the correct answer (1, 2, 3, or 4):");
                        setUserState($pdo, $chatId, 'entering_correct_answer', $userState['data']);
                    } else {
                        $answers = explode(',', $text);
                        if (count($answers) === 4) {
                            $data = $userState['data'];
                            $lastIndex = count($data['questions']) - 1;
                            $data['questions'][$lastIndex]['answers'] = array_map('trim', $answers);
                            sendMessage($chatId, "Answers set. Click \"Confirm Answers\" to proceed.", [
                                'keyboard' => [[['text' => 'Confirm Answers']]],
                                'resize_keyboard' => true,
                                'one_time_keyboard' => true
                            ]);
                            setUserState($pdo, $chatId, 'entering_answers', $data);
                        } else {
                            sendMessage($chatId, "Please enter exactly four answers separated by commas.");
                        }
                    }
                    break;

                case 'entering_correct_answer':
                    if (in_array($text, ['1', '2', '3', '4'])) {
                        $data = $userState['data'];
                        $lastIndex = count($data['questions']) - 1;
                        $data['questions'][$lastIndex]['correct'] = intval($text) - 1;
                        sendMessage($chatId, "Correct answer set. Do you want to add another question or finish the test?", [
                            'keyboard' => [
                                [['text' => 'Next Question'], ['text' => 'Finish Test']]
                            ],
                            'resize_keyboard' => true,
                            'one_time_keyboard' => true
                        ]);
                        setUserState($pdo, $chatId, 'confirm_next_or_finish', $data);
                    } else {
                        sendMessage($chatId, "Please indicate the correct answer (1, 2, 3, or 4):");
                    }
                    break;

                case 'confirm_next_or_finish':
                    if ($text === 'Next Question') {
                        sendMessage($chatId, "Please enter the next question:");
                        setUserState($pdo, $chatId, 'entering_question', $userState['data']);
                    } elseif ($text === 'Finish Test') {
                        saveTest($pdo, $userState['data']);
                        clearUserState($pdo, $chatId);
                        sendMessage($chatId, "Test saved successfully!");
                    }
                    break;

                default:
                    if ($text === '/menu') {
                        $keyboard = [
                            'keyboard' => [
                                [['text' => 'Create Test'], ['text' => 'Show Tests']]
                            ],
                            'resize_keyboard' => true,
                            'one_time_keyboard' => true
                        ];
                        sendMessage($chatId, "Please choose an option:\n1. Create Test\n2. Show Tests", $keyboard);
                    } elseif ($text === 'Create Test') {
                        sendMessage($chatId, "Please enter the name of the test:");
                        setUserState($pdo, $chatId, 'creating_test');
                    } elseif ($text === 'Show Tests') {
                        // Implement logic to show tests
                        sendMessage($chatId, "Here are the available tests:");
                        // You need to implement the code to retrieve and display the tests from your database
                    }
                    break;
            }

            $lastUpdateId = $update['update_id'];
            setLastUpdateId($pdo, $lastUpdateId);
        }
    } else {
        error_log("Error fetching updates: " . print_r($updates, true));
    }
    sleep(1); 
}
?>
