ALTER TABLE courses
ADD COLUMN visibility ENUM(
    'public',
    'enrolled',
    'hidden'
) NOT NULL DEFAULT 'public' AFTER status;

ALTER TABLE quizzes
ADD COLUMN source_material_id INT UNSIGNED NULL AFTER course_id;

ALTER TABLE materials
MODIFY COLUMN type ENUM(
    'video',
    'pdf',
    'notes',
    'docx'
) NOT NULL;

ALTER TABLE quizzes
ADD CONSTRAINT fk_quizzes_source_material FOREIGN KEY (source_material_id) REFERENCES materials (material_id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS assignments (
    assignment_id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    title VARCHAR(180) NOT NULL,
    instructions TEXT NOT NULL,
    due_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_assignments_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
    INDEX idx_assignments_course (course_id)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS assignment_submissions (
    submission_id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    student_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    feedback TEXT NULL,
    grade DECIMAL(5, 2) NULL,
    CONSTRAINT fk_submissions_assignment FOREIGN KEY (assignment_id) REFERENCES assignments (assignment_id) ON DELETE CASCADE,
    CONSTRAINT fk_submissions_student FOREIGN KEY (student_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT uq_submission_assignment_student UNIQUE (assignment_id, student_id),
    INDEX idx_submissions_assignment (assignment_id)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id INT NOT NULL,
    type VARCHAR(30) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message VARCHAR(500) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_student FOREIGN KEY (student_id) REFERENCES users (user_id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_course FOREIGN KEY (course_id) REFERENCES courses (course_id) ON DELETE CASCADE,
    INDEX idx_notifications_student_read (
        student_id,
        is_read,
        created_at
    )
) ENGINE = InnoDB;