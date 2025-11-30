<div class="card">
  <div class="card-header">
    <h1 class="card-title">Riders (read only)</h1>
    <span class="badge"><?php echo count($riders); ?> st</span>
  </div>

  <form method="get" class="form-grid">
    <input type="hidden" name="module" value="riders">

    <div class="form-group">
      <label>Sök</label>
      <input type="text" name="search" placeholder="Namn, Gravity ID, Licens…" 
             value="<?php echo htmlspecialchars($search ?? ''); ?>">
    </div>

    <div class="form-group">
      <label>Disciplin</label>
      <select name="discipline">
        <option value="">Alla</option>
        <option value="enduro"   <?php if ($discipline==='enduro') echo 'selected'; ?>>Enduro</option>
        <option value="downhill" <?php if ($discipline==='downhill') echo 'selected'; ?>>Downhill</option>
        <option value="gravel"   <?php if ($discipline==='gravel') echo 'selected'; ?>>Gravel</option>
      </select>
    </div>

    <div class="form-group">
      <label>Active</label>
      <select name="active">
        <option value="">Alla</option>
        <option value="1" <?php if ($active==='1') echo 'selected'; ?>>Aktiv</option>
        <option value="0" <?php if ($active==='0') echo 'selected'; ?>>Inaktiv</option>
      </select>
    </div>

    <div class="form-group">
      <label>Klubb</label>
      <select name="club">
        <option value="">Alla</option>
        <?php foreach ($clubs as $c): ?>
          <option value="<?php echo htmlspecialchars($c); ?>"
            <?php if ($club == $c) echo 'selected'; ?>>
            <?php echo htmlspecialchars($c); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <button class="btn" type="submit">Filtrera</button>
  </form>

  <table class="table">
    <thead>
      <tr>
        <th>#</th>
        <th>Namn</th>
        <th>Gravity ID</th>
        <th>Klubb</th>
        <th>Aktiv</th>
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
            <a class="btn btn-secondary" href="<?php echo url('?module=riders&action=view&id=' . (int)$r['id']); ?>">
              Visa
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
