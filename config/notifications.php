<?php

function notify_enrolled_students(PDO $pdo, int $courseId, string $type, string $title, string $message): void
{
    $students = $pdo->prepare("SELECT student_id FROM enrollments WHERE course_id = ? AND status = 'approved'");
    $students->execute([$courseId]);
    $insert = $pdo->prepare('INSERT INTO notifications (student_id, course_id, type, title, message) VALUES (?, ?, ?, ?, ?)');
    foreach ($students->fetchAll(PDO::FETCH_COLUMN) as $studentId) {
        $insert->execute([(int)$studentId, $courseId, $type, $title, $message]);
    }
}