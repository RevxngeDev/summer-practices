<?php

class Database {
    private $pdo;

    public function __construct($host, $port, $dbname, $user, $pass) {
        try {
            $this->pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $pass);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("Error connecting to the database: " . $e->getMessage());
        }
    }

    public function setUserState($chatId, $state, $data = null) {
        $stmt = $this->pdo->prepare("INSERT INTO user_state (chat_id, state, data) VALUES (:chat_id, :state, :data)
                                     ON CONFLICT (chat_id) DO UPDATE SET state = EXCLUDED.state, data = EXCLUDED.data");
        $stmt->execute([
            'chat_id' => $chatId,
            'state' => $state,
            'data' => json_encode($data)
        ]);
    }

    public function getUserState($chatId) {
        $stmt = $this->pdo->prepare("SELECT state, data FROM user_state WHERE chat_id = :chat_id");
        $stmt->execute(['chat_id' => $chatId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? ['state' => $result['state'], 'data' => json_decode($result['data'], true)] : ['state' => null, 'data' => null];
    }

    public function clearUserState($chatId) {
        $stmt = $this->pdo->prepare("DELETE FROM user_state WHERE chat_id = :chat_id");
        $stmt->execute(['chat_id' => $chatId]);
    }

    public function getTestQuestions($testId) {
        $stmt = $this->pdo->prepare("SELECT question_id, question_text, correct_answer FROM questions WHERE test_id = :test_id ORDER BY question_id");
        $stmt->execute(['test_id' => $testId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getQuestionAnswers($questionId) {
        $stmt = $this->pdo->prepare("SELECT answer_id, answer_text FROM answers WHERE question_id = :question_id ORDER BY answer_id");
        $stmt->execute(['question_id' => $questionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveTest($data) {
        $stmt = $this->pdo->prepare("INSERT INTO tests (test_name) VALUES (:test_name) RETURNING test_id");
        $stmt->execute(['test_name' => $data['test_name']]);
        $testId = $stmt->fetch(PDO::FETCH_ASSOC)['test_id'];

        foreach ($data['questions'] as $question) {
            $stmt = $this->pdo->prepare("INSERT INTO questions (test_id, question_text, correct_answer) VALUES (:test_id, :question_text, :correct_answer) RETURNING question_id");
            $stmt->execute([
                'test_id' => $testId,
                'question_text' => $question['question'],
                'correct_answer' => $question['correct']
            ]);
            $questionId = $stmt->fetch(PDO::FETCH_ASSOC)['question_id'];

            foreach ($question['answers'] as $answer) {
                $stmt = $this->pdo->prepare("INSERT INTO answers (question_id, answer_text) VALUES (:question_id, :answer_text)");
                $stmt->execute(['question_id' => $questionId, 'answer_text' => $answer]);
            }
        }
    }

    public function getTests() {
        $stmt = $this->pdo->query("SELECT test_id, test_name FROM tests ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteTest($testId) {
        $stmt = $this->pdo->prepare("DELETE FROM tests WHERE test_id = :test_id");
        $stmt->execute(['test_id' => $testId]);
    }

    public function setLastUpdateId($updateId) {
        $stmt = $this->pdo->prepare("INSERT INTO last_update_id (update_id) VALUES (:update_id) ON CONFLICT (update_id) DO NOTHING");
        $stmt->execute(['update_id' => $updateId]);
    }

    public function getLastUpdateId() {
        $stmt = $this->pdo->query("SELECT update_id FROM last_update_id ORDER BY update_id DESC LIMIT 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['update_id'] : 0;
    }
}
