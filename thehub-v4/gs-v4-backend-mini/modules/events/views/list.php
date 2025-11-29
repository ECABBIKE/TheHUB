<div class="card">
  <div class="card-header">
    <h1 class="card-title">Events (read only)</h1>
    <span class="badge"><?php echo count($events); ?> st</span>
  </div>

  <table class="table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Namn</th>
        <th>Datum</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($events as $ev): ?>
        <tr>
          <td><?php echo $ev['id']; ?></td>
          <td><?php echo htmlspecialchars($ev['name'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($ev['date'] ?? $ev['start_date'] ?? ''); ?></td>
          <td><a class="btn btn-secondary" href="?module=events&action=view&id=<?php echo $ev['id']; ?>">Visa</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
