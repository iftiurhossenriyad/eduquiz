CREATE DATABASE IF NOT EXISTS eduquiz_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE eduquiz_db;

CREATE TABLE users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM(
        'student',
        'instructor',
        'admin'
    ) NOT NULL DEFAULT 'student',
    status ENUM(
        'pending',
        'approved',
        'rejected'
    ) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB;

CREATE TABLE courses (
    course_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    instructor_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    status ENUM(
        'pending',
        'approved',
        'rejected'
    ) NOT NULL DEFAULT 'pending',
    visibility ENUM(
        'public',
        'enrolled',
        'hidden'
    ) NOT NULL DEFAULT 'public',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_courses_instructor FOREIGN KEY (instructor_id) REFERENCES users (user_id) ON DELETE CASCADE,
    INDEX idx_courses_instructor (instructor_id),
    INDEX idx_courses_status (status)
) ENGINE = InnoDB;

CREATE TABLE quizzes (
    quiz_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    source_material_id INT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL,
    total_marks SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_quizzes_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
    INDEX idx_quizzes_course (course_id)
) ENGINE = InnoDB;

CREATE TABLE questions (
    question_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT UNSIGNED NOT NULL,
    question_text TEXT NOT NULL,
    CONSTRAINT fk_questions_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes (quiz_id) ON DELETE CASCADE,
    INDEX idx_questions_quiz (quiz_id)
) ENGINE = InnoDB;

CREATE TABLE options (
    option_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id INT UNSIGNED NOT NULL,
    option_text VARCHAR(500) NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_options_question FOREIGN KEY (question_id) REFERENCES questions (question_id) ON DELETE CASCADE,
    INDEX idx_options_question (question_id)
) ENGINE = InnoDB;

CREATE TABLE enrollments (
    enrollment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    course_id INT UNSIGNED NOT NULL,
    status ENUM(
        'pending',
        'approved',
        'rejected'
    ) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_enrollments_student FOREIGN KEY (student_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_enrollments_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
    CONSTRAINT uq_enrollment_student_course UNIQUE (student_id, course_id),
    INDEX idx_enrollments_course_status (course_id, status)
) ENGINE = InnoDB;

CREATE TABLE results (
    result_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    quiz_id INT UNSIGNED NOT NULL,
    score SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_questions SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    elapsed_seconds INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_results_student FOREIGN KEY (student_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_results_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes (quiz_id) ON DELETE CASCADE,
    INDEX idx_results_student_quiz (student_id, quiz_id),
    INDEX idx_results_quiz (quiz_id)
) ENGINE = InnoDB;

CREATE TABLE materials (
    material_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    type ENUM(
        'video',
        'pdf',
        'notes',
        'docx'
    ) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_materials_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
    INDEX idx_materials_course (course_id)
) ENGINE = InnoDB;

ALTER TABLE quizzes
ADD CONSTRAINT fk_quizzes_source_material FOREIGN KEY (source_material_id) REFERENCES materials (material_id) ON DELETE SET NULL;