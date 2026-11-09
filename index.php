<?php
declare(strict_types=1);
session_start();

/* ---------- Configuration: edit for your environment ---------- */
const DB_HOST = 'localhost';
const DB_NAME = 'lab04_product_entry';
const DB_USER = 'root';
const DB_PASS = '';
const UPLOAD_DIR = __DIR__ . '/uploads/products/';
const MAX_IMAGE_BYTES = 2 * 1024 * 1024;           // 2 MB

/* ---------- Helpers ---------- */
function h(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

function post(string $key): string
{
    return (isset($_POST[$key]) && is_string($_POST[$key])) ? trim($_POST[$key]) : '';
}

function err(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<p class="error" id="' . h($key) . '-error" role="alert">' . h($errors[$key]) . '</p>' : '';
}

function inv(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . h($key) . '-error"' : '';
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* ---------- Allowed values ---------- */
$categories = ['Electronics', 'Clothing', 'Food & Drink', 'Home & Kitchen', 'Stationery', 'Beauty & Health'];

/* ---------- State ---------- */
$errors = [];
$old = ['product_name' => '', 'sku' => '', 'category' => '', 'price' => '', 'quantity' => '',
        'description' => '', 'available' => ''];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- Handle submission ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], post('csrf'))) {
        $errors['form'] = 'Your session expired. Reload the page and submit again.';
    }
    foreach ($old as $key => $_) {
        $old[$key] = post($key);
    }
    $old['product_name'] = preg_replace('/\s+/u', ' ', $old['product_name']);

    // Name: 3-100 characters
    $len = mb_strlen($old['product_name']);
    if ($old['product_name'] === '') {
        $errors['product_name'] = 'Enter the product name.';
    } elseif ($len < 3 || $len > 100) {
        $errors['product_name'] = 'Product name must be 3 to 100 characters.';
    }

    // SKU: required, normalised to upper case, letters/digits/hyphen/underscore, 3-30 chars
    $sku = strtoupper($old['sku']);
    if ($sku === '') {
        $errors['sku'] = 'Enter the SKU.';
    } elseif (!preg_match('/^[A-Z0-9][A-Z0-9_\-]{2,29}$/', $sku)) {
        $errors['sku'] = 'SKU must be 3 to 30 characters: letters, digits, hyphen or underscore.';
    }

    // Category
    if ($old['category'] === '') {
        $errors['category'] = 'Choose a category.';
    } elseif (!in_array($old['category'], $categories, true)) {
        $errors['category'] = 'Choose a category from the list.';
    }

    // Price: > 0, at most 2 decimals, at most 8 integer digits
    if ($old['price'] === '') {
        $errors['price'] = 'Enter the unit price.';
    } elseif (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $old['price']) || (float)$old['price'] <= 0) {
        $errors['price'] = 'Price must be a number greater than 0 (max 2 decimals).';
    }

    // Quantity: non-negative integer
    $qty = filter_var($old['quantity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
    if ($old['quantity'] === '') {
        $errors['quantity'] = 'Enter the quantity (0 or more).';
    } elseif ($qty === false || !preg_match('/^\d+$/', $old['quantity'])) {
        $errors['quantity'] = 'Quantity must be a whole number from 0 to 1,000,000.';
    }

    // Description: max 1000 characters
    if (mb_strlen($old['description']) > 1000) {
        $errors['description'] = 'Description must be 1000 characters or fewer.';
    }

    // Image (optional): JPEG/PNG only, <= 2 MB, verified on the server from file contents
    $imageExt = null;
    $tmp = null;
    $file = $_FILES['image'] ?? null;
    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors['image'] = 'The image is larger than 2 MB.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $errors['image'] = 'The image could not be uploaded. Try again.';
        } elseif ($file['size'] > MAX_IMAGE_BYTES) {
            $errors['image'] = 'The image is larger than 2 MB.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $types = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
            if (!isset($types[$mime]) || @getimagesize($file['tmp_name']) === false) {
                $errors['image'] = 'Only JPEG or PNG images are allowed.';
            } else {
                $imageExt = $types[$mime];
                $tmp = $file['tmp_name'];
            }
        }
    }

    if (!$errors) {
        $stored = null;
        try {
            $pdo = db();
            $dup = $pdo->prepare('SELECT 1 FROM products WHERE sku = :sku LIMIT 1');
            $dup->execute([':sku' => $sku]);

            if ($dup->fetchColumn()) {
                $errors['sku'] = 'This SKU already exists. SKUs must be unique.';
            } else {
                if ($tmp !== null) {
                    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true)) {
                        throw new RuntimeException('Upload directory missing');
                    }
                    $stored = bin2hex(random_bytes(16)) . '.' . $imageExt;   // never use the client's file name
                    if (!move_uploaded_file($tmp, UPLOAD_DIR . $stored)) {
                        $stored = null;
                        throw new RuntimeException('Could not store image');
                    }
                    chmod(UPLOAD_DIR . $stored, 0644);
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO products (product_name, sku, category, unit_price_xaf, quantity, description, image_file, is_available)
                     VALUES (:n, :sku, :c, :p, :q, :d, :img, :a)'
                );
                $stmt->execute([
                    ':n' => $old['product_name'], ':sku' => $sku, ':c' => $old['category'],
                    ':p' => number_format((float)$old['price'], 2, '.', ''), ':q' => $qty,
                    ':d' => $old['description'] !== '' ? $old['description'] : null,
                    ':img' => $stored, ':a' => $old['available'] === '1' ? 1 : 0,
                ]);

                if ($stmt->rowCount() === 1) {
                    $_SESSION['flash'] = ['msg' => 'Product saved (ID ' . $pdo->lastInsertId() . ', SKU ' . $sku . ').'];
                    header('Location: index.php', true, 303);
                    exit;
                }
                throw new RuntimeException('Insert affected no rows');
            }
        } catch (Throwable $e) {
            if ($stored !== null && is_file(UPLOAD_DIR . $stored)) {
                unlink(UPLOAD_DIR . $stored);                                // no orphaned files
            }
            if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
                $errors['sku'] = 'This SKU already exists. SKUs must be unique.';
            } else {
                error_log('Lab04 insert failed: ' . $e->getMessage());
                $errors['form'] = 'The product could not be saved. Please try again later.';
            }
        }
    }
}

/* ---------- Extension: inventory sorted by category ---------- */
$items = [];
$listError = null;
try {
    $items = db()->query(
        'SELECT product_id, sku, product_name, category, unit_price_xaf, quantity, is_available, image_file
         FROM products ORDER BY category ASC, product_name ASC LIMIT 200'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('Lab04 list failed: ' . $e->getMessage());
    $listError = 'The inventory list is unavailable (database connection problem).';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Product Entry</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
  <h1 class="page-title">Product entry</h1>
  <p class="subtitle">Add an inventory item. Fields marked * are required.</p>

  <section class="card" aria-labelledby="form-title">
    <h2 id="form-title">New product</h2>

    <?php if ($flash): ?>
      <div class="alert alert-success" role="status"><?= h($flash['msg']) ?></div>
    <?php endif; ?>
    <?php if (isset($errors['form'])): ?>
      <div class="alert alert-error" role="alert"><?= h($errors['form']) ?></div>
    <?php elseif ($errors): ?>
      <div class="alert alert-error" role="alert">Please correct the highlighted fields.</div>
    <?php endif; ?>

    <form method="post" action="index.php" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_IMAGE_BYTES ?>">

      <div class="form-group">
        <label class="required" for="product_name">Product name</label>
        <input type="text" id="product_name" name="product_name" maxlength="100"
               value="<?= h($old['product_name']) ?>"<?= inv($errors, 'product_name') ?>>
        <?= err($errors, 'product_name') ?>
      </div>

      <div class="form-group">
        <label class="required" for="sku">SKU</label>
        <input type="text" id="sku" name="sku" maxlength="30" autocomplete="off" placeholder="ELEC-0001"
               value="<?= h($old['sku']) ?>"<?= inv($errors, 'sku') ?>>
        <p class="hint">Unique. Letters, digits, hyphen or underscore. Stored in upper case.</p>
        <?= err($errors, 'sku') ?>
      </div>

      <div class="form-group">
        <label class="required" for="category">Category</label>
        <select id="category" name="category"<?= inv($errors, 'category') ?>>
          <option value="">Choose an option</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= h($c) ?>"<?= $old['category'] === $c ? ' selected' : '' ?>><?= h($c) ?></option>
          <?php endforeach; ?>
        </select>
        <?= err($errors, 'category') ?>
      </div>

      <div class="form-group">
        <label class="required" for="price">Unit price (XAF)</label>
        <input type="number" id="price" name="price" min="0.01" step="0.01" inputmode="decimal"
               value="<?= h($old['price']) ?>"<?= inv($errors, 'price') ?>>
        <?= err($errors, 'price') ?>
      </div>

      <div class="form-group">
        <label class="required" for="quantity">Quantity</label>
        <input type="number" id="quantity" name="quantity" min="0" step="1" inputmode="numeric"
               value="<?= h($old['quantity']) ?>"<?= inv($errors, 'quantity') ?>>
        <?= err($errors, 'quantity') ?>
      </div>

      <div class="form-group">
        <label for="description">Description</label>
        <textarea id="description" name="description" maxlength="1000"<?= inv($errors, 'description') ?>><?= h($old['description']) ?></textarea>
        <p class="hint">Up to 1000 characters.</p>
        <?= err($errors, 'description') ?>
      </div>

      <div class="form-group">
        <label for="image">Product image</label>
        <input type="file" id="image" name="image" accept="image/jpeg,image/png"<?= inv($errors, 'image') ?>>
        <p class="hint">Optional. JPEG or PNG, up to 2 MB. Choose the file again after a rejected submission.</p>
        <?= err($errors, 'image') ?>
      </div>

      <div class="form-group">
        <span style="font-weight:600">Available for sale</span><br>
        <label class="checkbox-row" for="available">
          <input type="checkbox" id="available" name="available" value="1"<?= $old['available'] === '1' ? ' checked' : '' ?>>
          I agree
        </label>
        <p class="hint">Tick to list the product as available for sale.</p>
      </div>

      <div class="actions">
        <button type="submit" class="btn-primary">Submit Form</button>
        <button type="reset" class="btn-secondary">Reset</button>
      </div>
    </form>
  </section>

  <section class="card" aria-labelledby="list-title">
    <h2 id="list-title">Inventory by category</h2>
    <?php if ($listError): ?>
      <p class="error"><?= h($listError) ?></p>
    <?php elseif (!$items): ?>
      <p class="empty">No products yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Image</th><th>Category</th><th>SKU</th><th>Name</th><th>Price (XAF)</th><th>Qty</th><th>For sale</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td>
                <?php if ($it['image_file']): ?>
                  <img class="thumb" src="uploads/products/<?= h($it['image_file']) ?>" alt="<?= h($it['product_name']) ?>">
                <?php else: ?>&ndash;<?php endif; ?>
              </td>
              <td><?= h($it['category']) ?></td>
              <td><?= h($it['sku']) ?></td>
              <td><?= h($it['product_name']) ?></td>
              <td><?= h(number_format((float)$it['unit_price_xaf'], 2)) ?></td>
              <td><?= h((string)$it['quantity']) ?></td>
              <td><?= $it['is_available'] ? 'Yes' : 'No' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
