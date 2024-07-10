<?php

class BotHandler {
    private $telegramBot;
    private $database;

    public function __construct($telegramBot, $database) {
        $this->telegramBot = $telegramBot;
        $this->database = $database;
    }

    public function handleUpdate($update) {
        if (isset($update['callback_query'])) {
            $callbackQuery = $update['callback_query'];
            $callbackQueryId = $callbackQuery['id'];
            $chatId = $callbackQuery['message']['chat']['id'];
            $data = $callbackQuery['data'];

            if (strpos($data, 'take_test_') === 0) {
                $testId = str_replace('take_test_', '', $data);
                $questions = $this->database->getTestQuestions($testId);
                foreach ($questions as &$question) {
                    $question['answers'] = $this->database->getQuestionAnswers($question['question_id']);
                }
                unset($question);

                $this->database->setUserState($chatId, 'taking_test', ['current_question' => 0, 'questions' => $questions, 'responses' => []]);
                $question = $questions[0];
                $this->sendQuestion($chatId, $question, 0);
            }

            if (strpos($data, 'answer_') === 0) {
                $answerIndex = str_replace('answer_', '', $data);
                $userState = $this->database->getUserState($chatId);
                $userResponses = $userState['data']['responses'];
                $currentQuestionIndex = $userState['data']['current_question'];

                // Save user response
                $userResponses[$currentQuestionIndex] = intval($answerIndex);
                $this->database->setUserState($chatId, 'taking_test', ['current_question' => $currentQuestionIndex + 1, 'questions' => $userState['data']['questions'], 'responses' => $userResponses]);

                // Check if there are more questions
                if ($currentQuestionIndex + 1 < count($userState['data']['questions'])) {
                    $nextQuestion = $userState['data']['questions'][$currentQuestionIndex + 1];
                    $this->sendQuestion($chatId, $nextQuestion, $currentQuestionIndex + 1);
                } else {
                    // Test completion
                    $correctAnswers = 0;
                    foreach ($userResponses as $index => $response) {
                        if ($response === $userState['data']['questions'][$index]['correct_answer']) {
                            $correctAnswers++;
                        }
                    }
                    $totalQuestions = count($userState['data']['questions']);
                    $this->telegramBot->sendMessage($chatId, "Test completed! You got $correctAnswers out of $totalQuestions correct.");
                    $this->database->clearUserState($chatId);
                }

                // Answer callback query
                $this->telegramBot->answerCallbackQuery($callbackQueryId);
            }

            if (strpos($data, 'delete_test_') === 0) {
                $testId = str_replace('delete_test_', '', $data);
                $this->database->deleteTest($testId);
                $this->telegramBot->sendMessage($chatId, "Test deleted successfully.");
            }
        } else {
            $chatId = $update['message']['chat']['id'];
            $text = $update['message']['text'] ?? '';

            $userState = $this->database->getUserState($chatId);

            // Handle different states of interaction
            switch ($userState['state']) {
                case 'creating_test':
                    if ($text === 'Confirm Name') {
                        $this->telegramBot->sendMessage($chatId, "Please enter the first question:");
                        $this->database->setUserState($chatId, 'entering_question', $userState['data']);
                    } else {
                        $data = $userState['data'] ?? [];
                        $data['test_name'] = $text;
                        $this->telegramBot->sendMessage($chatId, "Test name set to \"$text\". Click \"Confirm Name\" to proceed.", [
                            'keyboard' => [[['text' => 'Confirm Name']]],
                            'resize_keyboard' => true,
                            'one_time_keyboard' => true
                        ]);
                        $this->database->setUserState($chatId, 'creating_test', $data);
                    }
                    break;

                case 'entering_question':
                    if ($text === 'Confirm Question') {
                        $this->telegramBot->sendMessage($chatId, "Please enter the four answer options separated by commas:");
                        $this->database->setUserState($chatId, 'entering_answers', $userState['data']);
                    } else {
                        $data = $userState['data'] ?? [];
                        $data['questions'][] = ['question' => $text, 'answers' => []];
                        $this->telegramBot->sendMessage($chatId, "Question set to \"$text\". Click \"Confirm Question\" to proceed.", [
                            'keyboard' => [[['text' => 'Confirm Question']]],
                            'resize_keyboard' => true,
                            'one_time_keyboard' => true
                        ]);
                        $this->database->setUserState($chatId, 'entering_question', $data);
                    }
                    break;

                case 'entering_answers':
                    if ($text === 'Confirm Answers') {
                        $this->telegramBot->sendMessage($chatId, "Please indicate the correct answer (1, 2, 3, or 4):");
                        $this->database->setUserState($chatId, 'entering_correct_answer', $userState['data']);
                    } else {
                        $answers = explode(',', $text);
                        if (count($answers) === 4) {
                            $data = $userState['data'];
                            $lastIndex = count($data['questions']) - 1;
                            $data['questions'][$lastIndex]['answers'] = array_map('trim', $answers);
                            $this->telegramBot->sendMessage($chatId, "Answers set. Click \"Confirm Answers\" to proceed.", [
                                'keyboard' => [[['text' => 'Confirm Answers']]],
                                'resize_keyboard' => true,
                                'one_time_keyboard' => true
                            ]);
                            $this->database->setUserState($chatId, 'entering_answers', $data);
                        } else {
                            $this->telegramBot->sendMessage($chatId, "Please enter exactly four answers separated by commas.");
                        }
                    }
                    break;

                case 'entering_correct_answer':
                    if (in_array($text, ['1', '2', '3', '4'])) {
                        $data = $userState['data'];
                        $lastIndex = count($data['questions']) - 1;
                        $data['questions'][$lastIndex]['correct'] = intval($text) - 1;
                        $this->telegramBot->sendMessage($chatId, "Correct answer set. Do you want to add another question or finish the test?", [
                            'keyboard' => [
                                [['text' => 'Next Question'], ['text' => 'Finish Test']]
                            ],
                            'resize_keyboard' => true,
                            'one_time_keyboard' => true
                        ]);
                        $this->database->setUserState($chatId, 'confirm_next_or_finish', $data);
                    } else {
                        $this->telegramBot->sendMessage($chatId, "Please indicate the correct answer (1, 2, 3, or 4):");
                    }
                    break;

                case 'confirm_next_or_finish':
                    if ($text === 'Next Question') {
                        $this->telegramBot->sendMessage($chatId, "Please enter the next question:");
                        $this->database->setUserState($chatId, 'entering_question', $userState['data']);
                    } elseif ($text === 'Finish Test') {
                        // Save test to database
                        $this->database->saveTest($userState['data']);
                        $this->database->clearUserState($chatId);
                        $this->telegramBot->sendMessage($chatId, "Test saved successfully!");
                        $this->telegramBot->sendMessage($chatId, "Returning to menu...");
                        $keyboard = [
                            'keyboard' => [
                                [['text' => 'Create Test'], ['text' => 'Show Tests'], ['text' => 'Delete Test']]
                            ],
                            'resize_keyboard' => true,
                            'one_time_keyboard' => true
                        ];
                        $this->telegramBot->sendMessage($chatId, "Please choose an option:\n1. Create Test\n2. Show Tests\n3. Delete Test", $keyboard);
                    }
                    break;

                default:
                    if ($text === '/start') {
                        $this->telegramBot->sendMessage($chatId, "I'm ready to work, use /menu :)");
                    } elseif ($text === '/menu') {
                        $keyboard = [
                            'keyboard' => [
                                [['text' => 'Create Test'], ['text' => 'Show Tests'], ['text' => 'Delete Test']]
                            ],
                            'resize_keyboard' => true,
                            'one_time_keyboard' => true
                        ];
                        $this->telegramBot->sendMessage($chatId, "Please choose an option:\n1. Create Test\n2. Show Tests\n3. Delete Test", $keyboard);
                    } elseif ($text === 'Create Test') {
                        $this->telegramBot->sendMessage($chatId, "Please enter the name of the test:");
                        $this->database->setUserState($chatId, 'creating_test');
                    } elseif ($text === 'Show Tests') {
                        $tests = $this->database->getTests();
                        if (!empty($tests)) {
                            $keyboard = ['inline_keyboard' => []];
                            foreach ($tests as $test) {
                                $keyboard['inline_keyboard'][] = [['text' => $test['test_name'], 'callback_data' => 'take_test_' . $test['test_id']]];
                            }
                            $this->telegramBot->sendMessage($chatId, "Here are the available tests:", $keyboard);
                        } else {
                            $this->telegramBot->sendMessage($chatId, "No tests available.");
                        }
                    } elseif ($text === 'Delete Test') {
                        $tests = $this->database->getTests();
                        if (!empty($tests)) {
                            $keyboard = ['inline_keyboard' => []];
                            foreach ($tests as $test) {
                                $keyboard['inline_keyboard'][] = [['text' => $test['test_name'], 'callback_data' => 'delete_test_' . $test['test_id']]];
                            }
                            $this->telegramBot->sendMessage($chatId, "Select a test to delete:", $keyboard);
                        } else {
                            $this->telegramBot->sendMessage($chatId, "No tests available to delete.");
                        }
                    }
                    break;
            }

            $this->database->setLastUpdateId($update['update_id']);
        }
    }

    private function sendQuestion($chatId, $question, $questionIndex) {
        $answers = $question['answers'];
        $keyboard = ['inline_keyboard' => []];
        foreach ($answers as $index => $answer) {
            $keyboard['inline_keyboard'][] = [['text' => $answer['answer_text'], 'callback_data' => 'answer_' . $index]];
        }
        $message = "Question " . ($questionIndex + 1) . ": " . $question['question_text'];
        $this->telegramBot->sendMessage($chatId, $message, $keyboard);
    }
}
