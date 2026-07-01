<?php

require_role(['admin']);

$pageTitle = 'Admin Access Control';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newAdminId = filter_var($_POST['new_admin_id'] ?? '', FILTER_VALIDATE_INT);

    if ($newAdminId === false || $newAdminId <= 0) {
        flash('error', 'Please select a valid user.');
        redirect('index.php?page=admin');
    }

    try {
        $stmt = db()->prepare('SELECT id, email, role FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $newAdminId]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new RuntimeException('Selected user does not exist.');
        }

        if ($user['role'] === 'admin') {
            flash('warning', 'The selected user is already an administrator.');
            redirect('index.php?page=admin');
        }

        db()->prepare('UPDATE users SET role = :role WHERE id = :id')
            ->execute(['role' => 'admin', 'id' => $newAdminId]);

        flash('success', 'Admin access successfully granted to ' . e($user['email']) . '.');
        redirect('index.php?page=admin');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        flash('error', 'Could not transfer admin access: ' . $exception->getMessage());
        redirect('index.php?page=admin');
    }
}

$users = db()->query('SELECT id, email, role FROM users ORDER BY email ASC')->fetchAll();
$currentAdminId = current_user()['id'];
?>
<section class="chart-box">
    <h2>Admin Access Control</h2>
    <p class="muted">Use this page to transfer admin access to another user. Only administrators can see and use this page.</p>

    <form method="post" style="max-width:500px;">
        <label>
            Select teacher to promote to admin
            <select name="new_admin_id" required>
                <option value="">Choose user</option>
                <?php foreach ($users as $user): ?>
                    <?php if ($user['role'] !== 'teacher'): continue; endif; ?>
                    <option value="<?= e((string) $user['id']) ?>">
                        <?= e($user['email']) ?> (<?= e($user['role']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Grant Admin Access</button>
    </form>

    <div style="margin-top:24px;">
        <h3>Current users</h3>
        <table class="table">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Role</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= e($user['email']) ?></td>
                        <td><?= e($user['role']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
