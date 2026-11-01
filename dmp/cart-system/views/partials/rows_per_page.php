<form method="get" action="<?php echo APP_URL; ?>/">
  <?php foreach ($_GET as $k => $v): if (is_scalar($v) && $k !== 'per_page' && $k !== 'pg'): ?>
    <input type="hidden" name="<?php echo e($k); ?>" value="<?php echo e($v); ?>">
  <?php endif; endforeach; ?>
  <div class="d-flex align-items-center gap-2">
    <label class="text-muted small mb-0">Per page</label>
    <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;min-width:70px">
      <?php $cur = per_page(); foreach ([5,10,25,50,100] as $opt): ?>
        <option value="<?php echo $opt; ?>" <?php if ($cur == $opt) echo 'selected'; ?>><?php echo $opt; ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</form>
