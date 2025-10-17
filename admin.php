<?php
require_once 'secrets.php';

if (session_status() == PHP_SESSION_NONE) session_start();
$db = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_DATABASE);
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

if (isset($_GET['send_order_id'])) {
    $orderId = intval($_GET['send_order_id']);
    $sent_time = date('Y-m-d H:i:s');
    mysqli_query($db, "UPDATE orders SET status='sent', sent_time='$sent_time' WHERE id=$orderId;");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

if (isset($_GET['delete_order_id'])) {
    $orderId = intval($_GET['delete_order_id']);
    $order_contents_query = mysqli_query($db, "SELECT status, items FROM orders WHERE id=$orderId;");
    $order_contents = mysqli_fetch_array($order_contents_query);
    if (empty($order_contents)) { // Should NOT happen
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $items = json_decode($order_contents['items'], true);
    $preorder = in_array($order_contents['status'], array('preorder', 'unpaid preorder'));
    foreach ($items as $itemId => $quantity) {
        mysqli_query($db, "UPDATE items SET stock = stock + $quantity WHERE id=$itemId;");
        if ($preorder) mysqli_query($db, "UPDATE items SET preorders_left = preorders_left + $quantity WHERE id=$itemId;");
    }
    mysqli_query($db, "DELETE FROM orders WHERE id=$orderId;");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

/**
 * Update a field in a database table for a given ID if the field is allowed.
 *
 * @param array $input The input data containing 'id', 'field', and 'value'.
 * @param array $allowed_fields The list of fields that are allowed to be updated.
 * @param mysqli $db The database connection.
 * @param string $table_name The name of the table to update.
 * @return string JSON encoded result indicating success or failure.
 */
function update($input, $allowed_fields, $db, $table_name): string {
    if (!isset($input['id'], $input['field'], $input['value']))
        return json_encode(['success' => false, 'error' => 'Invalid input']);
    $id = intval($input['id']);
    $field = mysqli_real_escape_string($db, $input['field']);
    $value = mysqli_real_escape_string($db, $input['value']);
    if (in_array($field, $allowed_fields)) {
        mysqli_query($db, "UPDATE $table_name SET $field='$value' WHERE id=$id;");
        return json_encode(['success' => true]);
    } return json_encode(['success' => false, 'error' => 'Invalid field']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    if (!isset($_SERVER['HTTP_X_UPDATE_TYPE'])) {
        echo json_encode(['success' => false, 'error' => 'Missing update type']);
        exit();
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if ($_SERVER['HTTP_X_UPDATE_TYPE'] === 'item') {
        echo update($input,
                   ['name', 'description', 'image', 'price', 'stock', 'preorders_left', 'visible'],
                   $db,
                  'items');
    } else if ($_SERVER['HTTP_X_UPDATE_TYPE'] === 'order') {
        echo update($input,
                    ['status', 'name', 'email', 'phone', 'address', 'country', 'postal_code', 'notes'],
                    $db,
                    'orders');
    } else echo json_encode(['success' => false, 'error' => 'Invalid update type']);
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
                <input type="text" name="description" placeholder="Description">
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
                        <th>Description</th>
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
                            <td contenteditable='true' onBlur='updateItem({$item['id']}, \"description\", this.innerText)'>{$item['description']}</td>
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
        <section class="order-list">
            <h2>Orders</h2>
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Order Time</th>
                        <th>Sent Time</th>
                        <th>Status</th>
                        <th>Items</th>
                        <th>Subtotal</th>
                        <th>Shipping</th>
                        <th>Total</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone Number</th>
                        <th>Address</th>
                        <th>Country</th>
                        <th>Postal Code</th>
                        <th>Notes</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $orders_query = mysqli_query($db, "SELECT * FROM orders ORDER BY order_time DESC;");
                    while ($order = mysqli_fetch_array($orders_query)) {
                        $items = json_decode($order['items'], true);
                        $item_list = "<ul>";
                        foreach ($items as $itemId => $quantity) {
                            $item_query = mysqli_query($db, "SELECT * FROM items WHERE id=$itemId;");
                            if ($item_row = mysqli_fetch_array($item_query)) {
                                $item_price = number_format($item_row['price'] * $quantity, 2);
                                $item_list .= "<li>{$item_row['name']} x $quantity ({$item_price}€)</li>";
                            }
                        }
                        $item_list .= "</ul>";
                        $subtotal = number_format($order['subtotal'], 2);
                        $shipping = number_format($order['shipping'], 2);
                        $total = number_format($order['subtotal'] + $order['shipping'], 2);
                        $sent_time = $order['sent_time'] ? date('Y-m-d H:i:s', strtotime($order['sent_time'])) : 'N/A';
                        echo "<tr>";
                        echo "<td>{$order['id']}</td>";
                        echo "<td>{$order['order_time']}</td>";
                        echo "<td>$sent_time</td>";
                        echo "<td><select onchange='updateOrder({$order['id']}, \"status\", this.value)'>";
                        echo "<option value='preorder' " . ($order['status'] === 'preorder' ? 'selected' : '') . ">Preorder</option>";
                        echo "<option value='pending' " . ($order['status'] === 'pending' ? 'selected' : '') . ">Pending</option>";
                        echo "<option value='sent' " . ($order['status'] === 'sent' ? 'selected' : '') . ">Sent</option>";
                        echo "<option value='delivered' " . ($order['status'] === 'delivered' ? 'selected' : '') . ">Delivered</option>";
                        echo "<option value='cancelled' " . ($order['status'] === 'cancelled' ? 'selected' : '') . ">Cancelled</option>";
                        echo "<option value='refunded' " . ($order['status'] === 'refunded' ? 'selected' : '') . ">Refunded</option>";
                        echo "<option value='unpaid' " . ($order['status'] === 'unpaid' ? 'selected' : '') . ">Unpaid</option>";
                        echo "<option value='unpaid preorder' " . ($order['status'] === 'unpaid preorder' ? 'selected' : '') . ">Unpaid Preorder</option>";
                        echo "</select></td>";
                        echo "<td>$item_list</td>";
                        echo "<td>{$subtotal}€</td>";
                        echo "<td>{$shipping}€</td>";
                        echo "<td>{$total}€</td>";
                        echo "<td contenteditable='true' onBlur='updateOrder({$order['id']}, \"name\", this.innerText)'>{$order['name']}</td>";
                        echo "<td contenteditable='true' onBlur='updateOrder({$order['id']}, \"email\", this.innerText)'>{$order['email']}</td>";
                        echo "<td contenteditable='true' onBlur='updateOrder({$order['id']}, \"phone\", this.innerText)'>{$order['phone']}</td>";
                        echo "<td contenteditable='true' onBlur='updateOrder({$order['id']}, \"address\", this.innerText)'>{$order['address']}</td>";
                        echo "<td contenteditable='true' onBlur='updateOrder({$order['id']}, \"country\", this.innerText)'>{$order['country']}</td>";
                        echo "<td contenteditable='true' onBlur='updateOrder({$order['id']}, \"postal_code\", this.innerText)'>{$order['postal_code']}</td>";
                        echo "<td contenteditable='true' onBlur='updateOrder({$order['id']}, \"notes\", this.innerText)'>{$order['notes']}</td>";
                        echo "<td>";
                        if ($order['status'] !== 'sent' && $order['status'] !== 'delivered')
                            echo "<a href='?send_order_id={$order['id']}' onclick='return confirm(\"Mark order as sent?\")'>Mark as Sent</a> | ";
                        echo "<a href='?delete_order_id={$order['id']}' onclick='return confirm(\"Are you sure?\")'>Delete</a>";
                        echo "</td></tr>";
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
        headers: {
            'Content-Type': 'application/json',
            'X-Update-Type': 'item'
        },
        body: JSON.stringify({ id: id, field: field, value: value })
    }).then(response => response.json()).then(data => {
          if (data.success) console.log('Item updated successfully');
          else alert('Error updating item: ' + data.error);
    });
}

function updateOrder(id, field, value) {
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Update-Type': 'order'
        },
        body: JSON.stringify({ id: id, field: field, value: value })
    }).then(response => response.json()).then(data => {
          if (data.success) console.log('Order updated successfully');
          else alert('Error updating order: ' + data.error);
    });
}
</script>
</html>