<div class="card">
  <div class="card-header">
    <div>
      <h1 class="card-title">Riders (read only)</h1>
      <span class="badge"><?php echo count($riders); ?> entries</span>
    </div>
  </div>

  <table class="table">
    <thead>
      <tr>
        <th>#</th>
        <th>Name</th>
        <th>Gravity ID</th>
        <th>Club ID</th>
        <th>Active</th>
        <th></th>
      </tr>
    </thead>

    <tbody>
      <?php foreach ($riders as $r): ?>
        <tr>
          <td><?php echo (int)$r['id']; ?></td>
          <td><?php echo htmlspecialchars($r['lastname'] . ', ' . $r['firstname']); ?></td>
          <td><?php echo htmlspecialchars($r['gravity_id'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars((string)($r['club_id'] ?? '')); ?></td>
          <td><?php echo !empty($r['active']) ? '✔' : '✖'; ?></td>
          <td>
            <a class="btn btn-secondary" href="?module=riders&amp;action=view&amp;id=<?php echo (int)$r['id']; ?>">
              View
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
