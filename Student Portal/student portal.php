<?php
/**
 * api/users.php
 * Admin-panel operations on user accounts.
 *
 *   GET  ?action=list
 *   POST action=add             role, fullname, username, email, extra(dept), pass   -> approved account
 *   POST action=create_admin    fullname, username, pass
 *   POST action=create_teacher  fullname, username, pass, program, year, section
 *   POST action=approve         id
 *   POST action=delete          id
 *   POST action=update_password id, newpass
 */

require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$data   = $method === 'GET' ? $_GET : post_data();
$action = $data['action'] ?? '';

switch ($action) {

    case 'list': {
        $stmt = $pdo->query(
            'SELECT id, fullname, username, email, role, approved,
                    student_id AS studentId,
                    assigned_program AS assignedProgram,
                    assigned_year AS assignedYear,
                    assigned_section AS assignedSection,
                    dept
             FROM users'
        );
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) $r['approved'] = (bool) (int) $r['approved'];
        respond(['success' => true, 'users' => $rows]);
        break;
    }

    case 'add': {
        $role     = $data['role'] ?? '';
        $fullname = trim($data['fullname'] ?? '');
        $username = trim($data['username'] ?? '');
        $email    = trim($data['email'] ?? '');
        $extra    = trim($data['extra'] ?? '');
        $pass     = $data['pass'] ?? '';

        if (!$fullname || !$username || !$email || !$pass) {
            respond(['success' => false, 'message' => 'Fill all fields.']);
        }
        $check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $check->execute([$username, $email]);
        if ($check->fetch()) {
            respond(['success' => false, 'message' => 'Username/email exists.']);
        }

        $uid = 'u' . round(microtime(true) * 1000);
        $ins = $pdo->prepare(
            'INSERT INTO users (id, fullname, username, email, password, role, approved, dept)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $ins->execute([$uid, $fullname, $username, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $extra]);

        respond(['success' => true, 'message' => ucfirst($role) . ' account created!']);
        break;
    }

    case 'create_admin': {
        $username = trim($data['username'] ?? '');
        $pass     = $data['pass'] ?? '';
        $fullname = trim($data['fullname'] ?? '');

        if (!$username || !$pass || !$fullname) {
            respond(['success' => false, 'message' => 'All fields are required.']);
        }
        $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$username]);
        if ($check->fetch()) {
            respond(['success' => false, 'message' => 'Username already exists!']);
        }

        $uid = 'admin_' . round(microtime(true) * 1000);
        $ins = $pdo->prepare(
            'INSERT INTO users (id, fullname, username, email, password, role, approved)
             VALUES (?, ?, ?, ?, ?, "admin", 1)'
        );
        $ins->execute([$uid, $fullname, $username, $username . '@dlsjbc.edu', password_hash($pass, PASSWORD_DEFAULT)]);

        respond(['success' => true, 'message' => 'Admin account created! Login with username: ' . $username]);
        break;
    }

    case 'create_teacher': {
        $fullname = trim($data['fullname'] ?? '');
        $username = trim($data['username'] ?? '');
        $pass     = $data['pass'] ?? '';
        $program  = strtoupper(trim($data['program'] ?? ''));
        $year     = trim($data['year'] ?? '');
        $section  = strtoupper(trim($data['section'] ?? ''));

        if (!$fullname || !$username || !$pass || !$program || !$year || !$section) {
            respond(['success' => false, 'message' => 'All fields are required.']);
        }
        $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$username]);
        if ($check->fetch()) {
            respond(['success' => false, 'message' => 'Username already exists!']);
        }

        $uid = 'teacher_' . round(microtime(true) * 1000);
        $ins = $pdo->prepare(
            'INSERT INTO users (id, fullname, username, email, password, role, approved, assigned_program, assigned_year, assigned_section)
             VALUES (?, ?, ?, ?, ?, "teacher", 1, ?, ?, ?)'
        );
        $ins->execute([$uid, $fullname, $username, $username . '@dlsjbc.edu', password_hash($pass, PASSWORD_DEFAULT), $program, $year, $section]);

        $matchStmt = $pdo->prepare(
            "SELECT COUNT(*) AS c FROM students
             WHERE program = ? AND year = ?
               AND (? = 'ALL' OR section = ?
                    OR (? = 'ABC' AND section IN ('A','B','C'))
                    OR (? = 'AB'  AND section IN ('A','B'))
                    OR (? = 'AC'  AND section IN ('A','C'))
                    OR (? = 'BC'  AND section IN ('B','C')))"
        );
        $matchStmt->execute([$program, $year, $section, $section, $section, $section, $section, $section]);
        $studentCount = (int) $matchStmt->fetch()['c'];

        respond(['success' => true, 'message' => "Teacher account created! They can now login.", 'studentCount' => $studentCount]);
        break;
    }

    case 'approve': {
        $id = trim($data['id'] ?? '');
        $upd = $pdo->prepare('UPDATE users SET approved = 1 WHERE id = ?');
        $upd->execute([$id]);
        respond(['success' => true]);
        break;
    }

    case 'delete': {
        $id = trim($data['id'] ?? '');
        $del = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $del->execute([$id]);
        respond(['success' => true]);
        break;
    }

    case 'update_password': {
        $id  = trim($data['id'] ?? '');
        $new = $data['newpass'] ?? '';
        if (!$new) {
            respond(['success' => false, 'message' => 'New password required.']);
        }
        $upd = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $upd->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
        respond(['success' => true, 'message' => 'Password updated!']);
        break;
    }

    default:
        respond(['success' => false, 'message' => 'Unknown action.']);
}
