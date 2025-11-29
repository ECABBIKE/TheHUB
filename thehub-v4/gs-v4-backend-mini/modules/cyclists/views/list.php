<div class="card">
  <div class="card-header">
    <div>
      <h1 class="card-title">Cyclists</h1>
      <span class="badge"><?php echo count($cyclists); ?> entries</span>
    </div>
    <a class="btn" href="?module=cyclists&amp;action=create">+ New</a>
  </div>

  <table class="table">
    <thead>
      <tr>
        <th>#</th>
        <th>Name</th>
        <th>UCI ID</th>
        <th>Club</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($cyclists): ?>
        <?php foreach ($cyclists as $c): ?>
          <tr>
            <td><?php echo (int)$c['id']; ?></td>
            <td><?php echo htmlspecialchars($c['last_name'] . ', ' . $c['first_name']); ?></td>
            <td><?php echo htmlspecialchars($c['uci_id'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($c['club'] ?? ''); ?></td>
            <td class="table-actions">
              <a class="btn btn-secondary" href="?module=cyclists&amp;action=edit&amp;id=<?php echo (int)$c['id']; ?>">Edit</a>
              <a class="btn btn-danger" href="?module=cyclists&amp;action=delete&amp;id=<?php echo (int)$c['id']; ?>"
                 onclick="return confirm('Delete this cyclist?');">Delete</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="5">No cyclists yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
