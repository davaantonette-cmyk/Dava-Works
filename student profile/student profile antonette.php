<?php
/**
 * api/students.php
 * Handles: list, add, update_grade, update_status, delete.
 *
 *   GET  ?action=list
 *   POST action=add          id, name, program, year, section, status, math, ethics, pe, oop, platform, reed
 *   POST action=update_grade id, key, value
 *   POST action=update_status id, status
 *   POST action=delete       id   (also removes the linked user login account)
 */

require_once __DIR__ . '/../config.php';

$allowedGradeKeys = ['math', 'ethics', 'pe', 'oop', 'platform', 'reed'];

$method = $_SERVER['REQUEST_METHOD'];
$data   = $method === 'GET' ? $_GET : post_data();
$action = $data['action'] ?? '';

switch ($action) {

    case 'list': {
        $stmt = $pdo->query('SELECT * FROM students');
        $rows = $stmt->fetchAll();

        // Reshape into { id, name, ..., grades: { math, ethics, ... } } to match the front end.
        $out = array_map(function ($r) use ($allowedGradeKeys) {
            $grades = [];
            foreach ($allowedGradeKeys as $k) $grades[$k] = (float) $r[$k];
            return [
                'id'      => $r['id'],
                'name'    => $r['name'],
                'email'   => $r['email'],
                'program' => $r['program'],
                'year'    => $r['year'],
                'section' => $r['section'],
                'status'  => $r['status'],
                'grades'  => $grades,
            ];
        }, $rows);

        respond(['success' => true, 'students' => $out]);
        break;
    }

    case 'add': {
        $id      = trim($data['id'] ?? '');
        $name    = trim($data['name'] ?? '');
        $program = $data['program'] ?? '';
        $year    = $data['year'] ?? '';
        $section = $data['section'] ?? '';
        $status  = ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if (!$id || !$name || !$program || !$year || !$section) {
            respond(['success' => false, 'message' => 'Please fill all required fields.']);
        }

        $check = $pdo->prepare('SELECT id FROM students WHERE id = ?');
        $check->execute([$id]);
        if ($check->fetch()) {
            respond(['success' => false, 'message' => 'Student ID already exists. Please use a different ID.']);
        }

        $grades = [];
        foreach ($allowedGradeKeys as $k) {
            $v = isset($data[$k]) ? (float) $data[$k] : 1.5;
            $grades[$k] = max(1.0, min(5.0, $v));
        }

        $email = strtolower(str_replace(' ', '.', $name)) . '@dlsjbc.edu';

        $ins = $pdo->prepare(
            'INSERT INTO students (id, name, email, program, year, section, status, math, ethics, pe, oop, platform, reed)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $id, $name, $email, $program, $year, $section, $status,
            $grades['math'], $grades['ethics'], $grades['pe'], $grades['oop'], $grades['platform'], $grades['reed'],
        ]);

        // Count matching teachers for the confirmation message.
        $matchStmt = $pdo->prepare(
            "SELECT COUNT(*) AS c FROM users
             WHERE role = 'teacher' AND assigned_program = ? AND assigned_year = ?
               AND (assigned_section = 'ALL' OR assigned_section = ?
                    OR (assigned_section = 'ABC' AND ? IN ('A','B','C'))
                    OR (assigned_section = 'AB'  AND ? IN ('A','B'))
                    OR (assigned_section = 'AC'  AND ? IN ('A','C'))
                    OR (assigned_section = 'BC'  AND ? IN ('B','C')))"
        );
        $matchStmt->execute([$program, $year, $section, $section, $section, $section, $section]);
        $teacherCount = (int) $matchStmt->fetch()['c'];

        respond(['success' => true, 'message' => 'Student saved!', 'teacherCount' => $teacherCount]);
        break;
    }

    case 'update_grade': {
        $id  = trim($data['id'] ?? '');
        $key = $data['key'] ?? '';
        $val = (float) ($data['value'] ?? 0);

        if (!in_array($key, $allowedGradeKeys, true)) {
            respond(['success' => false, 'message' => 'Invalid subject.']);
        }
        $val = max(1.0, min(5.0, $val));

        // $key is whitelisted above, safe to interpolate into column name.
        $upd = $pdo->prepare("UPDATE students SET `$key` = ? WHERE id = ?");
        $upd->execute([$val, $id]);

        respond(['success' => true]);
        break;
    }

    case 'update_status': {
        $id = trim($data['id'] ?? '');
        $status = ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        $upd = $pdo->prepare('UPDATE students SET status = ? WHERE id = ?');
        $upd->execute([$status, $id]);

        respond(['success' => true]);
        break;
    }

    case 'delete': {
        $id = trim($data['id'] ?? '');

        $pdo->beginTransaction();
        try {
            // Remove the linked login account first (if any), then the student record.
            $delUser = $pdo->prepare("DELETE FROM users WHERE role = 'student' AND student_id = ?");
            $delUser->execute([$id]);

            $delStudent = $pdo->prepare('DELETE FROM students WHERE id = ?');
            $delStudent->execute([$id]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            respond(['success' => false, 'message' => 'Could not delete: ' . $e->getMessage()]);
        }

        respond(['success' => true, 'message' => 'Student and associated user account deleted successfully!']);
        break;
    }

    default:
        respond(['success' => false, 'message' => 'Unknown action.']);
}
