<?php

$pageTitle = 'Register';

if (is_logged_in()) {
    redirect('index.php?page=dashboard');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    try {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters long.');
        }

        if ($password !== $confirmPassword) {
            throw new RuntimeException('Password confirmation does not match.');
        }

        $existsStmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $existsStmt->execute(['email' => $email]);

        if ($existsStmt->fetch()) {
            throw new RuntimeException('An account with this email already exists.');
        }

        $role = has_registered_users() ? 'teacher' : 'admin';
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $insertStmt = db()->prepare(
            'INSERT INTO users (email, password, role) VALUES (:email, :password, :role)'
        );
        $insertStmt->execute([
            'email' => $email,
            'password' => $passwordHash,
            'role' => $role,
        ]);

        flash('success', $role === 'admin'
            ? 'Admin account created successfully. Please log in.'
            : 'Account created successfully. Please log in.'
        );
        redirect('index.php?page=login');
    } catch (Throwable $exception) {
        flash('error', 'Registration failed: ' . $exception->getMessage());
        redirect('index.php?page=register');
    }
}
?>
<p class="muted">
    <?= has_registered_users()
        ? 'Create a new user account to access the system.'
        : 'Create the first account to initialize the system. The first registered user becomes the admin.' ?>
</p>
<form method="post">
    <label>
        Email
        <input type="email" name="email" placeholder="you@example.com" required>
    </label>
    <label>
        Password
        <input type="password" name="password" placeholder="At least 8 characters" minlength="8" required>
    </label>
    <label>
        Confirm Password
        <input type="password" name="confirm_password" placeholder="Repeat the password" minlength="8" required>
    </label>
    <button type="submit">Create Account</button>
</form>
<p class="muted">Already registered? <a href="index.php?page=login">Go to login</a>.</p>
