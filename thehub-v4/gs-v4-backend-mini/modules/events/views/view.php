<div class="card">
  <div class="card-header">
    <h1 class="card-title">
      <?php echo htmlspecialchars($event['name'] ?? 'Event'); ?>
    </h1>
  </div>

  <table class="table">
    <?php foreach ($event as $key => $value): ?>
      <tr>
        <th><?php echo htmlspecialchars($key); ?></th>
        <td><?php echo htmlspecialchars((string)$value); ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <a class="btn btn-secondary" href="?module=events">← Back</a>
</div>
