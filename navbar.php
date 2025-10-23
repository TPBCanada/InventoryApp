<?php
// navbar.php
// expects $current (set in layout.php) and $role_id, $username from your session/auth layer
$active = fn(string $file) => ($file === ($current ?? '')) ? ' is-active' : '';
?>
<nav class="navbar">
  <div class="container flex items-center justify-between p-4">
    <div class="flex items-center g-4">
      <a class="brand<?= $active('dashboard.php') ?>" href="dashboard.php">TPB</a>
      <div class="nav-left flex g-4">
        <?php if (($role_id ?? null) == 1): ?>
          <a class="<?= $active('manage_users.php') ?>" href="manage_users.php">Users</a>
          <a class="<?= $active('manage_roles.php') ?>" href="manage_roles.php">Roles</a>
          <a class="<?= $active('manage_sku.php') ?>" href="manage_sku.php">SKU</a>
          <a class="<?= $active('manage_location.php') ?>" href="manage_location.php">Location</a>
          <a class="<?= $active('history.php') ?>" href="history.php">Transaction History</a>
        <?php endif; ?>
        <?php if (($role_id ?? null) == 2): ?>
          <a class="<?= $active('manage_users.php') ?>" href="manage_users.php">Users</a>
          <a class="<?= $active('manage_location.php') ?>" href="manage_location.php">Location</a>
          <a class="<?= $active('history.php') ?>" href="history.php">Transaction History</a>
        <?php endif; ?>
        <a class="<?= $active('place.php') ?>" href="place.php">Place</a>
        <a class="<?= $active('take.php') ?>" href="take.php">Take</a>
        <a class="<?= $active('transfert.php') ?>" href="transfert.php">Transfert</a>
        <a class="<?= $active('invLoc.php') ?>" href="invLoc.php">Inv by Location</a>
        <a class="<?= $active('invSku.php') ?>" href="invSku.php">Inv by SKU</a>
      </div>
    </div>

    <div class="nav-right flex items-center g-4">
      <span class="welcome text-muted">Welcome, <?= htmlspecialchars($username ?? '') ?></span>
      <a class="btn btn--ghost" href="logout.php">Logout</a>
    </div>
  </div>
</nav>
