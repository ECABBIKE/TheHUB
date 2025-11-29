<?php $isEdit = !empty($cyclist); ?>
<div class="card">
  <div class="card-header">
    <h1 class="card-title"><?php echo $isEdit ? 'Edit cyclist' : 'New cyclist'; ?></h1>
  </div>
  <form method="post" class="form-grid">
    <div class="form-group">
      <label>First name</label>
      <input type="text" name="first_name" required
             value="<?php echo $isEdit ? htmlspecialchars($cyclist['first_name']) : ''; ?>">
    </div>
    <div class="form-group">
      <label>Last name</label>
      <input type="text" name="last_name" required
             value="<?php echo $isEdit ? htmlspecialchars($cyclist['last_name']) : ''; ?>">
    </div>
    <div class="form-group">
      <label>UCI ID</label>
      <input type="text" name="uci_id"
             value="<?php echo $isEdit ? htmlspecialchars($cyclist['uci_id']) : ''; ?>">
    </div>
    <div class="form-group">
      <label>Club</label>
      <input type="text" name="club"
             value="<?php echo $isEdit ? htmlspecialchars($cyclist['club']) : ''; ?>">
    </div>
    <div class="form-actions">
      <button class="btn" type="submit">Save</button>
      <a class="btn btn-secondary" href="?module=cyclists">Cancel</a>
    </div>
  </form>
</div>
