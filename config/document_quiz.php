<?php

function extract_document_text(string $path, string $type): string
{
    if ($type === 'notes') {
        $text = file_get_contents($path);
        return is_string($text) ? $text : '';
    }

    if ($type === 'pdf') {
        $command = 'pdftotext -layout ' . escapeshellarg($path) . ' -';
        $text = shell_exec($command);
        if (is_string($text) && trim($text) !== '') {
            return $text;
        }

        $fallback_script = __DIR__ . DIRECTORY_SEPARATOR . 'extract_pdf.py';
        if (is_file($fallback_script)) {
            $fallback = 'py ' . escapeshellarg($fallback_script) . ' ' . escapeshellarg($path) . ' 2>NUL';
            $text = shell_exec($fallback);
            return is_string($text) ? $text : '';
        }

        return '';
    }

    if ($type === 'docx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            return is_string($xml) ? trim(strip_tags(str_replace('</w:p>', "\n", $xml))) : '';
        }
    }

    return '';
}

function build_quiz_from_document(string $text, int $questionCount = 5): array
{
    $sentences = preg_split('/(?<=[.!?])\s+|\R+/', trim(strip_tags($text))) ?: [];
    $sentences = array_values(array_filter(array_map('trim', $sentences), static function ($sentence) {
        return str_word_count($sentence) >= 8;
    }));
    $sentences = array_slice($sentences, 0, $questionCount);
    $questions = [];

    foreach ($sentences as $sentence) {
        $words = preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', '', $sentence)) ?: [];
        $keywords = array_values(array_filter($words, static function ($word) {
            return mb_strlen($word) >= 5;
        }));
        if (!$keywords) {
            continue;
        }

        $answer = $keywords[0];
        $questionText = preg_replace('/\b' . preg_quote($answer, '/') . '\b/i', '________', $sentence, 1);
        $distractors = array_values(array_unique(array_map('strtolower', array_slice($keywords, 1, 3))));
        while (count($distractors) < 3) {
            $distractors[] = 'Not mentioned in the document';
        }
        $options = array_merge([$answer], array_slice($distractors, 0, 3));
        shuffle($options);

        $questions[] = [
            'text' => 'According to the document, complete this statement: ' . $questionText,
            'options' => $options,
            'correct' => array_search($answer, $options, true),
        ];
    }

    return $questions;
}