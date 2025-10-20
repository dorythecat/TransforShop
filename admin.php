<?php
require_once 'secrets.php';

if (session_status() == PHP_SESSION_NONE) session_start();
$db = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_DATABASE);
if (!$db) die("Connection failed: " . mysqli_connect_error());
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in'] === true || isset($_GET['logout'])) {
    if (isset($_GET['logout'])) unset($_SESSION['admin_logged_in']);
    header("Location: login.php");
    exit();
}
if (isset($_GET['delete_item_id'])) {
    $itemId = intval($_GET['delete_item_id']);
    mysqli_query($db, "DELETE FROM items WHERE id=$itemId");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

if (isset($_POST['add_item'])) {
    $name = mysqli_real_escape_string($db, $_POST['name']);
    $image = mysqli_real_escape_string($db, $_POST['image']);
    $price = floatval($_POST['price']);
    $stock = intval($_POST['stock']);
    $preorders_left = intval($_POST['preorders_left']);
    if ($image === '') $image = 'https://picsum.photos/256'; // Default placeholder image
    $stock = max(0, $stock);
    $preorders_left = max(0, $preorders_left);
    mysqli_query($db, "INSERT INTO items (name, image, price, stock, preorders_left) VALUES
                                               ('$name', '$image', $price, $stock, $preorders_left)");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

if (isset($_GET['send_order_id'])) {
    $orderId = intval($_GET['send_order_id']);
    $sent_time = date('Y-m-d H:i:s');
    mysqli_query($db, "UPDATE orders SET status='sent', sent_time='$sent_time' WHERE id=$orderId");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

if (isset($_GET['delete_order_id'])) {
    $orderId = intval($_GET['delete_order_id']);
    $order_contents_query = mysqli_query($db, "SELECT status, items FROM orders WHERE id=$orderId");
    $order_contents = mysqli_fetch_array($order_contents_query);
    if (empty($order_contents)) { // Should NOT happen
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $items = json_decode($order_contents['items'], true);
    $preorder = in_array($order_contents['status'], array('preorder', 'unpaid preorder'));
    foreach ($items as $itemId => $quantity) {
        mysqli_query($db, "UPDATE items SET stock = stock + $quantity WHERE id=$itemId");
        if ($preorder) mysqli_query($db, "UPDATE items SET preorders_left = preorders_left + $quantity WHERE id=$itemId");
    }
    mysqli_query($db, "DELETE FROM orders WHERE id=$orderId");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Helper functions for error responses
function error_invalid($msg) { return json_encode(['success' => false, 'error' => "Invalid " . $msg]); }
function error_failed_to($msg) { return json_encode(['success' => false, 'error' => "Failed to " . $msg]); }

/**
 * Update a field in a database table for a given ID if the field is allowed.
 *
 * @param array $input The input data containing 'id', 'field', and 'value'.
 * @param array $allowed_fields The list of fields that are allowed to be updated.
 * @param mysqli $db The database connection.
 * @param string $table_name The name of the table to update.
 *
 * @return string JSON encoded result indicating success or failure.
 */
function update($input, $allowed_fields, $db, $table_name) {
    if (!isset($input['id'], $input['field'], $input['value'])) return error_invalid('input');
    $id = intval($input['id']);
    $field = mysqli_real_escape_string($db, $input['field']);
    // Keep original (unescaped) value for special commands like 'refresh'
    $raw_value = $input['value'];

    // Special-case: support a 'refresh' request for item stock (no UPDATE, just return current stock)
    if ($table_name === 'items' && $field === 'stock' && $raw_value === 'refresh') {
        $res = mysqli_query($db, "SELECT stock, preorders_left FROM items WHERE id=$id");
        if (!$res) return error_invalid('item');
        $row = mysqli_fetch_array($res);
        if (empty($row)) return error_invalid('item');
        return json_encode(['success' => true,
                            'stock' => intval($row['stock']),
                            'preorders_left' => intval($row['preorders_left'])]);
    }

    // If updating an orders numeric field (subtotal or shipping), coerce to numeric and update without quotes,
    // then recompute total and return the new numbers to the client so the UI can refresh.
    if (!in_array($field, $allowed_fields)) return error_invalid('field');
    if ($table_name === 'orders' && ($field === 'subtotal' || $field === 'shipping')) {
        // Extract numeric value (allow digits, dot and minus)
        $num = floatval(preg_replace('/[^0-9.\-]/', '', strval($raw_value)));
        // Persist the numeric value
        if (!mysqli_query($db, "UPDATE orders SET $field=$num WHERE id=$id"))
            return error_failed_to('update order');
        // Re-fetch subtotal and shipping to compute total (in case only one was updated)
        $res2 = mysqli_query($db, "SELECT subtotal, shipping FROM orders WHERE id=$id");
        $row2 = mysqli_fetch_array($res2);
        $subtotal = floatval($row2['subtotal']);
        $shipping = floatval($row2['shipping']);
        $total = $subtotal + $shipping;
        // Persist total
        mysqli_query($db, "UPDATE orders SET total=$total WHERE id=$id");
        return json_encode(['success' => true,
                            'subtotal' => $subtotal,
                            'shipping' => $shipping,
                            'total' => $total]);
    }

    if ($table_name === 'items' && ($field === 'stock' || $field === 'preorders_left')) {
        $value = max(0, intval($raw_value));
        $res = mysqli_query($db, "SELECT stock, preorders_left FROM items WHERE id=$id");
        $row = mysqli_fetch_array($res);
        if ($field === 'stock') {
            $stock = $value;
            $preorders_left = min(intval($row['preorders_left']), $value);
        } else {
            $stock = intval($row['stock']);
            $preorders_left = min($value, $stock);
        }
        mysqli_query($db, "UPDATE $table_name SET stock=$stock, preorders_left=$preorders_left WHERE id=$id");
        return json_encode(['success' => true,
                            'stock' => $stock,
                            'preorders_left' => $preorders_left]);
    }

    // Default behaviour: update as string/value
    $value = mysqli_real_escape_string($db, $raw_value);
    mysqli_query($db, "UPDATE $table_name SET $field='$value' WHERE id=$id");
    return json_encode(['success' => true]);
}

/**
 * Handle order items update actions (add/update/remove). Expects $input['value'] to be a JSON string
 * with at least an 'action' key: 'add'|'update'|'remove'.
 * For 'add' provide 'item_id' and 'qty'. For 'update' provide 'item_id' and 'qty'. For 'remove' provide 'item_id'.
 *
 * @param array $input The input data containing 'id' and 'value'.
 * @param mysqli $db The database connection.
 *
 * @return string JSON encoded result indicating success or failure, updated items, and subtotal.
 */
function updateOrderItems($input, $db) {
    if (!isset($input['id'], $input['value'])) return error_invalid('input');
    $orderId = intval($input['id']);
    $payload = json_decode($input['value'], true);
    if (!$payload || !isset($payload['action'])) return error_invalid('payload');

    // Fetch current items, status and shipping in one go
    $res = mysqli_query($db, "SELECT items, status, shipping FROM orders WHERE id=$orderId");
    if (!$res) return error_invalid('order');
    $row = mysqli_fetch_array($res);
    if (!$row) return error_invalid('order');

    $prev_items = [];
    if (!empty($row['items'])) {
        $prev_items = json_decode($row['items'], true);
        if (!is_array($prev_items)) $prev_items = [];
    }
    $prev_preorder = in_array($row['status'], array('preorder', 'unpaid preorder'));

    // Start from previous items map and apply action to produce new items
    $items = $prev_items; // copy
    $action = $payload['action'];
    $item_id = isset($payload['item_id']) ? intval($payload['item_id']) : null;
    $qty = isset($payload['qty']) ? intval($payload['qty']) : null;

    switch ($action) {
        case 'add':
            if (!$item_id || $qty <= 0) return error_invalid('add parameters');
            $items[$item_id] = (isset($items[$item_id]) ? $items[$item_id] : 0) + $qty;
            break;
        case 'update':
            if (!$item_id) return error_invalid('update parameters');
            if ($qty <= 0) unset($items[$item_id]); // remove if no units
            else $items[$item_id] = $qty;
            break;
        case 'remove':
            if (!$item_id) return error_invalid('remove parameters');
            unset($items[$item_id]);
            break;
        default: return error_invalid('action');
    }

    // Recalculate subtotal based on current item prices
    $subtotal = 0.0;
    $ids = array_keys($items);
    $prices = [];
    if (!empty($ids)) {
        $ids_list = implode(',', array_map('intval', $ids));
        $prices_res = mysqli_query($db, "SELECT id, price FROM items WHERE id IN ($ids_list)");
        if ($prices_res) while ($p = mysqli_fetch_array($prices_res)) $prices[intval($p['id'])] = floatval($p['price']);
        foreach ($items as $id => $q) $subtotal += isset($prices[intval($id)]) ? $prices[intval($id)] * intval($q) : 0.0;
    }

    // Prepare values for DB update
    $items_json = mysqli_real_escape_string($db, json_encode($items));
    $total = $subtotal + floatval($row['shipping']);

    // Start transaction
    if (!mysqli_begin_transaction($db)) return error_failed_to('start db transaction');

    // Update orders table
    $orders_update_sql = "UPDATE orders SET items='$items_json', subtotal=$subtotal, total=$total WHERE id=$orderId";
    if (!mysqli_query($db, $orders_update_sql)) {
        mysqli_rollback($db);
        return error_failed_to('update orders');
    }

    // Compute deltas per item to adjust stock and preorders_left in a minimal number of updates
    $all_ids = array_unique(array_merge(array_keys($prev_items), array_keys($items)));
    if (!empty($all_ids)) {
        // prepare statement once
        $stmt = mysqli_prepare($db, "UPDATE items SET stock = stock + ?, preorders_left = preorders_left + ? WHERE id = ?;");
        $fail = false;
        foreach ($all_ids as $iid) {
            $pid = intval($iid);
            $prev_qty = isset($prev_items[$pid]) ? intval($prev_items[$pid]) : 0;
            $new_qty = isset($items[$pid]) ? intval($items[$pid]) : 0;
            $stock_delta = $prev_qty - $new_qty; // add this amount to stock
            $preorders_delta = $prev_preorder ? $stock_delta : 0;
            if ($stock_delta === 0) continue;
            if ($stmt) { // Use prepared statement
                mysqli_stmt_bind_param($stmt, 'iii', $stock_delta, $preorders_delta, $pid);
                $fail = !mysqli_stmt_execute($stmt);
            } else { // Fallback: run individual queries if prepare fails
                $q = "UPDATE items SET stock = stock + $stock_delta,
                                       preorders_left = preorders_left + $preorders_delta WHERE id=$pid";
                $fail = !mysqli_query($db, $q);
            } if ($fail) break;
        } if ($stmt) mysqli_stmt_close($stmt);
        if ($fail) { mysqli_rollback($db); return error_failed_to('update orders'); }
    }

    // Commit transaction
    if (!mysqli_commit($db)) {
        mysqli_rollback($db);
        return error_failed_to('commit db transaction');
    }

    // Return success with updated items and subtotal/total
    return json_encode(['success' => true,
                        'items' => $items,
                        'subtotal' => $subtotal,
                        'total' => $total]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    if (!isset($_SERVER['HTTP_X_UPDATE_TYPE'])) {
        echo json_encode(['success' => false, 'error' => 'Missing update type']);
        exit();
    }
    $type = $_SERVER['HTTP_X_UPDATE_TYPE'];
    $allowed_list = ['status', 'name', 'subtotal', 'shipping', 'email', 'phone', 'address', 'country', 'postal_code', 'notes'];
    if (in_array($type, ['item', 'order'])) {
        $input = json_decode(file_get_contents('php://input'), true);
        if ($type == 'order' && isset($input['field']) && $input['field'] === 'items') {
            echo updateOrderItems($input, $db);
            exit();
        } if ($type === 'item') $allowed_list = ['name', 'description', 'image', 'price', 'stock', 'preorders_left', 'visible'];
        echo update($input, $allowed_list, $db, $type . 's');
    } else echo error_invalid('update type');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>TransforMate Official Shop | Admin Panel</title>

    <link rel="canonical" href="https://shop.transformate.live/admin.php">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header id="navbar">
        <h1>TransforMate Official Shop | Admin Pane</h1>
        <?php echo '<p>Logged in as <strong>' . htmlspecialchars($_SESSION['username']) . '</strong></p>'; ?>
        <nav><a href="?logout">Logout</a></nav>
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
                        <th>ID</th><th>Name</th><th>Description</th><th>Image</th><th>Price</th>
                        <th>Stock</th><th>Preorders Left</th><th>Visible</th><th>Admin actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $items_query = mysqli_query($db, "SELECT * FROM items");
                    // Build a map of all available items (id=>name) to use in order editors
                    $all_items_map = array();
                    $all_items_res = mysqli_query($db, "SELECT id, name FROM items");
                    while ($ai = mysqli_fetch_array($all_items_res)) $all_items_map[intval($ai['id'])] = $ai['name'];

                    while ($item = mysqli_fetch_array($items_query)) {
                        $id = (int)$item['id'];
                        $name = htmlspecialchars($item['name']);
                        $description = htmlspecialchars($item['description']);
                        $image = htmlspecialchars($item['image']);
                        $price = htmlspecialchars($item['price']);
                        $stock = htmlspecialchars($item['stock']);
                        $preorders_left = htmlspecialchars($item['preorders_left']);
                        $visible_checked = !empty($item['visible']) ? 'checked' : '';

                        echo "<tr data-item-id='$id'>";
                        echo "<td>$id</td>";
                        echo "<td contenteditable='true' onBlur='updateItem($id, \"name\", this.innerText)'>$name</td>";
                        echo "<td contenteditable='true' onBlur='updateItem($id, \"description\", this.innerText)'>$description</td>";
                        // Image preview and editable URL on click
                        echo "<td><div class='image-cell'><img src='$image' alt='$name Image' class='item-image-preview' onclick='let url=prompt(\"Image URL:\", this.src); if(url){this.src=url; updateItem($id, \"image\", url);}'>";
                        echo "</div></td>";
                        echo "<td contenteditable='true' onBlur='updateItem($id, \"price\", this.innerText)'>$price</td>";
                        echo "<td contenteditable='true' onBlur='updateItem($id, \"stock\", this.innerText)'>$stock</td>";
                        echo "<td contenteditable='true' onBlur='updateItem($id, \"preorders_left\", this.innerText)'>$preorders_left</td>";
                        echo "<td><input type='checkbox' $visible_checked onchange='updateItem($id, \"visible\", this.checked)'></td>";
                        echo "<td><a href='?delete_item_id=$id' onclick='return confirm(\"Are you sure?\")'>Delete</a></td></tr>";
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
                        <th>Order ID</th><th>Order Time</th><th>Sent Time</th><th>Status</th>
                        <th>Items</th><th>Subtotal</th><th>Shipping</th><th>Total</th>
                        <th>Name</th><th>Email</th><th>Phone Number</th><th>Address</th>
                        <th>Country</th><th>Postal Code</th><th>Notes</th><th>Admin actions</th>
                    </tr>
                </thead>
                <?php
                echo "<tbody>";
                $orders_query = mysqli_query($db, "SELECT * FROM orders ORDER BY order_time DESC");
                // Small helper to reduce clutter
                function sel($value) { global $order; return $order['status'] === $value ? 'selected' : ''; }
                while ($order = mysqli_fetch_array($orders_query)) {
                    $items = json_decode($order['items'], true);
                    if (!is_array($items)) $items = [];
                    echo "<tr><td>{$order['id']}</td>";
                    echo "<td>" . date('d/m/Y H:m:s', strtotime($order['order_time'])) . "</td>";
                    echo "<td>" . ($order['sent_time'] ? date('d/m/Y H:m:s', strtotime($order['sent_time'])) : 'N/A') . "</td>";
                    $values = ['preorder', 'pending', 'sent', 'delivered', 'cancelled', 'refunded', 'unpaid', 'unpaid preorder'];
                    echo "<td><select onchange='updateOrder({$order['id']}, \"status\", this.value)'>";
                    foreach ($values as $v) echo "<option value='$v' " . sel($v) . ">" . ucfirst($v) . "</option>";
                    echo "</select></td>";
                    // Render editable items list: quantity inputs, remove buttons, and add controls
                    echo "<td><div id='order-items-{$order['id']}' style='display:flex;flex-direction:column;gap:6px;'>";
                    foreach ($items as $itemId => $quantity) {
                        $item_query = mysqli_query($db, "SELECT name FROM items WHERE id=$itemId");
                        $item_row = mysqli_fetch_array($item_query);
                        echo "<div class='order-item' data-item-id='$itemId'>";
                        echo "<span class='item-name'>" . ($item_row ? htmlspecialchars($item_row['name']) : 'Unknown Item') . "</span> ";
                        echo "<input type='number' min='0' value='$quantity' style='width:70px' onchange='updateOrderItem({$order['id']}, $itemId, this.value)'> ";
                        echo "<button onclick='removeOrderItem({$order['id']}, $itemId); return false;'>X</button></div>";
                    }
                    // Add item controls
                    echo "<div class='add-order-item'><select id='add-item-select-{$order['id']}'>";
                    foreach ($all_items_map as $id => $name) echo "<option value='$id'>" . htmlspecialchars($name) . "</option>";
                    echo "</select>";
                    echo "<input id='add-item-qty-{$order['id']}' type='number' min='1' value='1' style='width:60px;'>";
                    echo "<button onclick='addOrderItem({$order['id']}); return false;'>+</button></div></div></td>";
                    $fields = ['subtotal', 'shipping', 'total',
                               'name', 'email', 'phone', 'address', 'country', 'postal_code', 'notes'];
                    foreach ($fields as $field) {
                        if (in_array($field, ['subtotal', 'shipping', 'total'])) {
                            $field_value = number_format($order[$field], 2) . "€";
                            if ($field === 'total') { echo "<td>$field_value</td>"; continue; }
                        } else $field_value = htmlspecialchars($order[$field]);
                        $function = "updateOrder({$order['id']}, \"$field\", this.innerText)";
                        echo "<td contenteditable='true' onBlur='$function'>$field_value</td>";
                    }
                    echo "<td>";
                    if ($order['status'] !== 'sent' && $order['status'] !== 'delivered')
                        echo "<a href='?send_order_id={$order['id']}' onclick='return confirm(\"Mark order as sent?\")'>Mark as Sent</a> | ";
                    echo "<a href='?delete_order_id={$order['id']}' onclick='return confirm(\"Are you sure?\")'>Delete</a></td></tr></tbody>";
                }
                ?>
            </table>
        </section>
    </main>
</body>
<script>
// Expose a client-side map of all items (id => name) for rendering
const ALL_ITEMS = <?php echo json_encode($all_items_map); ?>;

function updateItem(id, field, value) {
    if (field === 'visible') value = value ? 1 : 0;
    value = value.toString().trim();
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Update-Type': 'item'
        }, body: JSON.stringify({ id: id, field: field, value: value })
    }).then(response => response.json()).then(data => {
          if (data.success) {
              if (field === 'stock' || field === 'preorders_left') {
                  const itemRow = document.querySelector("tr[data-item-id='" + id + "']");
                  if (!itemRow) return;
                  const stockCell = itemRow.querySelector('td:nth-child(6)');
                  const preorderCell = itemRow.querySelector('td:nth-child(7)');
                  if (typeof data.stock !== 'undefined') stockCell.innerText = data.stock;
                  if (typeof data.preorders_left !== 'undefined') preorderCell.innerText = data.preorders_left;
              } console.log('Item updated successfully');
          }
          else alert('Error updating item: ' + data.error);
    });
}

function updateOrder(id, field, value) {
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Update-Type': 'order'
        }, body: JSON.stringify({ id: id, field: field, value: value })
    }).then(response => response.json()).then(data => {
        if (!data.success) { alert('Error updating order: ' + data.error); return; }
        if (typeof data.total === 'undefined') { console.log('Order updated successfully'); return; }
        const orderItems = document.getElementById('order-items-' + id);
        if (!orderItems) return;
        const subtotalCell = orderItems.closest('td').nextElementSibling;
        if (typeof data.subtotal !== 'undefined') subtotalCell.innerText = parseFloat(data.subtotal).toFixed(2) + '€';
        const shippingCell = subtotalCell ? subtotalCell.nextElementSibling : null;
        if (typeof data.shipping !== 'undefined') shippingCell.innerText = parseFloat(data.shipping).toFixed(2) + '€';
        const totalCell = shippingCell ? shippingCell.nextElementSibling : null;
        totalCell.innerText = parseFloat(data.total).toFixed(2) + '€';
    });
}

function renderOrderItems(orderId, items) {
    const container = document.getElementById('order-items-' + orderId);
    let html = '';
    for (const itemId in items) {
        html += "<div class='order-item' data-item-id='"+itemId+"'>";
        html += "<span class='item-name'>" + escapeHtml(ALL_ITEMS[itemId] ? ALL_ITEMS[itemId] : 'Unknown Item') + "</span>";
        html += "<input type='number' min='0' value='"+items[itemId]+"' onchange='updateOrderItem("+orderId+","+itemId+", this.value)'>";
        html += "<button onclick='removeOrderItem("+orderId+","+itemId+"); return false;'>X</button></div>";
    }
    // Controls to add new items
    html += "<div class='add-order-item'><select id='add-item-select-"+orderId+"'>";
    for (const id in ALL_ITEMS) html += "<option value='"+id+"'>"+escapeHtml(ALL_ITEMS[id])+"</option>";
    html += "</select><input id='add-item-qty-"+orderId+"' type='number' min='1' value='1'>";
    html += "<button onclick='addOrderItem("+orderId+"); return false;'>+</button></div>";
    container.innerHTML = html;
}

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function (s) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]);
    });
}

function updateOrderItem(orderId, itemId, qty) {
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Update-Type': 'order'
        }, body: JSON.stringify({
            id: orderId, field: 'items', value: JSON.stringify({
                action: 'update', item_id: itemId, qty: parseInt(qty,10)
            })
        })
    }).then(r => r.json()).then(data => {
        if (data.success) {
            renderOrderItems(orderId, data.items);
            // update subtotal cell
            const subtotalCell = document.querySelector('#order-items-' + orderId).closest('td').nextElementSibling;
            const totalCell = subtotalCell.nextElementSibling.nextElementSibling;
            subtotalCell.innerText = data.subtotal.toFixed(2) + '€';
            totalCell.innerText = data.total.toFixed(2) + '€';

            // Update stock display if needed
            const itemRow = document.querySelector("tr[data-item-id='" + itemId + "']");
            if (!itemRow) return;
            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Update-Type': 'item'
                }, body: JSON.stringify({ id: itemId, field: 'stock', value: 'refresh' })
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    const stockCell = itemRow.querySelector('td:nth-child(6)');
                    const preorderCell = itemRow.querySelector('td:nth-child(7)');
                    stockCell.innerText = data.stock;
                    preorderCell.innerText = data.preorders_left;
                } else alert('Error: ' + data.error);
            });
        } else alert('Error: ' + data.error);
    });
}

function removeOrderItem(orderId, itemId) {
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Update-Type': 'order'
        }, body: JSON.stringify({
            id: orderId, field: 'items', value: JSON.stringify({
                action: 'remove', item_id: itemId
            })
        })
    }).then(r => r.json()).then(data => {
        if (data.success) {
            renderOrderItems(orderId, data.items);
            const subtotalCell = document.querySelector('#order-items-' + orderId).closest('td').nextElementSibling;
            const totalCell = subtotalCell.nextElementSibling.nextElementSibling;
            subtotalCell.innerText = data.subtotal.toFixed(2) + '€';
            totalCell.innerText = data.total.toFixed(2) + '€';

            // Update stock display if needed
            const itemRow = document.querySelector("tr[data-item-id='" + itemId + "']");
            if (!itemRow) return;
            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Update-Type': 'item'
                }, body: JSON.stringify({ id: itemId, field: 'stock', value: 'refresh' })
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    const stockCell = itemRow.querySelector('td:nth-child(6)');
                    const preorderCell = itemRow.querySelector('td:nth-child(7)');
                    stockCell.innerText = data.stock;
                    preorderCell.innerText = data.preorders_left;
                } else alert('Error: ' + data.error);
            });
        } else alert('Error: ' + data.error);
    });
}

function addOrderItem(orderId) {
    const sel = document.getElementById('add-item-select-' + orderId);
    const qtyInput = document.getElementById('add-item-qty-' + orderId);
    const itemId = parseInt(sel.value, 10);
    const qty = parseInt(qtyInput.value, 10);
    if (!itemId || qty <= 0) { alert('Invalid item or quantity'); return; }
    fetch('admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Update-Type': 'order'
        }, body: JSON.stringify({
            id: orderId, field: 'items', value: JSON.stringify({
                action: 'add', item_id: itemId, qty: qty
            })
        })
    }).then(r => r.json()).then(data => {
        if (data.success) {
            renderOrderItems(orderId, data.items);
            const subtotalCell = document.querySelector('#order-items-' + orderId).closest('td').nextElementSibling;
            const totalCell = subtotalCell.nextElementSibling.nextElementSibling;
            subtotalCell.innerText = data.subtotal.toFixed(2) + '€';
            totalCell.innerText = data.total.toFixed(2) + '€';

            // Update stock display if needed
            const itemRow = document.querySelector("tr[data-item-id='" + itemId + "']");
            if (!itemRow) return;
            fetch('admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Update-Type': 'item'
                }, body: JSON.stringify({ id: itemId, field: 'stock', value: 'refresh' })
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    const stockCell = itemRow.querySelector('td:nth-child(6)');
                    const preorderCell = itemRow.querySelector('td:nth-child(7)');
                    stockCell.innerText = data.stock;
                    preorderCell.innerText = data.preorders_left;
                } else alert('Error: ' + data.error);
            });
        } else alert('Error: ' + data.error);
    });
}
</script>
</html>
