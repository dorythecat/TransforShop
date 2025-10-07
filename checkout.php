<?php
if (!session_id()) session_start();
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    header("Location: index.php");
    exit();
}
$db = mysqli_connect("localhost", "root", "", "transforshop");
$total_price = 0.0;
if (!empty($_SESSION['cart'])) {
    $item_ids = array_keys($_SESSION['cart']);
    $ids_string = implode(',', array_map('intval', $item_ids));
    $cart_query = mysqli_query($db, "SELECT * FROM items WHERE id IN ($ids_string);");
    while ($row = mysqli_fetch_array($cart_query)) {
        $item_id = $row['id'];
        $quantity = $_SESSION['cart'][$item_id];
        $total_price += $row['price'] * $quantity;
    }
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
</body>
</html>
