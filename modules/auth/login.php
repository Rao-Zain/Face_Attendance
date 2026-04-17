<?php

$pageTitle = 'Login';

if (is_logged_in()) {
    redirect('index.php?page=dashboard');
}

if (!has_registered_users()) {
    flash('warning', 'Create the first account before logging in.');
    redirect('index.php?page=register');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare('SELECT id, email, password, role FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];

        flash('success', 'Welcome back.');
        redirect('index.php?page=dashboard');
    }

    flash('error', 'Invalid email or password.');
    redirect('index.php?page=login');
}
?>
<p class="muted">Log in with the account you created during registration.</p>
<form method="post">
    <label>
        Email
        <input type="email" name="email" placeholder="you@example.com" required>
    </label>
    <label>
        Password
        <input type="password" name="password" placeholder="Enter your password" required>
    </label>
    <button type="submit">Log In</button>
</form>

<p class="muted">Need an account? <a href="index.php?page=register">Register here</a>.</p>

