<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/masters.php';
require_login();

$m = $_GET['m'] ?? '';
if (!isset($MASTERS[$m])) redirect('dashboard.php');
$cfg = $MASTERS[$m];
if ($cfg['who'] === 'admin') require_admin();
$T = $cfg['table'];
$fields = $cfg['fields'];
$editId = (int)($_GET['id'] ?? 0);
$adding = isset($_GET['add']);

// ------------------------------------------------ validation + save
function validate_field(array $f, $v): ?string {
    [$col, $label, $type, $req] = $f;
    $x = $f[4] ?? [];
    $v = trim((string)$v);
    try {
        switch ($type) {
            case 'tel': v_mobile($v, $label, (bool)$req); break;
            case 'name': v_name($v, $label, (bool)$req, $x['max'] ?? 80); break;
            case 'username':
                if ($v === '') return $req ? "$label is required." : null;
                if (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $v)) return "$label must be 3-50 characters: letters, digits, _ . - only.";
                break;
            case 'aadhar': if ($v === '') return $req ? "$label is required." : null; if (!preg_match('/^\d{12}$/', $v)) return "$label must be exactly 12 digits."; break;
            case 'pan': if ($v === '') return $req ? "$label is required." : null; if (!preg_match('/^[A-Za-z]{5}\d{4}[A-Za-z]$/', $v)) return "$label must look like ABCDE1234F."; break;
            case 'email':
                if ($v === '') return $req ? "$label is required." : null;
                if (strlen($v) > 80 || !filter_var($v, FILTER_VALIDATE_EMAIL)) return "$label is not a valid email.";
                break;
            case 'money': v_num($v, $label, $x['min'] ?? 0, 9999999.99, false, (bool)$req); break;
            case 'pct': v_num($v, $label, 0, 100, false, (bool)$req); break;
            case 'int': v_num($v, $label, 0, $x['maxv'] ?? 1000000, true, (bool)$req); break;
            case 'date': v_date($v, $label, empty($x['nofuture']), (bool)$req); break;
            case 'textarea': v_text($v, $label, 255, (bool)$req); break;
            case 'text':
                $t = v_text($v, $label, $x['max'] ?? 80, (bool)$req);
                if ($t !== null && preg_match('/[<>]/', $t)) return "$label must not contain < or >.";
                break;
            default: if ($v === '' && $req) return "$label is required.";
        }
    } catch (RuntimeException $ex) { return $ex->getMessage(); }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $act = $_POST['act'] ?? 'save';
    if ($act === 'delete') {
        if (!is_admin()) { flash('Only admin can delete.', 'err'); redirect("master.php?m=$m"); }
        $id = (int)$_POST['id'];
        if ($m === 'users' && $id === (int)user()['id']) { flash('You cannot delete your own login.', 'err'); redirect("master.php?m=$m"); }
        try { q("DELETE FROM `$T` WHERE id = ?", [$id]); flash('Record deleted.'); }
        catch (PDOException $ex) { flash('Cannot delete: this record is already used in bills, products, commissions or stock.', 'err'); }
        redirect("master.php?m=$m");
    }
    $id = (int)($_POST['id'] ?? 0);
    $errors = []; $data = [];
    foreach ($fields as $f) {
        [$col, $label, $type, $req] = $f;
        $x = $f[4] ?? [];
        if ($id && !empty($x['admin_edit']) && !is_admin()) continue;
        if (!empty($x['admin']) && !is_admin()) { if ($id) continue; $data[$col] = 0; continue; } // staff cannot set purchase rate
        $v = trim((string)($_POST[$col] ?? ''));
        if ($m === 'users' && $col === 'password') { if ($v === '' && !$id) $errors[] = 'Password is required.'; if ($v !== '' && strlen($v) < 6) $errors[] = 'Password needs 6+ characters.'; continue; }
        if ($err = validate_field($f, $v)) $errors[] = $err;
        if ($type === 'pan') $v = strtoupper($v);
        $data[$col] = ($v === '' && !$req) ? null : $v;
    }
    if ($m === 'users') {
        $pw = trim($_POST['password'] ?? '');
        if ($pw !== '') $data['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
    }
    if ($m === 'products' && empty($errors)) {
        $ok = val('SELECT COUNT(*) FROM subcategories WHERE id = ? AND category_id = ?', [$data['subcategory_id'] ?? 0, $data['category_id'] ?? 0]);
        if (!$ok) $errors[] = 'Subcategory does not belong to the selected category.';
    }
    if (!$errors && !empty($cfg['dupe_mobile'])) {
        $mob = $data['mobile'] ?? ''; $alt = $data['alt_mobile'] ?? null;
        if ($alt && $alt === $mob) $errors[] = 'Alt. Mobile No must be different from Mobile No.';
        elseif ($mob !== '' && val("SELECT COUNT(*) FROM `$T` WHERE mobile = ? AND id <> ?", [$mob, $id])) $errors[] = 'This mobile number is already registered in ' . $cfg['title'] . '.';
    }
    if (!$errors && $m === 'employees') {
        if (($data['dob'] ?? '') > date('Y-m-d', strtotime('-14 years'))) $errors[] = 'Employee must be at least 14 years old.';
        elseif (($data['joining_date'] ?? '') < date('Y-m-d', strtotime(($data['dob'] ?? '2000-01-01') . ' +14 years'))) $errors[] = 'Joining date is too early for the given date of birth.';
    }
    if (!$errors && $m === 'products' && isset($data['purchase_rate'], $data['sales_rate']) && (float)$data['purchase_rate'] > 0 && (float)$data['sales_rate'] < (float)$data['purchase_rate']) {
        $errors[] = 'Sales rate is lower than the purchase rate. Please check the rates.';
    }
    if (!$errors && !empty($data['vendor_id']) && !val('SELECT COUNT(*) FROM vendors WHERE id = ?', [$data['vendor_id']])) $errors[] = 'Selected vendor does not exist.';
    if ($errors) {
        flash(implode(' ', $errors), 'err');
        $_SESSION['old'] = $_POST;
        redirect("master.php?m=$m" . ($id ? "&id=$id" : '&add=1'));
    }
    try {
        if ($id) {
            $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
            q("UPDATE `$T` SET $set WHERE id = ?", [...array_values($data), $id]);
            flash('Record updated successfully.');
        } else {
            $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($data)));
            $ph = implode(', ', array_fill(0, count($data), '?'));
            q("INSERT INTO `$T` ($cols) VALUES ($ph)", array_values($data));
            flash('Record inserted successfully.');
        }
    } catch (PDOException $ex) {
        flash($ex->errorInfo[1] == 1062 ? 'This record already exists (duplicate).' : 'Could not save the record.', 'err');
        $_SESSION['old'] = $_POST;
        redirect("master.php?m=$m" . ($id ? "&id=$id" : '&add=1'));
    }
    redirect("master.php?m=$m");
}

// ------------------------------------------------ views
$page_title = $cfg['title'];
require __DIR__ . '/includes/header.php';

if ($adding || $editId):
    $rec = $editId ? row("SELECT * FROM `$T` WHERE id = ?", [$editId]) : [];
    if ($editId && !$rec) { echo '<div class="card">Record not found.</div>'; require __DIR__ . '/includes/footer.php'; exit; }
    $old = $_SESSION['old'] ?? null; unset($_SESSION['old']);
    if ($old) $rec = array_merge($rec ?: [], $old);
    if (!$editId && in_array($m, ['customers', 'vendors'], true) && empty($rec['reg_date'])) $rec['reg_date'] = date('Y-m-d');
    foreach (['added_date', 'tie_up_date', 'joining_date'] as $dc) if (!$editId && empty($rec[$dc])) $rec[$dc] = date('Y-m-d');
    if (!$editId && $m === 'products') { $rec += ['unit' => 'Box', 'stock_qty' => 0]; }
?>
<div class="card">
<h3><?= $editId ? 'Edit' : 'Add' ?> <?= e(rtrim($cfg['title'], 's')) ?><?= $editId ? ' #' . $editId : '' ?></h3>
<form method="post" class="form">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $editId ?>">
  <?php foreach ($fields as $f):
      [$col, $label, $type, $req] = $f; $x = $f[4] ?? [];
      $v = $rec[$col] ?? '';
      if ($m === 'users' && $col === 'password') $v = '';
      $ro = ($editId && !is_admin() && (!empty($x['admin_edit']) || !empty($x['admin'])));
      if (!empty($x['admin']) && !is_admin() && !$editId) $ro = false;
      if (!empty($x['admin']) && !is_admin()) { // staff never sees purchase rate
          if (!$editId) { echo '<input type="hidden" name="' . $col . '" value="0">'; }
          continue;
      }
  ?>
  <div>
    <label><?= e($label) ?><?= $req ? ' *' : '' ?></label>
    <?php if ($type === 'textarea'): ?>
      <textarea name="<?= $col ?>" maxlength="255"><?= e($v) ?></textarea>
    <?php elseif ($type === 'select'):
        $opts = [];
        if (isset($x['src'])) foreach (rows($x['src']) as $o) $opts[$o['id']] = [$o['name'], $o['parent'] ?? ''];
        else foreach ($x['opts'] as $k => $l) $opts[$k] = [$l, '']; ?>
      <select name="<?= $col ?>" <?= isset($x['parent']) ? 'data-parent="' . e($x['parent']) . '"' : '' ?> <?= $ro ? 'disabled' : '' ?> <?= $req ? 'required' : '' ?>>
        <option value="">-- select --</option>
        <?php foreach ($opts as $k => [$l, $par]): ?>
          <option value="<?= e($k) ?>" data-p="<?= e($par) ?>" <?= (string)$v === (string)$k ? 'selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
    <?php else:
        $html = ['date' => 'date', 'email' => 'email', 'password' => 'password', 'money' => 'number', 'int' => 'number', 'pct' => 'number'][$type] ?? 'text';
        $attr = $type === 'money' ? 'step="0.01" min="' . ($x['min'] ?? 0) . '" max="9999999.99"' : ($type === 'int' ? 'step="1" min="0" max="' . ($x['maxv'] ?? 1000000) . '"' : ($type === 'pct' ? 'step="0.01" min="0" max="100"' : ''));
        if ($type === 'date') $attr = !empty($x['nofuture']) ? 'max="' . date('Y-m-d') . '"' : '';
        if ($type === 'tel') $attr = 'pattern="[6-9]\d{9}" maxlength="10" inputmode="numeric" data-type="digits" title="10 digits, starting with 6-9" placeholder="10 digit mobile"';
        if ($type === 'aadhar') $attr = 'pattern="\d{12}" maxlength="12" inputmode="numeric" data-type="digits" title="12 digits"';
        if ($type === 'name') $attr = 'maxlength="' . ($x['max'] ?? 80) . '" data-type="name" title="Letters, spaces and . \' - only"';
        if ($type === 'text') $attr = 'maxlength="' . ($x['max'] ?? 80) . '"';
        if ($type === 'email') $attr = 'maxlength="80"';
        if ($type === 'username') $attr = 'maxlength="50" pattern="[A-Za-z0-9_.\-]{3,50}" title="Letters, digits, _ . - (3-50)"';
        if ($type === 'pan') $attr = 'pattern="[A-Za-z]{5}\d{4}[A-Za-z]" maxlength="10" style="text-transform:uppercase"';
    ?>
      <input type="<?= $html ?>" name="<?= $col ?>" value="<?= e($v) ?>" <?= $attr ?> <?= $req && $type !== 'password' ? 'required' : '' ?> <?= $ro ? 'readonly' : '' ?>>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <div style="grid-column:1/-1">
    <button class="btn">Save</button>
    <a class="btn alt" href="master.php?m=<?= e($m) ?>">Cancel</a>
  </div>
</form></div>
<script>
// dependent dropdown: subcategory list filtered by chosen category
document.querySelectorAll('select[data-parent]').forEach(function (child) {
  var parent = document.querySelector('[name="' + child.dataset.parent + '"]');
  if (!parent) return;
  function filter() {
    Array.prototype.forEach.call(child.options, function (o) {
      if (!o.value) return;
      var show = !parent.value || o.dataset.p === parent.value;
      o.hidden = !show; o.disabled = !show;
      if (!show && o.selected) child.value = '';
    });
  }
  parent.addEventListener('change', filter); filter();
});
</script>
<?php
else:
    $search = trim($_GET['s'] ?? '');
    $sql = $cfg['sql']; $params = [];
    if ($search !== '') {
        $sql .= ' WHERE (' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $cfg['search'])) . ')';
        $params = array_fill(0, count($cfg['search']), "%$search%");
    }
    $list = rows($sql . ' ORDER BY 1 DESC LIMIT 500', $params);
    $cols = $cfg['cols'];
    if (!is_admin() && !empty($cfg['hide_staff'])) foreach ($cfg['hide_staff'] as $h) unset($cols[$h]);
?>
<div class="bar">
  <form method="get" class="bar" style="margin:0"><input type="hidden" name="m" value="<?= e($m) ?>">
    <div><label>Search</label><input name="s" value="<?= e($search) ?>" placeholder="Type to search..."></div>
    <button class="btn alt">Search</button>
  </form>
  <a class="btn" href="master.php?m=<?= e($m) ?>&add=1">+ Add New</a>
</div>
<div class="tw"><table>
<tr><?php foreach ($cols as $c => $l): ?><th><?= e($l) ?></th><?php endforeach; ?><th class="no-print">Actions</th></tr>
<?php foreach ($list as $r): ?>
<tr>
  <?php foreach ($cols as $c => $l): $v = $r[$c];
      if (in_array($c, $cfg['money'] ?? [])) $v = money($v);
      elseif ($c === 'commission_pct') $v = ($v + 0) . '%';
      elseif (preg_match('/_date$|^dob$/', $c) || $c === 'reg_date') $v = dmy($v);
      elseif ($c === 'is_active') $v = $v ? 'Yes' : 'No';
      $cls = ($c === 'stock_qty' && (int)$r[$c] <= LOW_STOCK_LIMIT) ? 'badge low' : ''; ?>
    <td><?= $cls ? '<span class="' . $cls . '">' . e($v) . '</span>' : e($v) ?></td>
  <?php endforeach; ?>
  <td class="no-print" style="white-space:nowrap">
    <a class="btn sm alt" href="master.php?m=<?= e($m) ?>&id=<?= $r['id'] ?>">Edit</a>
    <?php if ($m === 'customers'): ?><a class="btn sm" href="receive_payment.php?cid=<?= $r['id'] ?>">Payments</a><?php endif; ?>
    <?php if ($m === 'vendors'): ?><a class="btn sm" href="vendor_detail.php?id=<?= $r['id'] ?>">Commission</a><?php endif; ?>
    <?php if (is_admin()): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete this record?')">
      <?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
      <button class="btn sm danger">Delete</button></form>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; if (!$list): ?><tr><td colspan="<?= count($cols) + 1 ?>">No records found.</td></tr><?php endif; ?>
</table></div>
<?php endif;
require __DIR__ . '/includes/footer.php';
