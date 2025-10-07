<?php
if (!session_id()) session_start();
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    header("Location: index.php");
    exit();
}
$db = mysqli_connect("localhost", "root", "", "transforshop");
if (!$db) die("Connection failed: " . mysqli_connect_error());
$total_price = 0.0;
$item_ids = array_keys($_SESSION['cart']);
if (!empty($item_ids)) {
    $ids_string = implode(',', array_map('intval', $item_ids));
    $cart_query = mysqli_query($db, "SELECT * FROM items WHERE id IN ($ids_string);");
    while ($row = mysqli_fetch_array($cart_query)) {
        $item_id = $row['id'];
        $quantity = $_SESSION['cart'][$item_id];
        $total_price += $row['price'] * $quantity;
    } $total_price = number_format($total_price, 2, '.', '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'], $_POST['address'], $_POST['email'], $_POST['phone'])) {
    $name = mysqli_real_escape_string($db, $_POST['name']);
    $address = mysqli_real_escape_string($db, $_POST['address']);
    $email = mysqli_real_escape_string($db, $_POST['email']);
    $phone = mysqli_real_escape_string($db, $_POST['phone']);
    $notes = isset($_POST['notes']) ? mysqli_real_escape_string($db, $_POST['notes']) : '';
    $order_items = [];
    foreach ($_SESSION['cart'] as $order_item) {
        // Query the name
        $item_query = mysqli_query($db, "SELECT name FROM items WHERE id=$order_item;");
        $item_name = "Unknown Item";
        if ($item_row = mysqli_fetch_array($item_query)) $item_name = $item_row['name'];
        $quantity = intval($order_item['quantity']);
        $order_items[$item_name] = $quantity;
    } $order_items = json_encode($order_items);
    $insert_query = "INSERT INTO orders (name, address, email, phone, notes, items, subtotal) 
                     VALUES ('$name', '$address', '$email', '$phone', '$notes', '$order_items', '$total_price');";
    mysqli_query($db, $insert_query);
    $_SESSION['cart'] = [];
    header("Location: index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TransforMate Official Shop | Checkout</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<div id="navbar">
    <h1>TransforMate Official Shop | Checkout</h1>
</div>
<table id="checkout-table">
    <tr>
        <th>Item</th>
        <th>Quantity</th>
        <th>Price</th>
    </tr>
    <?php
    $item_ids = array_keys($_SESSION['cart']);
    $ids_string = implode(',', array_map('intval', $item_ids));
    $cart_query = mysqli_query($db, "SELECT * FROM items WHERE id IN ($ids_string);");
    while ($row = mysqli_fetch_array($cart_query)) {
        $item_id = $row['id'];
        $quantity = $_SESSION['cart'][$item_id];
        $item_total = $row['price'] * $quantity;
        echo "<tr>
              <td>{$row['name']}</td>
              <td>$quantity</td>
              <td>" . number_format($item_total, 2) . "€</td>
              </tr>";
    }
    echo "<tr>
          <td colspan='2'><strong>Total</strong></td>
          <td><strong>" . number_format($total_price, 2) . "€</strong></td>
          </tr>";
    ?>
</table>
<form id="checkout-form" method="POST">
    <input type="text" name="name" placeholder="Full Name" required><br>
    <input type="text" name="address" placeholder="Shipping Address" required><br>
    <input type="email" name="email" placeholder="Email Address" required><br>
    <input type="text" name="phone" placeholder="Phone Number" required><br>
    <input type="text" name="notes" placeholder="Additional Notes (optional)"><br>
    <button type="submit">Place Order</button>
    <button type="button" onclick="window.location.href='index.php'">Continue Shopping</button>
</form>
</body>
</html>
