<?php
if (session_status() == PHP_SESSION_NONE) session_start();
$db = mysqli_connect("localhost", "root", "", "transforshop");
if (!$db) die("Connection failed: " . mysqli_connect_error());
$logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
if (!$logged_in) {
    header("Location: login.php");
    exit();
}
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}
if (isset($_GET['delete_item_id'])) {
    $itemId = intval($_GET['delete_item_id']);
    mysqli_query($db, "DELETE FROM items WHERE id=$itemId;");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
if (isset($_POST['add_item'])) {
    $name = mysqli_real_escape_string($db, $_POST['name']);
    $image = mysqli_real_escape_string($db, $_POST['image']);
    $price = floatval($_POST['price']);
    $stock = intval($_POST['stock']);
    $preorders_left = intval($_POST['preorders_left']);
    if ($image === '') $image = 'https://placehold.co/512';
    if ($stock < 0) $stock = 0;
    if ($preorders_left < 0) $preorders_left = 0;
    mysqli_query($db, "INSERT INTO items (name, image, price, stock, preorders_left) VALUES ('$name', '$image', $price, $stock, $preorders_left);");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['id'], $input['field'], $input['value'])) {
        $id = intval($input['id']);
        $field = mysqli_real_escape_string($db, $input['field']);
        $value = mysqli_real_escape_string($db, $input['value']);
        $allowed_fields = ['name', 'image', 'price', 'stock', 'preorders_left', 'visible'];
        if (in_array($field, $allowed_fields)) {
            if ($field === 'price') $value = floatval($value);
            if (in_array($field, ['stock', 'preorders_left'])) $value = intval($value);
            mysqli_query($db, "UPDATE items SET $field='$value' WHERE id=$id;");
            echo json_encode(['success' => true]);
        } else echo json_encode(['success' => false, 'error' => 'Invalid field']);
    } else echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - TransforShop</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header id="navbar">
        <h1>TransforShop Admin Panel</h1>
        <nav>
            <a href="?logout=true">Logout</a>
        </nav>
    </header>
    <main class="admin-panel">
        <section class="add-item">
            <h2>Add New Item</h2>
            <form method="POST" action="">
                <input type="text" name="name" placeholder="Item Name" required>
                <input type="text" name="image" placeholder="Image URL">
                <input type="number" step="0.01" name="price" placeholder="Price" required>
                <input type="number" name="stock" placeholder="Stock">
                <input type="number" name="preorders_left" placeholder="Preorders Left">
                <button type="submit" name="add_item">Add Item</button>
            </form>
        </section>
        <section class="item-list">
            <h2>Current Items</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Image</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Preorders Left</th>
                        <th>Visible</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $items_query = mysqli_query($db, "SELECT * FROM items;");
                    while ($item = mysqli_fetch_array($items_query)) {
                        // Allow editing of item details
                        echo "<tr>
                            <td>{$item['id']}</td>
                            <td contenteditable='true' onBlur='updateItem({$item['id']}, \"name\", this.innerText)'>{$item['name']}</td>
                            <td contenteditable='true' onBlur='updateItem({$item['id']}, \"image\", this.innerText)'><img src='{$item['image']}' alt='{$item['name']}' width='50'></td>
                            <td contenteditable='true' onBlur='updateItem({$item['id']}, \"price\", this.innerText)'>{$item['price']}</td>
                            <td contenteditable='true' onBlur='updateItem({$item['id']}, \"stock\", this.innerText)'>{$item['stock']}</td>
                            <td contenteditable='true' onBlur='updateItem({$item['id']}, \"preorders_left\", this.innerText)'>{$item['preorders_left']}</td>
                            <td><input type='checkbox' " . ($item['visible'] ? 'checked' : '') . " onchange='updateItem({$item['id']}, \"visible\", this.checked ? 1 : 0)'></td>
                            <td><a href='?delete_item_id={$item['id']}' onclick='return confirm(\"Are you sure?\")'>Delete</a></td>
                        </tr>";
                    }
                    ?>
                </tbody>
            </table>
        </section>
    </main>
</body>
<script>
function updateItem(id, field, value) {
    fetch('admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: id, field: field, value: value })
    }).then(response => response.json()).then(data => {
          if (data.success) console.log('Item updated successfully');
          else alert('Error updating item: ' + data.error);
    });
}
</script>
</html>