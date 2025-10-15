<?php
// File: notification/notification.php
include '../config/config.php';
include '../sistem/sistem.php';
include '../partial/partial.php';
check_login();

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notifications = $stmt->get_result();

// Tandai notifikasi sebagai sudah dibaca
$conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id AND is_read = 0");

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifikasi - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50">
    <?php navbar($conn); ?>
    <main class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-8">Notifikasi</h1>
        <div class="bg-white rounded-lg shadow-md">
            <ul class="divide-y divide-gray-200">
                <?php if($notifications->num_rows == 0): ?>
                    <li class="p-6 text-center text-gray-500">Tidak ada notifikasi.</li>
                <?php else: ?>
                    <?php while($notif = $notifications->fetch_assoc()): ?>
                        <li class="p-4 sm:p-6 hover:bg-gray-50">
                            <div class="flex items-start space-x-4">
                                <div class="flex-shrink-0">
                                    <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center">
                                        <svg class="h-6 w-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                                    </div>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm text-gray-800"><?= htmlspecialchars($notif['message']) ?></p>
                                    <p class="text-xs text-gray-500 mt-1"><?= date('d M Y, H:i', strtotime($notif['created_at'])) ?></p>
                                </div>
                            </div>
                        </li>
                    <?php endwhile; ?>
                <?php endif; ?>
            </ul>
        </div>
    </main>
    <?php footer(); ?>
</body>
</html>