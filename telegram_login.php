<?php
require __DIR__ . '/includes/auth.php';
requireLogin();
$user = currentUser();
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/telegram_worker_client.php';
?>
<div class="pan-page">
  <div class="pan-heading">
    <div>
      <div class="pan-kicker">PAN INDIA SEARCH</div>
      <!-- <h1>Connect Telegram</h1> -->
      <!-- <p>Connect Telegram once on this server. The authorization is reused automatically, so the dashboard will not ask you to verify on every visit.</p> -->
    </div>
  </div>
  <div class="pan-search-card" style="padding:22px">
    <?php try {
        $status = telegramWorkerRequest(['action' => 'status'], 3);
        if (!empty($status['ok'])) {
            echo '<div class="pan-result-state" style="min-height:60px">Telegram is connected. <a href="pan_india.php">Return to PAN India search</a>.</div>';
        } else {
            throw new RuntimeException($status['error'] ?? 'Telegram worker is unavailable.');
        }
    } catch (Throwable $e) { ?>
      <div class="pan-alert"><?= htmlspecialchars($e->getMessage()) ?></div>
      <div class="pan-result-state" style="min-height:80px">
        Run <strong>run_crm_server.bat</strong>, then complete the one-time Telegram login in the Telegram Worker terminal.
      </div>
    <?php } ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
