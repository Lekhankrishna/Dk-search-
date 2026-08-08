<?php
require __DIR__ . '/../includes/auth.php';
requireAdmin('../login.php');
require_once __DIR__ . '/../config/db.php';

$logs = $pdo->query(
    'SELECT sl.searched_at, u.username, u.role, sl.search_type, sl.search_query, sl.result_count
     FROM search_logs sl
     JOIN users u ON u.id = sl.user_id
     ORDER BY sl.searched_at DESC
     LIMIT 1000'
)->fetchAll();

$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<div class="page-header">
  <h1 class="page-title"><i class="bi bi-journal-text"></i> Search Audit Log</h1>
  <p class="page-subtitle">Last 1,000 search actions performed by all portal users.</p>
</div>

<div class="card">
  <div class="card-header">
    <i class="bi bi-clock-history" style="color:var(--c-accent)"></i>
    <span class="card-title">Recent Searches</span>
    <span class="badge badge-neutral"><?= count($logs) ?> entries</span>
  </div>
  <div class="card-body p-0">
    <table id="log-table" class="results-table" style="width:100%">
      <thead>
        <tr>
          <th>Time</th>
          <th>User</th>
          <th>Role</th>
          <th>Search Type</th>
          <th>Query</th>
          <th>Results</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($logs as $log):
        $resultClass = $log['result_count'] > 0 ? 'badge-success' : 'badge-neutral';
      ?>
        <tr>
          <td class="text-sm text-muted"><?= htmlspecialchars($log['searched_at']) ?></td>
          <td><strong><?= htmlspecialchars($log['username']) ?></strong></td>
          <td>
            <span class="badge <?= $log['role'] === 'admin' ? 'badge-warning' : 'badge-info' ?>">
              <?= ucfirst($log['role']) ?>
            </span>
          </td>
          <td>
            <span class="badge badge-primary">
              <?= htmlspecialchars(str_replace('_', ' ', $log['search_type'])) ?>
            </span>
          </td>
          <td class="text-sm" style="max-width:320px;word-break:break-all">
            <?= htmlspecialchars($log['search_query']) ?>
          </td>
          <td>
            <span class="badge <?= $resultClass ?>"><?= (int) $log['result_count'] ?></span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
$('#log-table').DataTable({
  pageLength: 25,
  lengthMenu: [25, 50, 100, 250, 500, 1000],
  order: [[0, 'desc']],
  language: { emptyTable: 'No log entries found.' }
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
