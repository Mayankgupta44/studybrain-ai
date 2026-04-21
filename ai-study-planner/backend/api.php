<?php
// backend/api.php
require_once 'database.php';

// Load from .env file if it exists
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    $env_vars = parse_ini_file($env_file);
    $gemini_api_key = $env_vars['GEMINI_API_KEY'] ?? '';
} else {
    $gemini_api_key = getenv('GEMINI_API_KEY') ?: '';
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Helper to get JSON body
$requestData = json_decode(file_get_contents('php://input'), true) ?? [];

if ($method === 'GET' && $action === 'topics') {
    // Fetch Topics
    $stmt = $pdo->query("SELECT * FROM topics");
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === 'GET' && $action === 'analytics') {
    $user_id = 1;
    
    // Total questions attempted and correct
    $stmt = $pdo->prepare("SELECT SUM(questions_attempted) as total_attempted, SUM(questions_correct) as total_correct FROM user_progress WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $totals = $stmt->fetch();
    
    $total_attempted = $totals['total_attempted'] ?? 0;
    $total_correct = $totals['total_correct'] ?? 0;
    $overall_accuracy = $total_attempted > 0 ? ($total_correct / $total_attempted) * 100 : 0;
    
    // Total time spent from test_sessions
    $stmt = $pdo->prepare("SELECT SUM(time_taken_seconds) as total_time FROM test_sessions WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $time_row = $stmt->fetch();
    $total_time_seconds = $time_row['total_time'] ?? 0;

    // Weakest topic
    $stmt = $pdo->prepare("
        SELECT t.name as topic_name, t.id as topic_id, (CAST(up.questions_correct AS FLOAT) / up.questions_attempted * 100) as accuracy
        FROM user_progress up
        JOIN topics t ON up.topic_id = t.id
        WHERE up.user_id = ? AND up.questions_attempted > 0
        ORDER BY accuracy ASC
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $weakest_topic = $stmt->fetch();

    echo json_encode([
        'total_attempted' => $total_attempted,
        'overall_accuracy' => round($overall_accuracy),
        'total_time_seconds' => $total_time_seconds,
        'weakest_topic' => $weakest_topic ?: null
    ]);
    exit;
}

if ($method === 'GET' && $action === 'progress') {
    // Fetch Progress (Mocking user_id = 1 for now)
    $user_id = 1;
    $stmt = $pdo->prepare("
        SELECT t.name as topic_name, up.questions_attempted, up.questions_correct, 
               (CAST(up.questions_correct AS FLOAT) / up.questions_attempted * 100) as accuracy
        FROM user_progress up
        JOIN topics t ON up.topic_id = t.id
        WHERE up.user_id = ?
    ");
    $stmt->execute([$user_id]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === 'POST' && $action === 'submit_progress') {
    // Update progress
    $user_id = 1;
    $topic_id = $requestData['topic_id'] ?? null;
    $correct = $requestData['correct'] ?? 0;
    $total = $requestData['total'] ?? 0;

    if ($topic_id && $total > 0) {
        // Check if progress exists
        $stmt = $pdo->prepare("SELECT id FROM user_progress WHERE user_id = ? AND topic_id = ?");
        $stmt->execute([$user_id, $topic_id]);
        $row = $stmt->fetch();

        if ($row) {
            $pdo->prepare("UPDATE user_progress SET questions_attempted = questions_attempted + ?, questions_correct = questions_correct + ?, last_attempted_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$total, $correct, $row['id']]);
        } else {
            $pdo->prepare("INSERT INTO user_progress (user_id, topic_id, questions_attempted, questions_correct) VALUES (?, ?, ?, ?)")
                ->execute([$user_id, $topic_id, $total, $correct]);
        }
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Invalid data']);
    }
    exit;
}

if ($method === 'POST' && $action === 'submit_session') {
    $user_id = 1; // mock user
    $topic_id = $requestData['topic_id'] ?? null;
    $mode = $requestData['mode'] ?? 'practice';
    $difficulty = $requestData['difficulty'] ?? 'medium';
    $score = $requestData['score'] ?? 0;
    $total_questions = $requestData['total_questions'] ?? 0;
    $time_taken_seconds = $requestData['time_taken_seconds'] ?? 0;
    $answers = $requestData['answers'] ?? [];

    if ($total_questions > 0) {
        $pdo->beginTransaction();
        try {
            // Insert into test_sessions
            $stmt = $pdo->prepare("INSERT INTO test_sessions (user_id, topic_id, mode, difficulty, score, total_questions, time_taken_seconds) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $topic_id, $mode, $difficulty, $score, $total_questions, $time_taken_seconds]);
            $session_id = $pdo->lastInsertId();

            // Insert user answers
            $ans_stmt = $pdo->prepare("INSERT INTO user_answers (session_id, question, options, correct_answer, user_answer, is_correct, explanation) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($answers as $ans) {
                $ans_stmt->execute([
                    $session_id,
                    $ans['question'] ?? '',
                    json_encode($ans['options'] ?? []),
                    $ans['correct_answer'] ?? '',
                    $ans['user_answer'] ?? '',
                    $ans['is_correct'] ? 1 : 0,
                    $ans['explanation'] ?? ''
                ]);
            }

            // Also update the aggregated user_progress
            if ($topic_id) {
                $prog_stmt = $pdo->prepare("SELECT id FROM user_progress WHERE user_id = ? AND topic_id = ?");
                $prog_stmt->execute([$user_id, $topic_id]);
                $row = $prog_stmt->fetch();

                if ($row) {
                    $pdo->prepare("UPDATE user_progress SET questions_attempted = questions_attempted + ?, questions_correct = questions_correct + ?, last_attempted_at = CURRENT_TIMESTAMP WHERE id = ?")
                        ->execute([$total_questions, $score, $row['id']]);
                } else {
                    $pdo->prepare("INSERT INTO user_progress (user_id, topic_id, questions_attempted, questions_correct) VALUES (?, ?, ?, ?)")
                        ->execute([$user_id, $topic_id, $total_questions, $score]);
                }
            }

            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['error' => 'Invalid data']);
    }
    exit;
}

if ($method === 'POST' && $action === 'generate_questions') {
    // Generate questions using Gemini API
    $topic = $requestData['topic'] ?? 'General Knowledge';
    $difficulty = $requestData['difficulty'] ?? 'medium';
    $count = $requestData['count'] ?? 3;

    $prompt = "Generate $count multiple-choice questions about $topic at $difficulty difficulty. Return ONLY a valid JSON array where each object has the keys: 'question' (string), 'options' (array of 4 strings), 'correct_answer' (string exactly matching one option), and 'explanation' (string explaining the answer). Do not wrap in markdown tags like ```json.";

    $response = callGeminiAPI($prompt, $gemini_api_key);
    
    // Attempt to parse JSON. Sometimes AI wraps in markdown anyway
    if (preg_match('/\[.*\]/s', $response, $matches)) {
        $clean_response = $matches[0];
    } else {
        $clean_response = str_replace(['```json', '```'], '', $response);
    }
    $data = json_decode($clean_response, true);

    if ($data) {
        echo json_encode($data);
    } else {
        echo json_encode(['error' => 'Failed to parse AI response', 'raw' => $response]);
    }
    exit;
}

if ($method === 'POST' && $action === 'generate_plan') {
    // Generate study plan
    $user_id = 1;
    $duration = $requestData['duration'] ?? 3;
    
    // Check if we need to regenerate based on recent activity
    $stmt = $pdo->prepare("SELECT created_at FROM test_sessions WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $last_session = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT created_at, plan_content FROM study_plans WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $last_plan = $stmt->fetch();

    if ($last_plan && (!$last_session || strtotime($last_session['created_at']) < strtotime($last_plan['created_at']))) {
        // Return cached plan if no new tests were taken
        echo json_encode(['plan' => $last_plan['plan_content']]);
        exit;
    }
    
    // Fetch weak areas
    $stmt = $pdo->prepare("
        SELECT t.name as topic_name, (CAST(up.questions_correct AS FLOAT) / up.questions_attempted * 100) as accuracy
        FROM user_progress up
        JOIN topics t ON up.topic_id = t.id
        WHERE up.user_id = ?
        ORDER BY accuracy ASC
        LIMIT 3
    ");
    $stmt->execute([$user_id]);
    $weak_areas = $stmt->fetchAll();
    
    if(count($weak_areas) === 0) {
        echo json_encode(['plan' => 'Not enough data to generate a study plan. Please practice more topics!']);
        exit;
    }

    // Fetch specific recent incorrect questions
    $stmt = $pdo->prepare("
        SELECT ua.question, ua.user_answer, ua.correct_answer
        FROM user_answers ua
        JOIN test_sessions ts ON ua.session_id = ts.id
        WHERE ts.user_id = ? AND ua.is_correct = 0
        ORDER BY ts.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $incorrect = $stmt->fetchAll();
    $incorrect_str = json_encode($incorrect);

    $weak_topics_str = implode(', ', array_map(function($w) { return $w['topic_name'] . ' (' . round($w['accuracy']) . '% accuracy)'; }, $weak_areas));

    $prompt = "You are an expert tutor. The student's weakest topics are: $weak_topics_str. Furthermore, they recently struggled with these specific questions: $incorrect_str. Create a highly personalized $duration-day study plan focusing on these exact conceptual gaps. Return the plan formatted in clean Markdown. Be concise and highly actionable.";

    $response = callGeminiAPI($prompt, $gemini_api_key);
    
    // Check if API returned an error JSON
    $data = json_decode($response, true);
    if (is_array($data) && isset($data['error'])) {
        echo json_encode($data);
        exit;
    }
    
    // Save plan to DB
    $pdo->prepare("INSERT INTO study_plans (user_id, plan_content) VALUES (?, ?)")
        ->execute([$user_id, $response]);

    echo json_encode(['plan' => $response]);
    exit;
}

if ($method === 'GET' && $action === 'get_plan') {
    $user_id = 1;
    $stmt = $pdo->prepare("SELECT plan_content, created_at FROM study_plans WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $plan = $stmt->fetch();
    
    if ($plan) {
        echo json_encode($plan);
    } else {
        echo json_encode(['plan_content' => null]);
    }
    exit;
}

if ($method === 'GET' && $action === 'ai_feedback') {
    $user_id = 1;
    
    // Check if we need to regenerate
    $stmt = $pdo->prepare("SELECT created_at FROM test_sessions WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $last_session = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT created_at, feedback_content FROM ai_feedbacks WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $last_feedback = $stmt->fetch();

    if ($last_feedback && (!$last_session || strtotime($last_session['created_at']) < strtotime($last_feedback['created_at']))) {
        echo json_encode(['feedback' => $last_feedback['feedback_content']]);
        exit;
    }
    
    // Fetch last 10 incorrect answers
    $stmt = $pdo->prepare("
        SELECT ua.question, ua.user_answer, ua.correct_answer, t.name as topic_name
        FROM user_answers ua
        JOIN test_sessions ts ON ua.session_id = ts.id
        JOIN topics t ON ts.topic_id = t.id
        WHERE ts.user_id = ? AND ua.is_correct = 0
        ORDER BY ts.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $incorrect = $stmt->fetchAll();
    
    if (count($incorrect) === 0) {
        echo json_encode(['feedback' => 'You have no recent incorrect answers to analyze. Great job!']);
        exit;
    }
    
    // Fetch topic scores for context
    $stmt = $pdo->prepare("
        SELECT t.name as topic_name, (CAST(up.questions_correct AS FLOAT) / up.questions_attempted * 100) as accuracy
        FROM user_progress up
        JOIN topics t ON up.topic_id = t.id
        WHERE up.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $scores = $stmt->fetchAll();
    
    $scores_str = json_encode($scores);
    $incorrect_str = json_encode($incorrect);
    
    $prompt = "You are an expert tutor. Analyze the following recent incorrect answers from a student: $incorrect_str. Identify their conceptual misunderstandings. Provide output in markdown format with exactly three sections: \n\n### 1. Your Strengths\n(based on their topic scores: $scores_str)\n\n### 2. Your Weaknesses\n(specific concepts they are getting wrong based on the incorrect answers)\n\n### 3. Actionable Improvement Tips";
    
    $response = callGeminiAPI($prompt, $gemini_api_key);
    
    // Check if API returned an error JSON
    $data = json_decode($response, true);
    if (is_array($data) && isset($data['error'])) {
        echo json_encode($data);
        exit;
    }
    
    // Save feedback to DB
    $pdo->prepare("INSERT INTO ai_feedbacks (user_id, feedback_content) VALUES (?, ?)")
        ->execute([$user_id, $response]);
    
    echo json_encode(['feedback' => $response]);
    exit;
}

echo json_encode(['error' => 'Invalid endpoint']);

// Function to call Gemini API
function callGeminiAPI($prompt, $api_key) {
    if ($api_key === 'YOUR_GEMINI_API_KEY') {
        // Return Mock data if API key is not set to prevent errors during Phase 1
        return mockGeminiResponse($prompt);
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key;
    
    $data = [
        "contents" => [
            ["parts" => [["text" => $prompt]]]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return json_encode(["error" => "cURL Error: " . $err]);
    }

    if ($http_code === 429) {
        return json_encode([
            "error" => "Too many requests to the AI service. Please wait a few seconds and try again.",
            "rate_limited" => true
        ]);
    }

    $json = json_decode($response, true);
    if (isset($json['error'])) {
        $errorMessage = $json['error']['message'];
        if (strpos(strtolower($errorMessage), 'quota exceeded') !== false) {
            return json_encode([
                "error" => "Too many requests to the AI service. Please wait a few seconds and try again.",
                "rate_limited" => true
            ]);
        }
        return json_encode(["error" => "Gemini API Error: " . $errorMessage]);
    }

    return $json['candidates'][0]['content']['parts'][0]['text'] ?? json_encode(["error" => "Empty response from API", "raw" => $response]);
}

function mockGeminiResponse($prompt) {
    // Return mock data for questions
    if (strpos($prompt, 'multiple-choice questions') !== false) {
        return json_encode([
            [
                "question" => "What is the capital of France?",
                "options" => ["London", "Paris", "Berlin", "Madrid"],
                "correct_answer" => "Paris",
                "explanation" => "Paris is the capital and most populous city of France."
            ],
            [
                "question" => "Which language is primarily used for web styling?",
                "options" => ["HTML", "Python", "CSS", "C++"],
                "correct_answer" => "CSS",
                "explanation" => "CSS (Cascading Style Sheets) is used for styling web pages."
            ]
        ]);
    }
    // Return mock plan
    return "### Day 1: Fundamentals\n- Review basic concepts of your weak areas.\n- Practice simple problems.\n\n### Day 2: Advanced Topics\n- Dive deep into specific modules.\n- Complete a small project.\n\n### Day 3: Testing\n- Take a mock test to evaluate improvements.";
}
